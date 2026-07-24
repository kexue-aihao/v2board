<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::findOrFail($request->user['id']);
        $service = new SubscriptionService();
        if (!$service->available()) return response(['data' => []]);
        $service->ensurePrimary($user);
        $subscriptions = $service->forUser($user)->map(function (Subscription $subscription) {
            $status = $subscription->status;
            if ($status === 'active' && $subscription->expired_at && $subscription->expired_at < time()) {
                $status = 'expired';
            }
            return [
                'id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'plan_name' => optional($subscription->plan)->name,
                'status' => $status,
                'transfer_enable' => (int)$subscription->transfer_enable,
                'u' => (int)$subscription->u,
                'd' => (int)$subscription->d,
                'expired_at' => $subscription->expired_at,
                'device_limit' => $subscription->device_limit,
                'group_id' => $subscription->group_id,
                'subscribe_url' => Helper::getSubscribeUrl($subscription->token, $subscription),
                'is_primary' => (bool)$subscription->is_primary,
                'auto_renewal' => (bool)$subscription->auto_renewal
            ];
        })->values();
        return response(['data' => $subscriptions]);
    }

    public function setPrimary(Request $request)
    {
        $user = User::findOrFail($request->user['id']);
        $subscription = Subscription::where('id', $request->input('subscription_id'))
            ->where('user_id', $user->id)->first();
        if (!$subscription) abort(404, __('Subscription does not exist'));
        (new SubscriptionService())->setPrimary($user, $subscription);
        return response(['data' => true]);
    }

    public function revoke(Request $request)
    {
        $user = User::findOrFail($request->user['id']);
        $subscription = Subscription::where('id', $request->input('subscription_id'))
            ->where('user_id', $user->id)->first();
        if (!$subscription) abort(404, __('Subscription does not exist'));
        return response(['data' => (new SubscriptionService())->revoke($user, $subscription)]);
    }
}
