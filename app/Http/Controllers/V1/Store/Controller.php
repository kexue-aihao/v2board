<?php

namespace App\Http\Controllers\V1\Store;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Controllers\V1\Passport\AuthController as PassportAuthController;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Models\Order;
use App\Models\ResellerAccount;
use App\Models\ResellerCustomer;
use App\Models\ResellerOrder;
use App\Models\ResellerPayment;
use App\Models\ResellerPlan;
use App\Models\ResellerPlanTemplate;
use App\Models\User;
use App\Services\AuthService;
use App\Services\ResellerOrderService;
use App\Services\ResellerSharedSubscriptionService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class Controller extends BaseController
{
    public function config(Request $request)
    {
        $store = $this->store($request);
        return response(['data' => [
            'store_slug' => $store->store_slug,
            'store_name' => $store->store_name,
            'store_description' => $store->store_description,
            'app_name' => config('v2board.app_name', 'V2Board'),
            'logo' => config('v2board.logo'),
        ]]);
    }

    public function plans(Request $request)
    {
        $plans = ResellerPlan::with('basePlan')
            ->where('reseller_id', $this->store($request)->id)
            ->where('enabled', 1)
            ->orderBy('sort')->orderBy('id')->get();
        $data = $plans->filter(function (ResellerPlan $plan) {
            return $this->templateEnabled($plan);
        })->map(function (ResellerPlan $plan) {
            $base = $plan->basePlan;
            return [
                'id' => (int)$plan->id,
                'name' => $plan->name,
                'content' => $plan->content,
                'transfer_enable' => $base ? $base->transfer_enable : null,
                'device_limit' => $base ? $base->device_limit : null,
                'speed_limit' => $base ? $base->speed_limit : null,
                'month_price' => $plan->month_price,
                'quarter_price' => $plan->quarter_price,
                'half_year_price' => $plan->half_year_price,
                'year_price' => $plan->year_price,
                'two_year_price' => $plan->two_year_price,
                'three_year_price' => $plan->three_year_price,
                'onetime_price' => $plan->onetime_price,
                'shared_member_limit' => max(1, (int)$plan->shared_member_limit),
            ];
        })->values();
        return response(['data' => $data]);
    }

    public function payments(Request $request)
    {
        $payments = ResellerPayment::where('reseller_id', $this->store($request)->id)
            ->whereIn('driver', (array)config('v2board.reseller_allowed_payment_drivers', []))
            ->where('enabled', 1)->orderBy('sort')->orderBy('id')->get();
        return response(['data' => $payments->map(function (ResellerPayment $payment) {
            return ['id' => (int)$payment->id, 'name' => $payment->name, 'driver' => $payment->driver];
        })->values()]);
    }

    public function register(Request $request)
    {
        // Store registrations use the store-specific policy: email is the account identifier,
        // but the platform-wide email verification switch does not apply here.
        $response = (new PassportAuthController())->register($this->passportRequest($request, AuthRegister::class), true);
        $this->linkAuthenticatedUser($request, $response);
        return $response;
    }

    public function login(Request $request)
    {
        $response = (new PassportAuthController())->login($this->passportRequest($request, AuthLogin::class));
        $this->linkAuthenticatedUser($request, $response);
        return $response;
    }

    public function verify2fa(Request $request)
    {
        $response = (new PassportAuthController())->verify2fa($request);
        $this->linkAuthenticatedUser($request, $response);
        return $response;
    }

    public function saveOrder(Request $request)
    {
        $data = $request->validate([
            'plan_id' => 'required|integer',
            'period' => 'required|string',
            'subscription_id' => 'nullable|integer',
        ]);
        $user = User::findOrFail($request->user['id']);
        $store = $this->store($request);
        $plan = ResellerPlan::where('id', $data['plan_id'])->where('reseller_id', $store->id)->first();
        if (!$plan) abort(404, 'Reseller plan does not exist');
        $mapping = (new ResellerOrderService())->create(
            $user,
            $store,
            $plan,
            $data['period'],
            isset($data['subscription_id']) ? (int)$data['subscription_id'] : null
        );
        return response(['data' => $mapping->platformOrder->trade_no]);
    }

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'trade_no' => 'required|string',
            'method' => 'required|integer',
            'token' => 'nullable|string',
        ]);
        $result = (new ResellerOrderService())->checkout(
            $this->store($request),
            User::findOrFail($request->user['id']),
            $data['trade_no'],
            (int)$data['method'],
            $data['token'] ?? null
        );
        return response($result);
    }

    public function checkOrder(Request $request)
    {
        $mapping = $this->ownedMapping($request);
        return response(['data' => (int)$mapping->platformOrder->status]);
    }

    public function detailOrder(Request $request)
    {
        $mapping = $this->ownedMapping($request);
        $order = $mapping->platformOrder->load('subscription');
        return response(['data' => [
            'trade_no' => $order->trade_no,
            'status' => (int)$order->status,
            'total_amount' => (int)$mapping->amount_snapshot,
            'period' => $mapping->period,
            'plan' => $this->planData($mapping->plan),
        ]]);
    }

    public function fetchOrders(Request $request)
    {
        $orders = ResellerOrder::with(['platformOrder', 'plan'])
            ->where('reseller_id', $this->store($request)->id)
            ->where('user_id', $request->user['id'])
            ->orderByDesc('id')->paginate(50);
        $orders->getCollection()->transform(function (ResellerOrder $mapping) {
            return [
                'trade_no' => optional($mapping->platformOrder)->trade_no,
                'status' => optional($mapping->platformOrder)->status,
                'total_amount' => (int)$mapping->amount_snapshot,
                'period' => $mapping->period,
                'plan' => $this->planData($mapping->plan),
                'created_at' => $mapping->created_at,
            ];
        });
        return response(['data' => $orders]);
    }

    public function cancelOrder(Request $request)
    {
        $mapping = $this->ownedMapping($request);
        if ((int)$mapping->platformOrder->status !== 0) abort(422, 'You can only cancel pending orders');
        if (!(new \App\Services\OrderService($mapping->platformOrder))->cancel()) {
            abort(500, 'Cancel failed');
        }
        return response(['data' => true]);
    }

    public function subscription(Request $request)
    {
        $store = $this->store($request);
        $user = User::findOrFail($request->user['id']);
        $sharedService = new ResellerSharedSubscriptionService();
        $sharedGroup = $sharedService->groupForUser($user, $store);
        if ($sharedGroup) {
            return response(['data' => $sharedService->payload($sharedGroup, $user)])
                ->header('Cache-Control', 'no-store, private');
        }

        $mappings = ResellerOrder::with(['platformOrder.subscription.plan', 'plan'])
            ->where('reseller_id', $store->id)
            ->where('user_id', $user->id)
            ->whereHas('platformOrder', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('status', 3)
                    ->whereNotNull('subscription_id');
            })
            ->orderByDesc('id')
            ->get();

        foreach ($mappings as $mapping) {
            $order = $mapping->platformOrder;
            $subscription = $order ? $order->subscription : null;
            if (!$subscription
                || (int)$subscription->user_id !== (int)$user->id
                || $subscription->status === 'revoked') {
                continue;
            }

            $total = max(0, (int)$subscription->transfer_enable);
            $used = max(0, (int)$subscription->u + (int)$subscription->d);
            $available = $subscription->status === 'active'
                && (!$subscription->expired_at || (int)$subscription->expired_at >= time());

            return response(['data' => [
                'subscription_id' => (int)$subscription->id,
                'plan_name' => optional($mapping->plan)->name ?: optional($subscription->plan)->name,
                'status' => $subscription->status,
                'expired_at' => $subscription->expired_at,
                'subscribe_url' => $available ? Helper::getSubscribeUrl($subscription->token, $subscription) : null,
                'total' => $total,
                'used' => $used,
                'remaining' => max(0, $total - $used),
                'usage_percent' => $total > 0 ? min(100, (int)floor($used * 100 / $total)) : 0,
                'shared_subscription' => false,
            ]])->header('Cache-Control', 'no-store, private');
        }

        return response(['data' => null])->header('Cache-Control', 'no-store, private');
    }

    public function sharedSubscription(Request $request)
    {
        $user = User::findOrFail($request->user['id']);
        $group = (new ResellerSharedSubscriptionService())->groupForUser($user, $this->store($request));
        return response(['data' => $group ? (new ResellerSharedSubscriptionService())->payload($group, $user) : null])
            ->header('Cache-Control', 'no-store, private');
    }

    public function sharedMembers(Request $request)
    {
        return response(['data' => (new ResellerSharedSubscriptionService())->members(
            $this->store($request), User::findOrFail($request->user['id'])
        )]);
    }

    public function createSharedInvitation(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:128']);
        return response(['data' => (new ResellerSharedSubscriptionService())->createInvitation(
            $this->store($request), User::findOrFail($request->user['id']), $data['email']
        )]);
    }

    public function sharedInvitations(Request $request)
    {
        return response(['data' => (new ResellerSharedSubscriptionService())->invitations(
            $this->store($request), User::findOrFail($request->user['id'])
        )]);
    }

    public function revokeSharedInvitation(Request $request, $slug, $id)
    {
        (new ResellerSharedSubscriptionService())->revokeInvitation(
            $this->store($request), User::findOrFail($request->user['id']), (int)$id
        );
        return response(['data' => true]);
    }

    public function acceptSharedInvitation(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|size:64']);
        $store = $this->store($request);
        $user = User::findOrFail($request->user['id']);
        if (!ResellerCustomer::where('reseller_id', $store->id)->where('user_id', $user->id)->exists()) {
            abort(403, 'Sign in through this store before accepting an invitation');
        }
        $group = (new ResellerSharedSubscriptionService())->acceptInvitation($store, $user, $data['token']);
        return response(['data' => (new ResellerSharedSubscriptionService())->payload($group, $user)]);
    }

    public function removeSharedMember(Request $request, $slug, $id)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        (new ResellerSharedSubscriptionService())->removeMember(
            $this->store($request), User::findOrFail($request->user['id']), (int)$id, $data['reason'] ?? null
        );
        return response(['data' => true]);
    }

    public function rotateSharedCredential(Request $request)
    {
        $store = $this->store($request);
        $user = User::findOrFail($request->user['id']);
        $group = (new ResellerSharedSubscriptionService())->rotateOwnerCredential($store, $user);
        return response(['data' => (new ResellerSharedSubscriptionService())->payload($group, $user)]);
    }

    public function notify(Request $request, $slug, $payment_uuid)
    {
        return (new ResellerOrderService())->notify($this->store($request), $payment_uuid, $request->input());
    }

    private function ownedMapping(Request $request): ResellerOrder
    {
        $tradeNo = (string)$request->input('trade_no');
        $mapping = ResellerOrder::with(['platformOrder', 'plan'])
            ->where('reseller_id', $this->store($request)->id)
            ->where('user_id', $request->user['id'])
            ->whereHas('platformOrder', function ($query) use ($tradeNo) {
                $query->where('trade_no', $tradeNo);
            })->first();
        if (!$mapping || !$mapping->platformOrder) abort(404, 'Order does not exist');
        return $mapping;
    }

    private function store(Request $request): ResellerAccount
    {
        return $request->store instanceof ResellerAccount
            ? $request->store
            : ResellerAccount::where('store_slug', $request->route('slug'))->where('status', 'active')->firstOrFail();
    }

    private function templateEnabled(ResellerPlan $plan): bool
    {
        return ResellerPlanTemplate::where('base_plan_id', $plan->base_plan_id)->where('enabled', 1)->exists()
            && (bool)$plan->basePlan;
    }

    private function planData(?ResellerPlan $plan): ?array
    {
        if (!$plan) return null;
        return [
            'id' => (int)$plan->id,
            'name' => $plan->name,
            'content' => $plan->content,
            'month_price' => $plan->month_price,
            'quarter_price' => $plan->quarter_price,
            'half_year_price' => $plan->half_year_price,
            'year_price' => $plan->year_price,
            'two_year_price' => $plan->two_year_price,
            'three_year_price' => $plan->three_year_price,
            'onetime_price' => $plan->onetime_price,
            'shared_member_limit' => max(1, (int)$plan->shared_member_limit),
        ];
    }

    private function linkAuthenticatedUser(Request $request, $response): void
    {
        $payload = json_decode($response->getContent(), true);
        $authData = $payload['data']['auth_data'] ?? null;
        if (!$authData) return;
        $user = AuthService::decryptAuthData($authData);
        if ($user && !empty($user['id'])) {
            ResellerCustomer::firstOrCreate(
                ['reseller_id' => $this->store($request)->id, 'user_id' => $user['id']],
                ['created_at' => time(), 'updated_at' => time()]
            );
        }
    }

    private function passportRequest(Request $request, string $requestClass): Request
    {
        /** @var \Illuminate\Foundation\Http\FormRequest $passportRequest */
        $passportRequest = $requestClass::createFrom($request);
        $passportRequest->setContainer(app())->setRedirector(app('redirect'));
        $passportRequest->validateResolved();
        return $passportRequest;
    }
}
