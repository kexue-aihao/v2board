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

    public function generate(ResellerAccount $account, Request $request): array
    {
        $session = Helper::guid();
        $token = JWT::encode([
            'reseller_id' => $account->id,
            'session' => $session,
            'scope' => 'reseller',
        ], config('app.key'), 'HS256');

        $sessions = (array)Cache::get($this->sessionKey($account->id), []);
        $sessions[$session] = [
            'ip' => $request->ip(),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $token,
        ];
        Cache::put($this->sessionKey($account->id), $sessions);

        return [
            'auth_data' => $token,
            'reseller' => $this->safeAccount($account),
        ];
    }

    public function resolve(string $token): ?array
    {
        try {
            $payload = (array)JWT::decode($token, new Key(config('app.key'), 'HS256'));
            if (($payload['scope'] ?? null) !== 'reseller' || empty($payload['reseller_id'])) {
                return null;
            }
            $sessions = (array)Cache::get($this->sessionKey((int)$payload['reseller_id']), []);
            if (!isset($sessions[$payload['session']])) {
                return null;
            }

            $account = ResellerAccount::find((int)$payload['reseller_id']);
            if (!$account || $account->status !== 'active') {
                return null;
            }

            return $this->safeAccount($account);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function forget(string $token): void
    {
        $data = $this->resolve($token);
        Cache::forget($token);
        if (!$data || empty($data['id'])) {
            return;
        }
        $sessions = (array)Cache::get($this->sessionKey((int)$data['id']), []);
        foreach ($sessions as $id => $session) {
            if (($session['auth_data'] ?? null) === $token) {
                unset($sessions[$id]);
            }
        }
        Cache::put($this->sessionKey((int)$data['id']), $sessions);
    }

    public function safeAccount(ResellerAccount $account): array
    {
        return [
            'id' => (int)$account->id,
            'email' => $account->email,
            'store_slug' => $account->store_slug,
            'store_name' => $account->store_name,
            'status' => $account->status,
        ];
    }

    private function sessionKey(int $id): string
    {
        return self::SESSION_KEY . '_' . $id;
    }
}
