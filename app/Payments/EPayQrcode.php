<?php

namespace App\Payments;

use Curl\Curl;

class EPayQrcode
{
    private $config;

    public function __construct($config)
    {
        $this->config = is_array($config) ? $config : [];
    }

    public function form()
    {
        return [
            'url' => [
                'label' => 'URL',
                'description' => 'EPay payment site root URL, for example https://pay.example.com',
                'type' => 'input',
            ],
            'pid' => [
                'label' => 'PID',
                'description' => 'Merchant ID',
                'type' => 'input',
            ],
            'key' => [
                'label' => 'KEY',
                'description' => 'Merchant signing key',
                'type' => 'input',
            ],
            'type' => [
                'label' => 'TYPE',
                'description' => 'Required payment method, for example alipay or wxpay',
                'type' => 'input',
            ],
        ];
    }

    public function pay($order)
    {
        $this->validateConfig();
        if (($order['gateway_currency'] ?? null) !== 'CNY') {
            abort(500, 'EPay QR only supports CNY payments');
        }

        $params = [
            'pid' => (string)$this->config['pid'],
            'type' => (string)$this->config['type'],
            'out_trade_no' => (string)$order['trade_no'],
            'notify_url' => (string)$order['notify_url'],
            'return_url' => (string)$order['return_url'],
            'name' => (string)($order['display_trade_no'] ?? $order['trade_no']),
            'money' => number_format(((int)$order['gateway_amount_minor']) / 100, 2, '.', ''),
            'clientip' => $this->clientIp(),
            'device' => $this->deviceType(),
        ];
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'MD5';

        $curl = new Curl();
        $curl->setUserAgent('V2Board-EPay-MAPI/1.0');
        $curl->setHeader('Accept', 'application/json');
        $curl->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $curl->setOpt(CURLOPT_CONNECTTIMEOUT, 10);
        $curl->setOpt(CURLOPT_TIMEOUT, 30);
        $curl->post($this->mapiUrl(), http_build_query($params));

        $response = is_string($curl->response) ? json_decode($curl->response, true) : $curl->response;
        $hasError = $curl->error;
        $curl->close();
        if ($hasError || !is_array($response) || (int)($response['code'] ?? 0) !== 1) {
            abort(500, (string)($response['msg'] ?? 'EPay MAPI order request failed'));
        }

        $paymentData = '';
        foreach (['qrcode', 'urlscheme', 'payurl'] as $field) {
            if (!empty($response[$field]) && is_string($response[$field])) {
                $paymentData = $response[$field];
                break;
            }
        }
        if ($paymentData === '') {
            abort(500, 'EPay MAPI response does not contain payment data');
        }

        return [
            'type' => $this->isMobile() ? 1 : 0,
            'data' => $paymentData,
        ];
    }

    public function notify($params)
    {
        if (!is_array($params) || empty($params['sign']) || !$this->hasConfig(['pid', 'key'])) {
            return false;
        }
        if (!hash_equals($this->sign($params), strtolower(trim((string)$params['sign'])))) {
            return false;
        }
        if (!hash_equals((string)$this->config['pid'], (string)($params['pid'] ?? ''))) {
            return false;
        }

        $status = strtoupper(trim((string)($params['trade_status'] ?? '')));
        if (!in_array($status, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)
            || empty($params['out_trade_no']) || empty($params['trade_no'])) {
            return false;
        }
        $amount = $this->amountToMinor($params['money'] ?? null);
        if ($amount === null) {
            return false;
        }

        return [
            'trade_no' => (string)$params['out_trade_no'],
            'callback_no' => (string)$params['trade_no'],
            'paid_amount_minor' => $amount,
            'currency' => 'CNY',
        ];
    }

    private function sign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $parts[] = $key . '=' . (string)$value;
        }
        return md5(implode('&', $parts) . (string)($this->config['key'] ?? ''));
    }

    private function validateConfig(): void
    {
        if (!$this->hasConfig(['url', 'pid', 'key', 'type'])) {
            abort(500, 'EPay QR configuration is incomplete');
        }
        $url = $this->mapiUrl();
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'], $parts['pass'])) {
            abort(500, 'EPay QR URL must be a valid HTTPS URL');
        }
    }

    private function hasConfig(array $keys): bool
    {
        foreach ($keys as $key) {
            if (!isset($this->config[$key]) || trim((string)$this->config[$key]) === '') {
                return false;
            }
        }
        return true;
    }

    private function mapiUrl(): string
    {
        $baseUrl = trim((string)($this->config['url'] ?? ''));
        $baseUrl = preg_replace('#/(?:submit|mapi)\.php(?:\?.*)?$#i', '', $baseUrl);
        return rtrim($baseUrl, '/') . '/mapi.php';
    }

    private function amountToMinor($value): ?int
    {
        $amount = trim((string)$value);
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
            return null;
        }
        $parts = explode('.', $amount, 2);
        $fraction = isset($parts[1]) ? str_pad($parts[1], 2, '0') : '00';
        return ((int)$parts[0] * 100) + (int)$fraction;
    }

    private function clientIp(): string
    {
        $ip = function_exists('request') ? request()->ip() : null;
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }

    private function deviceType(): string
    {
        $userAgent = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (strpos($userAgent, 'micromessenger') !== false) {
            return 'wechat';
        }
        if (strpos($userAgent, 'alipayclient') !== false) {
            return 'alipay';
        }
        return $this->isMobile() ? 'mobile' : 'pc';
    }

    private function isMobile(): bool
    {
        return preg_match('/mobile|android|iphone|ipad/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) === 1;
    }
}
