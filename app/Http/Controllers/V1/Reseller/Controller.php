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

    public function savePayment(Request $request)
    {
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

    private function accountData(Request $request): array
    {
        $account = ResellerAccount::findOrFail($request->reseller['id']);
        return (new ResellerAuthService())->safeAccount($account) + [
            'store_description' => $account->store_description,
            'allowed_payment_drivers' => array_values((array)config('v2board.reseller_allowed_payment_drivers', [])),
        ];
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
