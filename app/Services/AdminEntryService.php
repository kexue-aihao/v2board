<?php

namespace App\Services;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/** A page-only session hint; business APIs still require Authorization. */
class AdminEntryService
{
    public static function cookieName(): string
    {
        return 'v2board_admin_entry_' . substr(hash('sha256', (string)config('app.key')), 0, 12);
    }

    public static function token(Request $request): string
    {
        return (string)$request->cookie(self::cookieName(), '');
    }

    public static function security(array $actor): array
    {
        $role = $actor['admin_role'];
        return [
            'role' => $role, 'role_label' => config('admin_security.roles')[$role],
            'version' => $actor['admin_version'], 'user_id' => $actor['id'],
            'menus' => AdminAccessService::menus($role), 'landing' => AdminAccessService::landing($role),
            'debug_exempt' => app()->environment(['local', 'testing']) || (bool)config('admin_security.debug_exempt'),
        ];
    }

    public static function page(Request $request): ?array
    {
        $token = self::token($request);
        $actor = $token !== '' ? AuthService::decryptAuthData($token) : false;
        if (!$actor || !AdminAccessService::role($actor)) return null;
        $payload = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        return SecurityAuditService::run($request, $actor, function () use ($actor, $payload) {
            return ['security' => self::security($actor), 'session' => $payload['session']];
        });
    }

    public static function remember($response, Request $request, string $token)
    {
        $response->headers->setCookie(new Cookie(self::cookieName(), $token, time() + 2592000, '/', null,
            $request->isSecure(), true, false, Cookie::SAMESITE_STRICT));
        return $response;
    }

    public static function loginResponse($response, Request $request)
    {
        $data = json_decode($response->getContent(), true)['data'] ?? [];
        if (!empty($data['is_admin']) && !empty($data['auth_data'])) return self::remember($response, $request, $data['auth_data']);
        return $response;
    }

    public static function forget($response, Request $request)
    {
        $response->headers->setCookie(new Cookie(self::cookieName(), '', 1, '/', null,
            $request->isSecure(), true, false, Cookie::SAMESITE_STRICT));
        return $response;
    }
}
