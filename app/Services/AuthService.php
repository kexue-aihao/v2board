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
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    private static function realIp(Request $request)
    {
        $candidates = [];
    
        // 优先 Cloudflare
        $cf = $request->headers->get('CF-Connecting-IP');
        if ($cf) $candidates[] = $cf;
    
        // 然后 X-Forwarded-For（取链路中的第一个）
        $xff = $request->headers->get('X-Forwarded-For');
        if ($xff) {
            foreach (explode(',', $xff) as $part) {
                $part = trim($part);
                if ($part !== '') $candidates[] = $part;
            }
        }
    
        // 再尝试 X-Real-IP
        $xri = $request->headers->get('X-Real-IP');
        if ($xri) $candidates[] = $xri;
    
        // 兜底
        $remote = $request->server->get('REMOTE_ADDR');
        if ($remote) $candidates[] = $remote;
    
        foreach ($candidates as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return $request->ip();
    }

    public function generateAuthData(Request $request)
    {
        $guid = Helper::guid();
        $authData = JWT::encode([
            'id' => $this->user->id,
            'session' => $guid,
        ], config('app.key'), 'HS256');
        self::addSession($this->user->id, $guid, [
            'ip' => $this->realIp($request),
            'login_at' => time(),
            'ua' => $request->userAgent(),
            'auth_data' => $authData
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
            if (!Cache::has($jwt)) {
                $data = (array)JWT::decode($jwt, new Key(config('app.key'), 'HS256'));
                if (!self::checkSession($data['id'], $data['session'])) return false;
                $user = User::select([
                    'id',
                    'email',
                    'is_admin',
                    'is_staff'
                ])
                    ->find($data['id']);
                if (!$user) return false;
                Cache::put($jwt, $user->toArray(), 3600);
            }
            return Cache::get($jwt);
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function checkSession($userId, $session)
    {
        $sessions = (array)Cache::get(CacheKey::get("USER_SESSIONS", $userId)) ?? [];
        if (!in_array($session, array_keys($sessions))) return false;
        return true;
    }

    private static function addSession($userId, $guid, $meta)
    {
        $cacheKey = CacheKey::get("USER_SESSIONS", $userId);
        $sessions = (array)Cache::get($cacheKey, []);
        $sessions[$guid] = $meta;
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
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
        if (!Cache::put(
            $cacheKey,
            $sessions
        )) return false;
        return true;
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
}
