<?php

namespace App\Services;

use App\Models\ResellerPayment;
use App\Models\ResellerOrder;
use Illuminate\Support\Facades\Crypt;

class ResellerPaymentService
{
    private $payment;
    private $config;
    private $class;
    private $driver;

    public function __construct(ResellerPayment $payment, bool $allowInactive = false)
    {
        $this->driver = $payment->driver;
        $allowed = (array)config('v2board.reseller_allowed_payment_drivers', []);
        if (!PaymentAttemptService::isDriverAvailable($payment->driver)) {
            abort(422, 'Payment driver is not allowlisted');
        }
        if (!$allowInactive && (!$payment->enabled || !in_array($payment->driver, $allowed, true))) {
            abort(422, 'Payment driver is not allowed');
        }

        $this->class = '\\App\\Payments\\' . $payment->driver;
        if (!class_exists($this->class)) {
            abort(422, 'Payment driver is not installed');
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payment->config_encrypted), true);
        } catch (\Throwable $e) {
            abort(500, 'Payment configuration is unavailable');
        }
        if (!is_array($decoded)) {
            abort(500, 'Payment configuration is invalid');
        }

        $this->config = $this->normalizeConfig($decoded);
        $this->payment = new $this->class($this->config);
    }

    public static function drivers(): array
    {
        $drivers = [];
        foreach (glob(base_path('app/Payments') . '/*.php') as $file) {
            $driver = pathinfo($file, PATHINFO_FILENAME);
            if (PaymentAttemptService::isDriverAvailable($driver)) {
                $drivers[] = $driver;
            }
        }
        sort($drivers);
        return $drivers;
    }

    public static function form(string $driver): array
    {
        if (!in_array($driver, self::drivers(), true)) {
            abort(422, 'Payment driver is not installed');
        }
        $class = '\\App\\Payments\\' . $driver;
        return (new $class([]))->form();
    }

    public static function isSensitiveConfigField(string $field): bool
    {
        return (bool)preg_match('/(?:key|secret|token|password|private|webhook)/i', $field);
    }

    public function pay(ResellerOrder $order, string $storeSlug, ?string $stripeToken = null): array
    {
        $platformOrder = $order->platformOrder;
        if (!$platformOrder || empty($platformOrder->trade_no)) {
            abort(500, 'Payment order is unavailable');
        }

        $notifyUrl = url("/api/v1/store/{$storeSlug}/payment/notify/{$order->payment->uuid}");
        $returnUrl = url("/store/{$storeSlug}#/order/{$platformOrder->trade_no}");

        $checkout = [
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'trade_no' => $platformOrder->trade_no,
            'display_trade_no' => $platformOrder->trade_no,
            'total_amount' => $platformOrder->total_amount,
            'user_id' => $platformOrder->user_id,
            'stripe_token' => $stripeToken,
        ];
        $quote = $this->quote($checkout);
        $checkout['gateway_amount_minor'] = $quote['amount_minor'];
        $checkout['gateway_currency'] = $quote['currency'];
        return $this->payment->pay($checkout);
    }

    public function notify(array $params)
    {
        $result = $this->payment->notify($params);
        if (is_array($result)) {
            $amount = $this->callbackAmount($params);
            if ($amount !== null) {
                $result['payment_amount_cents'] = $amount;
            }
        }
        return $result;
    }

    private function callbackAmount(array $params): ?int
    {
        if ($this->driver === 'WechatPayNative' && isset($params['total_fee'])) {
            return (int)$params['total_fee'];
        }
        if ($this->driver === 'MGate' && isset($params['total_amount'])) {
            return (int)$params['total_amount'];
        }
        if ($this->driver === 'AlipayF2F' && isset($params['total_amount'])) {
            return (int)round((float)$params['total_amount'] * 100);
        }
        if ($this->driver === 'EPay' && isset($params['money'])) {
            return (int)round((float)$params['money'] * 100);
        }
        if (in_array($this->driver, ['Bepusdt', 'Epusdt'], true) && isset($params['amount'])) {
            return (int)round((float)$params['amount'] * 100);
        }
        return null;
    }

    private function quote(array $order): array
    {
        if (method_exists($this->payment, 'prepare')) {
            $quote = $this->payment->prepare($order);
        } else {
            $currency = 'CNY';
            if ($this->driver === 'Epusdt') {
                $currency = strtoupper(trim((string)($this->config['epusdt_currency'] ?? '')) ?: 'CNY');
            } elseif ($this->driver === 'Bepusdt') {
                $currency = strtoupper(trim((string)($this->config['bepusdt_fiat'] ?? '')) ?: 'CNY');
            }
            if ($currency !== 'CNY') {
                abort(422, 'This payment driver cannot securely quote a non-CNY payment');
            }
            $quote = ['amount_minor' => (int)$order['total_amount'], 'currency' => $currency];
        }

        $amount = $quote['amount_minor'] ?? null;
        $currency = strtoupper(trim((string)($quote['currency'] ?? '')));
        if (!is_int($amount) || $amount < 1 || !preg_match('/^[A-Z0-9]{3,12}$/', $currency)) {
            abort(500, 'Payment driver did not provide a verifiable amount and currency');
        }
        return ['amount_minor' => $amount, 'currency' => $currency];
    }

    private function normalizeConfig(array $config): array
    {
        if ($this->driver !== 'EPay') return $config;

        foreach (['url', 'pid', 'key', 'type'] as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                $config[$key] = trim($config[$key]);
            }
        }
        if (!empty($config['url'])) {
            $config['url'] = rtrim($config['url'], '/');
        }
        return $config;
    }
}
