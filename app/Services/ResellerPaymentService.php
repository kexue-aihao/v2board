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
            $drivers[] = pathinfo($file, PATHINFO_FILENAME);
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

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
            'trade_no' => $platformOrder->trade_no,
            'total_amount' => $platformOrder->total_amount,
            'user_id' => $platformOrder->user_id,
            'stripe_token' => $stripeToken,
        ]);
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
