<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderAssign extends FormRequest
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
            'email' => 'required',
            'total_amount' => 'required',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price'
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => __('订阅不能为空'),
            'email.required' => __('邮箱不能为空'),
            'total_amount.required' => __('支付金额不能为空'),
            'period.required' => __('订阅周期不能为空'),
            'period.in' => __('订阅周期格式有误')
        ];
    }
}
