<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\ResellerSharedSubscriptionService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::findOrFail($request->user['id']);
        $service = new SubscriptionService();
        if (!$service->available()) return response(['data' => []]);
        $sharedService = new ResellerSharedSubscriptionService();
        if ($sharedService->suspendedGroupForUser($user)) {
            abort(403, 'Shared subscription is suspended');
        }
        $service->ensurePrimary($user);
        $sharedGroup = $sharedService->groupForUser($user);
        $sharedPayload = $sharedGroup ? $sharedService->payload($sharedGroup, $user) : null;
        $subscriptions = $service->forUser($user)->map(function (Subscription $subscription) use ($sharedGroup, $sharedPayload) {
            $status = $subscription->status;
            if ($status === 'active' && $subscription->expired_at && $subscription->expired_at < time()) {
                $status = 'expired';
            }
            $data = [
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
            if ($sharedGroup && (int)$sharedGroup->subscription_id === (int)$subscription->id) {
                $data['shared_subscription'] = $sharedPayload;
                $data['traffic_log_available'] = false;
            }
            return $data;
        })->values();
        if ($sharedGroup && !$subscriptions->contains(function ($item) use ($sharedGroup) {
            return (int)$item['id'] === (int)$sharedGroup->subscription_id;
        })) {
            $shared = $sharedPayload;
            $subscriptions->prepend([
                'id' => (int)$shared['subscription_id'],
                'plan_id' => optional($sharedGroup->subscription)->plan_id,
                'plan_name' => $shared['plan_name'],
                'status' => $shared['status'],
                'transfer_enable' => $shared['transfer_enable'],
                'u' => $shared['u'],
                'd' => $shared['d'],
                'expired_at' => $shared['expired_at'],
                'device_limit' => $shared['device_limit'],
                'group_id' => optional($sharedGroup->subscription)->group_id,
                'subscribe_url' => $shared['subscribe_url'],
                'is_primary' => true,
                'auto_renewal' => false,
                'shared_subscription' => $shared,
                'traffic_log_available' => false,
            ]);
        }
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
        $sharedGroup = (new ResellerSharedSubscriptionService())->groupForUser($user);
        if ($sharedGroup && (int)$sharedGroup->subscription_id === (int)$subscription->id) {
            abort(422, 'Shared subscriptions cannot be revoked by a member');
        }
        return response(['data' => (new SubscriptionService())->revoke($user, $subscription)]);
    }
}
