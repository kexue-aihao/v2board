<?php

namespace App\Http\Middleware;

use App\Services\ResellerAuthService;
use Closure;

class Reseller
{
    public function handle($request, Closure $next)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) {
            abort(403, 'Reseller authentication required');
        }

        $user = (new ResellerAuthService())->resolve($authorization);
        if (!$user) {
            abort(403, 'Reseller authentication expired');
        }

        $request->merge(['reseller' => $user]);
        return $next($request);
    }
}
