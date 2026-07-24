<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class Client
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
        $token = $request->input('token');
        if (empty($token)) {
            abort(403, 'token is null');
        }
        $submethod = (int)config('v2board.show_subscribe_method', 0);
        switch ($submethod) {
            case 0:
                break;
            case 1:
                if (!Cache::has("otpn_{$token}")) {
                    abort(403, 'token is error');
                }
                $usertoken = Cache::pull("otpn_{$token}");
                Cache::forget("otp_{$usertoken}");
                $token = $usertoken;
                break;
            case 2:
                $usertoken = Cache::get("totp_{$token}");
                if (!$usertoken) {
                    $timestep = (int)config('v2board.show_subscribe_expire', 5) * 60;
                    $counter = floor(time() / $timestep);
                    $counterBytes = pack('N*', 0) . pack('N*', $counter);
                    $idhash = Helper::base64DecodeUrlSafe($token);
                    if (strpos($idhash, ':') === false) {
                        abort(403, 'token is error');
                    }
                    $parts = explode(':', $idhash, 2);
                    [$userid, $clienthash] = $parts;
                    if (!$userid || !$clienthash) {
                        abort(403, 'token is error');
                    }
                    $user = User::where('id', $userid)->first();
                    if (!$user) {
                        abort(403, 'token is error');
                    }
                    $usertoken = $user->token;
                    $matchedSubscription = Schema::hasTable('v2_subscription') ? Subscription::where('user_id', $userid)->get()->first(function ($subscription) use ($counterBytes, $clienthash) {
                        return hash_equals($clienthash, hash_hmac('sha1', $counterBytes, $subscription->token, false));
                    }) : null;
                    if ($matchedSubscription) {
                        $usertoken = $matchedSubscription->token;
                    } elseif (!hash_equals($clienthash, hash_hmac('sha1', $counterBytes, $usertoken, false))) {
                        abort(403, 'token is error');
                    }
                    Cache::put("totp_{$token}", $usertoken, $timestep);
                }
                $token = $usertoken;
                break;
            default:
                break;
        }
        $subscriptionService = new SubscriptionService();
        $subscription = $subscriptionService->byToken($token);
        $subscriptionRecord = Schema::hasTable('v2_subscription') ? Subscription::where('token', $token)->first() : null;
        if (!$subscription && $subscriptionRecord) {
            abort(403, 'subscription is expired or revoked');
        }
        $user = $subscription ? User::find($subscription->user_id) : User::where('token', $token)->first();
        if (!$user) {
            abort(403, 'token is error');
        }
        if ($subscription) {
            $user = $subscriptionService->context($user, $subscription);
        }
        $request->merge([
            'user' => $user,
            'subscription' => $subscription
        ]);
        return $next($request);
    }
}
