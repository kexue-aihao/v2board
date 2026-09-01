<?php

namespace App\Payments;

use App\Models\PaymentAttempt;

class PaytaroQR
{
    private const API = 'https://v3.paytaro.com';

    private $config;

    public function __construct($config)
    {
        $this->config = is_array($config) ? $config : [];
    }

    public function form()
    {
        return [
            'pid' => [
                'label' => 'App ID',
                'description' => 'Paytaro application App ID',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'App Secret',
                'description' => 'Paytaro application App Secret',
                'type' => 'input',
            ],
            'method_uuid' => [
                'label' => 'Payment Method UUID',
                'description' => 'One payment configuration is required for each Paytaro payment method',
                'type' => 'input',
            ],
            'alert' => [
                'type' => 'alert',
                'content' => 'Paytaro QR must be explicitly enabled in the secure payment driver allowlist after sandbox verification.',
            ],
        ];
    }

    public function pay($order)
    {
        $this->validateConfig();
        if (($order['gateway_currency'] ?? null) !== 'CNY') {
            abort(500, 'Paytaro QR only supports CNY payments');
        }

        $response = $this->request('/v1/invoice/pay', [
            'merchant_no' => (string)$order['trade_no'],
            'order_amount' => number_format(((int)$order['gateway_amount_minor']) / 100, 2, '.', ''),
            'notify_url' => (string)$order['notify_url'],
            'return_url' => (string)$order['return_url'],
            'method_uuid' => trim((string)$this->config['method_uuid']),
        ]);
        $invoiceUuid = trim((string)($response['uuid'] ?? ''));
        $payment = isset($response['payment']) && is_array($response['payment']) ? $response['payment'] : null;
        if (!$this->isUuid($invoiceUuid) || !$payment || empty($payment['data'])) {
            abort(500, 'Paytaro invoice response is incomplete');
        }

        $linkType = strtolower((string)($payment['link_type'] ?? ''));
        $isAlipay = $linkType === 'h5'
            || $linkType === 'pc'
            || strtolower((string)($payment['type'] ?? '')) === 'alipay';

        if ($isAlipay) {
            if ($this->isMobile()) {
                $data = !empty($payment['mobile_url']) ? $payment['mobile_url'] : $payment['data'];
                return ['type' => 1, 'data' => $data, 'provider_reference' => $invoiceUuid];
            }
            if ($linkType === 'pc') {
                return ['type' => 1, 'data' => $payment['data'], 'provider_reference' => $invoiceUuid];
            }
            return ['type' => 0, 'data' => $payment['data'], 'provider_reference' => $invoiceUuid];
        }

        $pageBase = rtrim((string)($order['paytaro_qr_page_base'] ?? ''), '/');
        if ($pageBase === '') {
            abort(500, 'Paytaro QR payment page URL is unavailable');
        }
        return [
            'type' => 1,
            'data' => $pageBase . '/api/v1/guest/payment/paytaro-qr/'
                . rawurlencode((string)$order['trade_no']) . '/' . rawurlencode($invoiceUuid),
            'provider_reference' => $invoiceUuid,
        ];
    }

    public function notify($params)
    {
        if (!is_array($params) || !hash_equals($this->appSecret(), $this->callbackSecret())) {
            return false;
        }
        if (!in_array(strtoupper(trim((string)($params['status'] ?? ''))), ['PAID', 'SUCCESS'], true)) {
            return false;
        }

        $tradeNo = trim((string)($params['merchant_no'] ?? ''));
        $callbackNo = $this->firstValue($params, ['callback_no', 'transaction_no', 'payment_no']);
        if (!preg_match('/^[A-Za-z0-9]{32}$/', $tradeNo) || $callbackNo === '') {
            return false;
        }

        $attempt = PaymentAttempt::where('attempt_no', $tradeNo)
            ->where('payment_id', (int)($this->config['id'] ?? 0))
            ->where('driver', 'PaytaroQR')
            // A paid callback for a cancelled order must still reach the
            // shared payment state machine so it emits the reconciliation alert.
            ->whereIn('status', [PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_INVALIDATED])
            ->first();
        if (!$attempt || !$this->isUuid((string)$attempt->provider_reference)) {
            return false;
        }

        $invoice = self::fetchInvoice((string)$attempt->provider_reference);
        $details = $invoice ? self::invoiceDetails($attempt, $invoice) : null;
        if ($details === null || !in_array(strtoupper(trim((string)($invoice['status'] ?? ''))), ['PAID', 'SUCCESS'], true)) {
            return false;
        }

        return [
            'trade_no' => $tradeNo,
            'callback_no' => $callbackNo,
            'paid_amount_minor' => $details['amount_minor'],
            'currency' => $details['currency'],
            'custom_result' => 'success',
        ];
    }

    public static function fetchInvoice(string $invoiceUuid): ?array
    {
        if (!self::isValidUuid($invoiceUuid)) {
            return null;
        }
        $ch = curl_init(self::API . '/v1/invoice/' . rawurlencode($invoiceUuid));
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: V2Board-PaytaroQR/1.0',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }
        $invoice = json_decode($body, true);
        return is_array($invoice) ? $invoice : null;
    }

    public static function invoiceDetails(PaymentAttempt $attempt, array $invoice): ?array
    {
        if (!self::isValidUuid((string)($invoice['uuid'] ?? ''))
            || !hash_equals((string)$attempt->provider_reference, (string)$invoice['uuid'])
            || !hash_equals((string)$attempt->attempt_no, (string)($invoice['merchant_no'] ?? ''))) {
            return null;
        }
        $amount = self::amountToMinor($invoice['order_amount'] ?? null);
        $currency = strtoupper(trim((string)($invoice['order_currency'] ?? '')));
        if ($amount === null
            || $currency === ''
            || $amount !== (int)$attempt->gateway_amount_minor
            || !hash_equals((string)$attempt->gateway_currency, $currency)) {
            return null;
        }
        return ['amount_minor' => $amount, 'currency' => $currency];
    }

    private function request(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            abort(500, 'Paytaro request cannot be encoded');
        }
        $ch = curl_init(self::API . $path);
        if ($ch === false) {
            abort(500, 'Paytaro request cannot be initialized');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-App-Secret: ' . $this->appSecret(),
                'User-Agent: V2Board-PaytaroQR/' . $this->appId(),
            ],
        ]);
        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $response = is_string($responseBody) ? json_decode($responseBody, true) : null;
        if ($responseBody === false || $error !== '' || !is_array($response)) {
            abort(500, 'Paytaro gateway is unavailable');
        }
        if ($status < 200 || $status >= 300) {
            abort(500, 'Paytaro: ' . (string)($response['error'] ?? $response['message'] ?? ('HTTP ' . $status)));
        }
        return $response;
    }

    private function validateConfig(): void
    {
        if ($this->appId() === '' || $this->appSecret() === '' || !self::isValidUuid(trim((string)($this->config['method_uuid'] ?? '')))) {
            abort(500, 'Paytaro QR configuration is incomplete or invalid');
        }
    }

    private function appId(): string
    {
        return trim((string)($this->config['pid'] ?? ''));
    }

    private function appSecret(): string
    {
        return trim((string)($this->config['key'] ?? ''));
    }

    private function callbackSecret(): string
    {
        if (function_exists('request')) {
            $header = request()->header('X-App-Secret');
            if (is_string($header)) {
                return $header;
            }
        }
        return (string)($_SERVER['HTTP_X_APP_SECRET'] ?? '');
    }

    private function firstValue(array $params, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($params[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function isUuid(string $value): bool
    {
        return self::isValidUuid($value);
    }

    private static function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function amountToMinor($value): ?int
    {
        $amount = trim((string)$value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            return null;
        }
        $parts = explode('.', $amount, 2);
        $fraction = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';
        return ((int)$parts[0] * 100) + (int)$fraction;
    }

    private function isMobile(): bool
    {
        return preg_match('/Android|iPhone|iPad|iPod|Mobile|HarmonyOS/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) === 1;
    }
}
