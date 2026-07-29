<?php

namespace App\Http\Middleware;

use App\Models\ResellerAccount;
use Closure;

class Storefront
{
    public function handle($request, Closure $next)
    {
        if (!(int)config('v2board.reseller_enable', 0)) {
            abort(503, 'Reseller service is disabled');
        }
        $slug = (string)$request->route('slug');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{2,31}$/', $slug)) {
            abort(404, 'Store does not exist');
        }

        $store = ResellerAccount::where('store_slug', $slug)
            ->first();
        if (!$store || !$store->isFullyActive()) {
            abort(404, 'Store does not exist');
        }

        $request->merge(['store' => $store]);
        return $next($request);
    }
}
