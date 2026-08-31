<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderReconcile extends FormRequest
{
    public function rules()
    {
        return [
            'trade_no' => 'required|string|max:36',
            'callback_no' => 'required|string|max:255',
            'paid_amount_minor' => 'required|integer|min:1',
            'remark' => 'required|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'trade_no.required' => __('订单号不能为空'),
            'callback_no.required' => __('网关交易号不能为空'),
            'paid_amount_minor.required' => __('实付金额不能为空'),
            'paid_amount_minor.integer' => __('实付金额必须是分值整数'),
            'paid_amount_minor.min' => __('实付金额必须大于 0'),
            'remark.required' => __('补单备注不能为空'),
        ];
    }
}
