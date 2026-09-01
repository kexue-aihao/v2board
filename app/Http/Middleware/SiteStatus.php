<?php

namespace App\Http\Middleware;

use Closure;

class SiteStatus
{
    private const MODES = ['normal', 'maintenance', 'shutdown'];

    public function handle($request, Closure $next)
    {
        $mode = (string)config('v2board.site_status', 'normal');
        if (!in_array($mode, self::MODES, true) || $mode === 'normal' || $this->isExempt($request)) {
            return $next($request);
        }

        $code = $mode === 'shutdown' ? 'SITE_SHUTDOWN' : 'SITE_MAINTENANCE';
        // Language 中间件在 api 与 web 两个组里都有（组中间件先于路由中间件），
        // 本路由中间件到达时 locale 已按当前请求就位，可安全走 __()。
        $message = $mode === 'shutdown'
            ? __('The site is currently out of service, please try again later')
            : __('The site is currently under maintenance, please try again later');

        return response()->json([
            'message' => $message,
            'code' => $code,
            'data' => [
                'site_status' => $mode
            ]
        ], 503)->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    private function isExempt($request)
    {
        $path = trim((string)$request->path(), '/');

        if ($path === 'api/v1/guest/comm/config') {
            return true;
        }

        // Payment providers and Telegram need to finish already-created callbacks.
        if ($path === 'api/v1/guest/telegram/webhook'
            || preg_match('#^api/v1/guest/payment/notify/[^/]+/[^/]+$#', $path)
            || preg_match('#^api/v1/guest/payment/paytaro-qr/[A-Za-z0-9]{32}/[0-9a-f-]{36}(?:/status)?$#i', $path)
            || preg_match('#^api/v1/store/[^/]+/payment/notify/[^/]+$#', $path)) {
            return true;
        }

        // Node communication and the administrator API remain available for operations.
        if (strpos($path, 'api/v1/server/') === 0 || strpos($path, 'api/v2/server/') === 0) {
            return true;
        }

        $securePath = trim((string)config(
            'v2board.secure_path',
            config('v2board.frontend_admin_path', hash('crc32b', (string)config('app.key')))
        ), '/');
        if ($securePath !== '' && preg_match('#^api/v1/' . preg_quote($securePath, '#') . '(/|$)#', $path)) {
            return true;
        }

        return false;
    }
}
