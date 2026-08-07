<?php

namespace Tests\Unit;

use App\Payments\EPay;
use App\Services\PaymentAttemptService;
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
        $this->assertFalse(PaymentAttemptService::isDriverAvailable('MGate'));

        config(['v2board.payment_secure_driver_allowlist' => ['BTCPay', 'Coinbase', 'MGate']]);
        $this->assertTrue(PaymentAttemptService::isDriverAvailable('BTCPay'));
        $this->assertTrue(PaymentAttemptService::isDriverAvailable('Coinbase'));
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
