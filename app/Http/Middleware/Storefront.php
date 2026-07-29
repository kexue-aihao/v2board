<?php

namespace App\Http\Middleware;

use App\Models\ResellerAccount;
use Closure;

class Storefront
{
    public function handle($request, Closure $next)
    {
        $slug = (string)$request->route('slug');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{2,31}$/', $slug)) {
            abort(404, 'Store does not exist');
        }

        // Providers must be able to settle already-created orders after a store is disabled.
        $isPaymentNotify = $request->route('payment_uuid') !== null;
        if (!(int)config('v2board.reseller_enable', 0) && !$isPaymentNotify) {
            abort(503, 'Reseller service is disabled');
        }

        $store = ResellerAccount::where('store_slug', $slug)
            ->first();
        if (!$store || (!$isPaymentNotify && !$store->isFullyActive())) {
            abort(404, 'Store does not exist');
        }

        $request->merge(['store' => $store]);
        return $next($request);
    }
}
