<?php

namespace Tests\Unit;

use App\Payments\EPay;
use App\Payments\EPayQrcode;
use App\Models\PaymentAttempt;
use App\Services\PaymentAttemptService;
use App\Services\PaymentReturnUrlService;
use Illuminate\Http\Request;
use Tests\TestCase;

class PaymentSecurityTest extends TestCase
{
    public function testMgateIsQuarantined(): void
    {
        $this->assertTrue(PaymentAttemptService::isQuarantinedDriver('MGate'));
        $this->assertFalse(PaymentAttemptService::isQuarantinedDriver('EPay'));
    }

    public function testHighRiskDriversRequireAnExplicitAllowlist(): void
    {
        config(['v2board.payment_secure_driver_allowlist' => []]);
        $this->assertFalse(PaymentAttemptService::isDriverAvailable('BTCPay'));
        $this->assertFalse(PaymentAttemptService::isDriverAvailable('Coinbase'));
        $this->assertFalse(PaymentAttemptService::isDriverAvailable('PaytaroQR'));
        $this->assertFalse(PaymentAttemptService::isDriverAvailable('MGate'));

        config(['v2board.payment_secure_driver_allowlist' => ['BTCPay', 'Coinbase', 'PaytaroQR', 'MGate']]);
        $this->assertTrue(PaymentAttemptService::isDriverAvailable('BTCPay'));
        $this->assertTrue(PaymentAttemptService::isDriverAvailable('Coinbase'));
        $this->assertTrue(PaymentAttemptService::isDriverAvailable('PaytaroQR'));
        $this->assertFalse(PaymentAttemptService::isDriverAvailable('MGate'));
    }

    public function testEpayRequiresMerchantBindingAndReturnsExactAmount(): void
    {
        $driver = new EPay(['pid' => 'merchant-1', 'key' => 'secret']);
        $params = [
            'pid' => 'merchant-1',
            'out_trade_no' => 'attempt-123',
            'trade_no' => 'gateway-456',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '12.34',
            'type' => 'alipay',
        ];
        $params['sign'] = $this->epaySignature($params, 'secret');

        $result = $driver->notify($params);

        $this->assertSame('attempt-123', $result['trade_no']);
        $this->assertSame('gateway-456', $result['callback_no']);
        $this->assertSame(1234, $result['paid_amount_minor']);
        $this->assertSame('CNY', $result['currency']);

        $params['pid'] = 'another-merchant';
        $params['sign'] = $this->epaySignature($params, 'secret');
        $this->assertFalse($driver->notify($params));
    }

    public function testEpusdtRequiresMerchantBindingAndExactCurrency(): void
    {
        $driver = new \App\Payments\Epusdt([
            'epusdt_pid' => 'merchant-1',
            'epusdt_token' => 'secret',
            'epusdt_currency' => 'CNY',
        ]);
        $params = [
            'pid' => 'merchant-1',
            'order_id' => 'attempt-123',
            'trade_id' => 'gateway-456',
            'status' => 2,
            'amount' => '12.34',
            'currency' => 'CNY',
        ];
        $params['signature'] = $this->epusdtSignature($params, 'secret');

        $result = $driver->notify($params);
        $this->assertSame('attempt-123', $result['trade_no']);
        $this->assertSame('gateway-456', $result['callback_no']);
        $this->assertSame(1234, $result['paid_amount_minor']);
        $this->assertSame('CNY', $result['currency']);

        $params['pid'] = 'another-merchant';
        $params['signature'] = $this->epusdtSignature($params, 'secret');
        $this->assertSame('failed', $driver->notify($params));
    }

    public function testEpayQrcodeRequiresMerchantBindingAndReturnsExactAmount(): void
    {
        $driver = new EPayQrcode(['pid' => 'merchant-1', 'key' => 'secret']);
        $params = [
            'pid' => 'merchant-1',
            'out_trade_no' => 'attempt-123',
            'trade_no' => 'gateway-456',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '12.34',
            'type' => 'alipay',
        ];
        $params['sign'] = $this->epaySignature($params, 'secret');

        $result = $driver->notify($params);

        $this->assertSame('attempt-123', $result['trade_no']);
        $this->assertSame('gateway-456', $result['callback_no']);
        $this->assertSame(1234, $result['paid_amount_minor']);
        $this->assertSame('CNY', $result['currency']);
    }

    public function testPaymentReturnUrlUsesOnlyAllowlistedOrigin(): void
    {
        config([
            'v2board.app_url' => 'https://panel.example.test',
            'v2board.payment_return_url_allowlist' => ['https://app.example.test'],
        ]);
        $service = new PaymentReturnUrlService();
        $trusted = Request::create('/api/v1/user/order/checkout', 'POST');
        $trusted->headers->set('Origin', 'https://app.example.test');
        $untrusted = Request::create('/api/v1/user/order/checkout', 'POST');
        $untrusted->headers->set('Origin', 'https://attacker.example.test');
        $untrusted->headers->set('X-Forwarded-Host', 'attacker.example.test');

        $this->assertSame('https://app.example.test/#/order/display-123', $service->forOrder('display-123', $trusted));
        $this->assertSame('https://panel.example.test/#/order/display-123', $service->forOrder('display-123', $untrusted));
    }

    public function testPaytaroInvoiceDetailsMustMatchTheBoundAttempt(): void
    {
        $attempt = new PaymentAttempt();
        $attempt->setRawAttributes([
            'attempt_no' => 'abcdefghijklmnopqrstuvwx12345678',
            'provider_reference' => '2b5b1a11-1a11-4a11-8a11-111111111111',
            'gateway_amount_minor' => 128,
            'gateway_currency' => 'CNY',
        ], true);
        $invoice = [
            'uuid' => '2b5b1a11-1a11-4a11-8a11-111111111111',
            'merchant_no' => 'abcdefghijklmnopqrstuvwx12345678',
            'order_amount' => '1.28',
            'order_currency' => 'CNY',
        ];

        $this->assertSame(
            ['amount_minor' => 128, 'currency' => 'CNY'],
            \App\Payments\PaytaroQR::invoiceDetails($attempt, $invoice)
        );

        $invoice['order_amount'] = '1.27';
        $this->assertNull(\App\Payments\PaytaroQR::invoiceDetails($attempt, $invoice));
    }

    private function epaySignature(array $params, string $key): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $parts = [];
        foreach ($params as $name => $value) {
            if ($value === '' || $value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $parts[] = $name . '=' . $value;
        }
        return md5(implode('&', $parts) . $key);
    }

    private function epusdtSignature(array $params, string $key): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $name => $value) {
            if ($value === '' || $value === null || $name === 'signature') {
                continue;
            }
            $parts[] = $name . '=' . $value;
        }
        return md5(implode('&', $parts) . $key);
    }
}
