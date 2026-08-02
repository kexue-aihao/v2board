<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class OrderSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'plan_id' => 'required',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price,deposit',
            'subscription_id' => 'nullable|integer',
            'new_subscription' => 'nullable|boolean',
            // 充值金额（分）：仅在充值单(plan_id=0)时必填，必须是 1..9999998 的整数。
            // 原来控制器直接取值 + PHP 松散比较，'0.5'/'1e-3'/'.9'/'0x1' 会穿过两道守卫。
            'deposit_amount' => 'required_if:plan_id,0|integer|min:1|max:9999998'
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => __('Plan ID cannot be empty'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period')
        ];
    }
}
