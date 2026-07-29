<?php

namespace App\Services;

use App\Models\ResellerAccount;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ResellerAuthService
{
    private const SESSION_KEY = 'RESELLER_SESSIONS';
    private const SESSION_TTL = 2592000;

    public function generate(ResellerAccount $account, Request $request): array
    {
        $session = Helper::guid();
        $token = JWT::encode([
            'reseller_id' => $account->id,
            'session' => $session,
            'scope' => 'reseller',
            'iat' => time(),
            'exp' => time() + self::SESSION_TTL,
        ], config('app.key'), 'HS256');

        $sessions = (array)Cache::get($this->sessionKey($account->id), []);
        $sessions[$session] = [
            'ip' => $request->ip(),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $token,
        ];
        Cache::put($this->sessionKey($account->id), $sessions, self::SESSION_TTL);

        return [
            'auth_data' => $token,
            'reseller' => $this->safeAccount($account),
        ];
    }

    public function resolve(string $token): ?array
    {
        try {
            $payload = $this->decode($this->normalizeToken($token));
            if (!$payload) {
                return null;
            }
            if (($payload['scope'] ?? null) !== 'reseller' || empty($payload['reseller_id'])) {
                return null;
            }
            $sessions = (array)Cache::get($this->sessionKey((int)$payload['reseller_id']), []);
            if (!isset($sessions[$payload['session']])) {
                return null;
            }

            $account = ResellerAccount::find((int)$payload['reseller_id']);
            if (!$account || !$account->isAccountActive()) {
                return null;
            }

            return $this->safeAccount($account);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function forget(string $token): void
    {
        $payload = $this->decode($this->normalizeToken($token));
        if (!$payload || empty($payload['reseller_id']) || empty($payload['session'])) {
            return;
        }
        $key = $this->sessionKey((int)$payload['reseller_id']);
        $sessions = (array)Cache::get($key, []);
        unset($sessions[$payload['session']]);
        Cache::put($key, $sessions, self::SESSION_TTL);
    }

    public function revokeAll(ResellerAccount $account): void
    {
        Cache::forget($this->sessionKey((int)$account->id));
    }

    public function safeAccount(ResellerAccount $account): array
    {
        return [
            'id' => (int)$account->id,
            'email' => $account->email,
            'store_slug' => $account->store_slug,
            'store_name' => $account->store_name,
            'status' => $account->status,
            'account_status' => $account->accountStatus(),
            'reseller_status' => $account->accountStatus(),
            'store_status' => $account->storeStatus(),
            'store_available' => $account->isFullyActive(),
            'can_sell' => $account->isFullyActive(),
            'reseller_review_reason' => $account->reseller_review_reason,
            'store_review_reason' => $account->store_review_reason,
        ];
    }

    private function normalizeToken(string $token): string
    {
        $token = trim($token);
        if (stripos($token, 'bearer ') === 0) {
            return trim(substr($token, 7));
        }
        return $token;
    }

    private function decode(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        try {
            return (array)JWT::decode($token, new Key(config('app.key'), 'HS256'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function sessionKey(int $id): string
    {
        return self::SESSION_KEY . '_' . $id;
    }
}
