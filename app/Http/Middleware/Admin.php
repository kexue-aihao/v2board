<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use App\Services\AdminAccessService;
use App\Services\SecurityAuditService;
use Closure;

class Admin
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        // Only the read-only script endpoint accepts the page cookie. Cookie
        // authentication must not silently authorize any business API or write.
        if (!$authorization && $request->isMethod('GET')
            && $request->route()->getActionName() === 'App\\Http\\Controllers\\V1\\Admin\\SecurityController@asset') {
            $authorization = \App\Services\AdminEntryService::token($request);
        }
        $user = $authorization ? AuthService::decryptAuthData($authorization) : false;
        if (!$user || !AdminAccessService::role($user)) {
            try {
                SecurityAuditService::append('authorization.denied', 'denied', ['action' => $request->route()->getActionName()], $user ?: null);
            } catch (\Throwable $error) {
                // An unavailable audit store must never turn denial into access.
                // Preserve the denial status and leave a local outage signal.
                \Illuminate\Support\Facades\Log::channel('daily')->error('Security audit unavailable while rejecting administrator access', [
                    'action' => $request->route()->getActionName(), 'ip' => $request->ip(),
                ]);
            }
            abort(403, '后台访问已停用、未分配角色或登录已过期');
        }
        $request->merge(['user' => $user]);
        return SecurityAuditService::run($request, $user, function () use ($request, $next, $user) {
            abort_unless(AdminAccessService::allows($user['admin_role'], $request->route()->getActionName()), 403, '当前角色无权执行此操作');
            AdminAccessService::protectRequest($request, $user);
            return $next($request);
        });
    }
}
