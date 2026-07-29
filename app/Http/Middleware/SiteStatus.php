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
        $message = $mode === 'shutdown'
            ? '站点当前已停止服务，请稍后再试。'
            : '站点当前正在维护，请稍后再试。';

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
