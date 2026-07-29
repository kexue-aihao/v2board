<?php

namespace App\Http\Middleware;

use App\Services\ResellerAuthService;
use Closure;

class Reseller
{
    public function handle($request, Closure $next)
    {
        if (!(int)config('v2board.reseller_enable', 0)) {
            abort(503, 'Reseller service is disabled');
        }

        $authorization = trim((string)$request->input('auth_data', ''));
        if ($authorization === '') {
            $authorization = trim((string)$request->header('authorization', ''));
        }
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
