<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;

class User
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
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) abort(403, '未登录或登陆已过期');
        $request->merge([
            'user' => $user
        ]);
        $action = $request->route()->getActionName();
        if (preg_match('/User\\\\(?:TwoFactorController@|UserController@(changePassword|resetPassword|resetSecurity|removeActiveSession|unbindTelegram)$|TelegramController@(?:prepareAccountBinding|revokeBinding)$)/', $action)) {
            $model = \App\Models\User::find($user['id']);
            if ($model && ($model->is_admin || $model->is_staff)) {
                return \App\Services\SecurityAuditService::run($request, \App\Services\AdminAccessService::actor($model), function () use ($request, $next) {
                    return $next($request);
                });
            }
        }
        return $next($request);
    }
}
