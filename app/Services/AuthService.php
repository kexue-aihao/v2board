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
        if ($this->user->is_admin || $this->user->is_staff) {
            $actor = AdminAccessService::actor($this->user);
            $context = $request->attributes->get('security_audit_context');
            if ($context) {
                $context['actor'] = $actor;
                $request->attributes->set('security_audit_context', $context);
            }
            SecurityAuditService::append('authentication.login', 'success', ['two_factor_verified' => (bool)$twoFactorVerified], $actor);
        }
        $now = time();
        $expiresAt = $now + self::SESSION_TTL;
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
            'iat' => $now,
            'exp' => $expiresAt,
            'admin_version' => (int)$this->user->admin_version,
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
            'is_admin' => AdminAccessService::role($this->user) !== null,
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

            // Authorization is never taken from the hour-long profile cache.
            // Reading the row also works during upgrades before the new nullable
            // role columns exist; missing roles fail closed for legacy admins.
            $user = User::find($data['id']);
            if (!$user || $user->banned || (int)($data['admin_version'] ?? 0) !== (int)$user->admin_version) return false;
            return AdminAccessService::actor($user);
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
        $removed = isset($sessions[$sessionId]);
        unset($sessions[$sessionId]);
        $result = self::putSessions($this->user->id, $sessions);
        SecurityAuditService::effect('撤销指定登录会话', ['用户编号' => $this->user->id, '撤销数量' => $removed ? 1 : 0], $result ? 'success' : 'failure', true);
        return $result;
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
        $result = Cache::forget($cacheKey);
        SecurityAuditService::effect('撤销账号全部登录会话', ['用户编号' => $this->user->id, '撤销数量' => count($sessions)], $result || !$sessions ? 'success' : 'failure', true);
        return $result;
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
