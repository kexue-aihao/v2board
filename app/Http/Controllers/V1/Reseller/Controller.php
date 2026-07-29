<?php

namespace App\Http\Controllers\V1\Reseller;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Plan;
use App\Models\ResellerAccount;
use App\Models\ResellerCustomer;
use App\Models\ResellerOrder;
use App\Models\ResellerPayment;
use App\Models\ResellerPlan;
use App\Models\ResellerPlanTemplate;
use App\Services\ResellerAuthService;
use App\Services\ResellerPaymentService;
use App\Services\ResellerOrderService;
use App\Services\ResellerSharedSubscriptionService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class Controller extends BaseController
{
    public function me(Request $request)
    {
        return response(['data' => $this->accountData($request)]);
    }

    public function templates()
    {
        $templates = ResellerPlanTemplate::with('plan')
            ->where('enabled', 1)->orderBy('sort')->orderBy('id')->get();
        return response(['data' => $templates->map(function ($template) {
            $plan = $template->plan;
            return [
                'id' => $template->base_plan_id,
                'name' => $plan ? $plan->name : null,
                'content' => $plan ? $plan->content : null,
                'transfer_enable' => $plan ? $plan->transfer_enable : null,
                'device_limit' => $plan ? $plan->device_limit : null,
                'speed_limit' => $plan ? $plan->speed_limit : null,
            ];
        })->filter(function ($item) { return $item['name'] !== null; })->values()]);
    }

    public function plans(Request $request)
    {
        $plans = ResellerPlan::where('reseller_id', $request->reseller['id'])
            ->with('basePlan')->orderBy('sort')->orderBy('id')->get();
        return response(['data' => $plans->map(function (ResellerPlan $plan) {
            return $this->planData($plan, true);
        })->values()]);
    }

    public function savePlan(Request $request)
    {
        $this->ensureStoreCanSell($request);
        $data = $request->validate([
            'id' => 'nullable|integer',
            'base_plan_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'content' => 'nullable|string|max:10000',
            'month_price' => 'nullable|integer|min:1',
            'quarter_price' => 'nullable|integer|min:1',
            'half_year_price' => 'nullable|integer|min:1',
            'year_price' => 'nullable|integer|min:1',
            'two_year_price' => 'nullable|integer|min:1',
            'three_year_price' => 'nullable|integer|min:1',
            'onetime_price' => 'nullable|integer|min:1',
            'shared_member_limit' => 'nullable|integer|min:1|max:100',
            'enabled' => 'nullable|boolean',
            'sort' => 'nullable|integer',
        ]);
        if (!ResellerPlanTemplate::where('base_plan_id', $data['base_plan_id'])->where('enabled', 1)->exists()) {
            abort(422, 'Base plan is not enabled for resale');
        }
        if (!Plan::find($data['base_plan_id'])) {
            abort(422, 'Base plan does not exist');
        }
        if (!collect(ResellerOrderService::PERIODS)->contains(function ($period) use ($data) {
            return isset($data[$period]) && (int)$data[$period] > 0;
        })) {
            abort(422, 'At least one positive price is required');
        }

        $accountId = (int)$request->reseller['id'];
        $plan = !empty($data['id'])
            ? ResellerPlan::where('id', $data['id'])->where('reseller_id', $accountId)->first()
            : new ResellerPlan();
        if (!$plan) abort(404, 'Reseller plan does not exist');
        if ($plan->exists && (int)$plan->base_plan_id !== (int)$data['base_plan_id']
            && ResellerOrder::where('reseller_plan_id', $plan->id)->exists()) {
            abort(422, 'A plan with existing orders cannot change its base plan');
        }
        $plan->reseller_id = $accountId;
        $data['shared_member_limit'] = max(1, (int)($data['shared_member_limit'] ?? $plan->shared_member_limit ?? 1));
        $plan->fill($data);
        $plan->save();
        return response(['data' => $this->planData($plan->fresh('basePlan'), true)]);
    }

    public function payments(Request $request)
    {
        $payments = ResellerPayment::where('reseller_id', $request->reseller['id'])
            ->orderBy('sort')->orderBy('id')->get();
        return response(['data' => $payments->map(function (ResellerPayment $payment) {
            return [
                'id' => $payment->id,
                'uuid' => $payment->uuid,
                'driver' => $payment->driver,
                'name' => $payment->name,
                'enabled' => (int)$payment->enabled,
            ];
        })->values()]);
    }

    public function paymentForm(Request $request)
    {
        $driver = (string)$request->input('driver');
        if (!in_array($driver, (array)config('v2board.reseller_allowed_payment_drivers', []), true)) {
            abort(422, 'Payment driver is not allowed');
        }
        return response(['data' => ResellerPaymentService::form($driver)]);
    }

    public function paymentEdit(Request $request, $id)
    {
        $payment = $this->resellerPayment($request, (int)$id);
        $config = $this->paymentConfig($payment);
        $fields = ResellerPaymentService::form($payment->driver);
        foreach ($fields as $key => &$field) {
            $value = $config[$key] ?? '';
            if (ResellerPaymentService::isSensitiveConfigField($key)) {
                $field['sensitive'] = true;
                $field['value'] = '';
                $field['placeholder'] = $value === '' || $value === null
                    ? '请输入配置值'
                    : '已保存，留空保持不变';
                continue;
            }
            $field['value'] = is_scalar($value) ? (string)$value : '';
        }
        unset($field);

        return response(['data' => [
            'id' => (int)$payment->id,
            'driver' => $payment->driver,
            'name' => $payment->name,
            'enabled' => (int)$payment->enabled,
            'sort' => (int)$payment->sort,
            'fields' => $fields,
        ]]);
    }

    public function savePayment(Request $request)
    {
        $this->ensureStoreCanSell($request);
        $data = $request->validate([
            'id' => 'nullable|integer',
            'driver' => 'required|string|max:64',
            'name' => 'required|string|max:255',
            'config' => 'required|array',
            'enabled' => 'nullable|boolean',
            'sort' => 'nullable|integer',
        ]);
        if (!in_array($data['driver'], (array)config('v2board.reseller_allowed_payment_drivers', []), true)) {
            abort(422, 'Payment driver is not allowed');
        }
        ResellerPaymentService::form($data['driver']);
        $accountId = (int)$request->reseller['id'];
        $payment = !empty($data['id'])
            ? ResellerPayment::where('id', $data['id'])->where('reseller_id', $accountId)->first()
            : new ResellerPayment();
        if (!$payment) abort(404, 'Payment method does not exist');
        if ($payment->exists && $payment->driver !== $data['driver']) {
            abort(422, 'Payment driver cannot be changed. Delete this configuration and create a new one.');
        }
        $data['config'] = $this->validatePaymentConfig(
            $data['driver'], $this->mergedPaymentConfig($payment, $data['config'])
        );
        $payment->reseller_id = $accountId;
        $payment->driver = $data['driver'];
        $payment->name = $data['name'];
        $payment->config_encrypted = Crypt::encryptString(json_encode($data['config'], JSON_UNESCAPED_UNICODE));
        $payment->enabled = (int)($data['enabled'] ?? 0);
        $payment->sort = (int)($data['sort'] ?? 0);
        $payment->uuid = $payment->uuid ?: Helper::randomChar(32);
        $payment->save();
        return response(['data' => ['id' => $payment->id, 'uuid' => $payment->uuid]]);
    }

    public function deletePayment(Request $request, $id)
    {
        $payment = $this->resellerPayment($request, (int)$id);
        $hasUnsettledOrder = ResellerOrder::where('reseller_id', $request->reseller['id'])
            ->where('reseller_payment_id', $payment->id)
            ->whereHas('platformOrder', function ($query) {
                $query->whereIn('status', [0, 1]);
            })->exists();
        if ($hasUnsettledOrder) {
            abort(422, 'This payment method has pending orders and cannot be deleted');
        }
        $payment->delete();
        return response(['data' => true]);
    }

    public function updateStore(Request $request)
    {
        $data = $request->validate([
            'store_slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{2,31}$/'],
            'store_name' => 'required|string|max:128',
            'store_description' => 'nullable|string|max:10000',
        ]);
        $account = ResellerAccount::findOrFail($request->reseller['id']);
        if (ResellerAccount::where('store_slug', $data['store_slug'])->where('id', '!=', $account->id)->exists()) {
            abort(422, 'Store slug already exists');
        }
        $account->fill($data);
        $account->save();
        return response(['data' => (new ResellerAuthService())->safeAccount($account)]);
    }

    public function customers(Request $request)
    {
        $customers = ResellerCustomer::with('user')
            ->where('reseller_id', $request->reseller['id'])
            ->orderByDesc('id')->paginate(50);
        return response(['data' => $customers]);
    }

    public function orders(Request $request)
    {
        $orders = ResellerOrder::with(['platformOrder', 'plan'])
            ->where('reseller_id', $request->reseller['id'])
            ->orderByDesc('id')->paginate(50);
        $orders->getCollection()->transform(function (ResellerOrder $item) {
            return [
                'trade_no' => optional($item->platformOrder)->trade_no,
                'user_id' => $item->user_id,
                'plan_name' => optional($item->plan)->name,
                'period' => $item->period,
                'amount' => $item->amount_snapshot,
                'status' => optional($item->platformOrder)->status,
                'created_at' => $item->created_at,
            ];
        });
        return response(['data' => $orders]);
    }

    public function sharedSubscriptions(Request $request)
    {
        $groups = (new ResellerSharedSubscriptionService())->auditGroups(
            ResellerAccount::findOrFail($request->reseller['id'])
        );
        $groups->getCollection()->transform(function ($group) {
            $subscription = $group->subscription;
            return [
                'id' => (int)$group->id,
                'status' => $group->status,
                'owner_email' => optional($group->owner)->email,
                'plan_name' => optional($group->plan)->name ?: optional(optional($subscription)->plan)->name,
                'member_limit' => (int)$group->member_limit,
                'member_count' => (int)$group->member_count,
                'transfer_enable' => (int)optional($subscription)->transfer_enable,
                'used' => $subscription ? (int)$subscription->u + (int)$subscription->d : 0,
                'expired_at' => optional($subscription)->expired_at,
                'created_at' => $group->created_at,
            ];
        });
        return response(['data' => $groups]);
    }

    public function suspendSharedSubscription(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        (new ResellerSharedSubscriptionService())->suspendFromReseller(
            ResellerAccount::findOrFail($request->reseller['id']), (int)$id, $data['reason']
        );
        return response(['data' => true]);
    }

    public function sharedSubscriptionMembers(Request $request, $id)
    {
        return response(['data' => (new ResellerSharedSubscriptionService())->auditMembers(
            ResellerAccount::findOrFail($request->reseller['id']), (int)$id
        )]);
    }

    public function removeSharedMember(Request $request, $groupId, $memberId)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        (new ResellerSharedSubscriptionService())->forceRemoveFromReseller(
            ResellerAccount::findOrFail($request->reseller['id']), (int)$groupId, (int)$memberId, $data['reason']
        );
        return response(['data' => true]);
    }

    private function accountData(Request $request): array
    {
        $account = ResellerAccount::findOrFail($request->reseller['id']);
        return (new ResellerAuthService())->safeAccount($account) + [
            'store_description' => $account->store_description,
            'allowed_payment_drivers' => array_values((array)config('v2board.reseller_allowed_payment_drivers', [])),
        ];
    }

    private function ensureStoreCanSell(Request $request): void
    {
        if (empty($request->reseller['can_sell'])) {
            abort(403, 'Account and store approval are both required before selling');
        }
    }

    private function resellerPayment(Request $request, int $id): ResellerPayment
    {
        $payment = ResellerPayment::where('id', $id)
            ->where('reseller_id', $request->reseller['id'])
            ->first();
        if (!$payment) abort(404, 'Payment method does not exist');
        return $payment;
    }

    private function paymentConfig(ResellerPayment $payment): array
    {
        try {
            $config = json_decode(Crypt::decryptString($payment->config_encrypted), true);
        } catch (\Throwable $e) {
            abort(500, 'Payment configuration is unavailable');
        }
        if (!is_array($config)) abort(500, 'Payment configuration is invalid');
        return $config;
    }

    private function mergedPaymentConfig(ResellerPayment $payment, array $config): array
    {
        if (!$payment->exists) return $config;

        foreach ($this->paymentConfig($payment) as $key => $value) {
            if (!array_key_exists($key, $config)
                || (ResellerPaymentService::isSensitiveConfigField($key) && trim((string)$config[$key]) === '')) {
                $config[$key] = $value;
            }
        }
        return $config;
    }

    private function validatePaymentConfig(string $driver, array $config): array
    {
        if ($driver !== 'EPay') return $config;

        foreach (['url', 'pid', 'key', 'type'] as $key) {
            if (isset($config[$key]) && is_string($config[$key])) $config[$key] = trim($config[$key]);
        }
        foreach (['url', 'pid', 'key'] as $key) {
            if (empty($config[$key])) abort(422, "EPay {$key} is required");
        }
        if (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
            abort(422, 'EPay URL is invalid');
        }
        $config['url'] = rtrim($config['url'], '/');
        return $config;
    }

    private function planData(ResellerPlan $plan, bool $includeBase): array
    {
        $data = [
            'id' => $plan->id,
            'base_plan_id' => $plan->base_plan_id,
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
            'enabled' => (int)$plan->enabled,
            'sort' => $plan->sort,
        ];
        if ($includeBase && $plan->basePlan) {
            $data['base'] = [
                'name' => $plan->basePlan->name,
                'transfer_enable' => $plan->basePlan->transfer_enable,
                'device_limit' => $plan->basePlan->device_limit,
                'speed_limit' => $plan->basePlan->speed_limit,
            ];
        }
        return $data;
    }
}
