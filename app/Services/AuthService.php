<?php

namespace App\Services;

use App\Utils\CacheKey;
use App\Utils\Helper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class AuthService
{
    private const SESSION_TTL = 2592000;
    private const USER_CACHE_TTL = 3600;

    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function generateAuthData(Request $request, $twoFactorVerified = false)
    {
        if (!$twoFactorVerified && (new TwoFactorService())->isEnabled($this->user->id)) {
            abort(403, __('需要完成二步验证后才能建立登录会话'));
        }
        $guid = Helper::guid();
        $now = time();
        $expiresAt = $now + self::SESSION_TTL;
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
            'iat' => $now,
            'exp' => $expiresAt,
        ], config('app.key'), 'HS256');
        self::addSession($this->user->id, $guid, [
            'ip' => $request->ip(),
            'login_at' => $now,
            'ua' => $request->userAgent(),
            'auth_data' => $authData,
            'expires_at' => $expiresAt
        ]);
        return [
            'token' => $this->user->token,
            'is_admin' => $this->user->is_admin,
            'auth_data' => $authData
        ];
    }

    public static function decryptAuthData($jwt)
    {
        try {
            // Decode on every request: checking only a cached user allows an expired JWT to
            // remain usable until the cache entry expires. Tokens issued before expiry support
            // are intentionally rejected to close the existing unbounded-session window.
            $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
            if (empty($data['id']) || empty($data['session']) || empty($data['exp'])
                || (int)$data['exp'] <= time()
                || !self::checkSession($data['id'], $data['session'])) {
                return false;
            }

            $user = Cache::get($jwt);
            if (!$user) {
                $user = User::select([
                    'id',
                    'email',
                    'is_admin',
                    'is_staff'
                ])
                    ->find($data['id']);
                if (!$user) return false;
                $user = $user->toArray();
                Cache::put($jwt, $user, min(
                    self::USER_CACHE_TTL,
                    max(1, (int)$data['exp'] - time())
                ));
            }
            return $user;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey) ?? [];
        $meta = $sessions[$session] ?? null;
        if (!is_array($meta) || (int)($meta['expires_at'] ?? 0) <= time()) {
            if (isset($sessions[$session])) {
                unset($sessions[$session]);
                self::putSessions($userId, $sessions);
            }
            return false;
        }
        return true;
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        $sessions[$guid] = $meta;
        return self::putSessions($userId, $sessions);
    }

    public function getSessions()
    {
        return (array)Cache::get(CacheKey::get("USER_SESSIONS", $this->user->id), []);
    }

    public function removeSession($sessionId)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        unset($sessions[$sessionId]);
        return self::putSessions($this->user->id, $sessions);
    }

    public function removeAllSession()
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $this->user->id);
        $sessions = (array)Cache::get($cacheKey, []);
        foreach ($sessions as $guid => $meta) {
            if (isset($meta['auth_data'])) {
                Cache::forget($meta['auth_data']);
            }
        }
        return Cache::forget($cacheKey);
    }

    private static function putSessions($userId, array $sessions): bool
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        if (!count($sessions)) {
            return Cache::forget($cacheKey);
        }

        $latestExpiry = time();
        foreach ($sessions as $meta) {
            $latestExpiry = max($latestExpiry, (int)($meta['expires_at'] ?? 0));
        }
        return Cache::put($cacheKey, $sessions, max(1, $latestExpiry - time()));
    }
}
