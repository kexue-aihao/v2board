<?php

namespace App\Http\Middleware;

use App\Services\SecurityAuditService;
use Closure;

class AdminLoginAudit
{
    public function handle($request, Closure $next)
    {
        // Also cover privileged accounts attempting to log in through the user
        // portal, without collecting every ordinary subscriber's login data.
        if (strpos($request->route()->getActionName(), '@admin') === false) {
            $user = is_string($request->input('email')) ? \App\Models\User::where('email', $request->input('email'))->first() : null;
            if (!$user && ($request->input('challenge') || $request->input('setup_token'))) {
                $type = $request->input('setup_token') ? 'setup' : 'login';
                $challenge = (new \App\Services\TwoFactorService())->getChallenge($request->input('setup_token') ?: $request->input('challenge'), $type);
                if ($challenge) $user = \App\Models\User::find($challenge['user_id'] ?? 0);
            }
            if (!$user || !($user->is_admin || $user->is_staff)) return $next($request);
        }
        return SecurityAuditService::run($request, null, function () use ($request, $next) {
            return $next($request);
        });
    }
}
