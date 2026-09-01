<?php

namespace App\Services;

use Illuminate\Http\Request;

class PaymentReturnUrlService
{
    public function forOrder(string $displayTradeNo, ?Request $request = null): string
    {
        return $this->baseUrl($request) . '/#/order/' . rawurlencode($displayTradeNo);
    }

    public function baseUrl(?Request $request = null): string
    {
        $request = $request ?: request();
        $origin = $request ? $this->normalizeOrigin((string)$request->header('Origin', '')) : null;
        $allowlist = $this->allowlist();

        if ($origin !== null && in_array($origin, $allowlist, true)) {
            return $origin;
        }

        $configured = $this->normalizeOrigin((string)config('v2board.app_url', ''));
        if ($configured !== null) {
            return $configured;
        }

        $fallback = $this->normalizeOrigin(url('/'));
        if ($fallback === null) {
            throw new \RuntimeException('Payment return URL cannot be determined');
        }
        return $fallback;
    }

    public function normalizeAllowlist(array $origins): array
    {
        $normalized = [];
        foreach ($origins as $origin) {
            $value = $this->normalizeOrigin((string)$origin);
            if ($value === null || in_array($value, $normalized, true)) {
                continue;
            }
            $normalized[] = $value;
        }
        return $normalized;
    }

    public function normalizeOrigin(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $path = $parts['path'] ?? '';
        if ($path !== '' && $path !== '/') {
            return null;
        }

        $host = strtolower((string)$parts['host']);
        if ($host === '') {
            return null;
        }

        $origin = strtolower((string)$parts['scheme']) . '://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }
        return $origin;
    }

    private function allowlist(): array
    {
        return $this->normalizeAllowlist((array)config('v2board.payment_return_url_allowlist', []));
    }
}
