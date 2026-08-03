<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email' => 'required|email:strict',
            'password' => 'nullable|min:8',
            'transfer_enable' => 'numeric',
            'device_limit' => 'nullable|integer',
            'expired_at' => 'nullable|integer',
            'banned' => 'required|in:0,1',
            'plan_id' => 'nullable|integer',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'is_admin' => 'required|in:0,1',
            'is_staff' => 'required|in:0,1',
            'u' => 'integer',
            'd' => 'integer',
            'balance' => 'integer',
            'commission_type' => 'integer',
            'commission_balance' => 'integer',
            'remarks' => 'nullable',
            'speed_limit' => 'nullable|integer'
        ];
    }

    public function messages()
    {
        return [
            'email.required' => __('邮箱不能为空'),
            'email.email' => __('邮箱格式不正确'),
            'transfer_enable.numeric' => __('流量格式不正确'),
            'device_limit.integer' => __('设备数限制格式不正确'),
            'expired_at.integer' => __('到期时间格式不正确'),
            'banned.required' => __('是否封禁不能为空'),
            'banned.in' => __('是否封禁格式不正确'),
            'is_admin.required' => __('是否管理员不能为空'),
            'is_admin.in' => __('是否管理员格式不正确'),
            'is_staff.required' => __('是否员工不能为空'),
            'is_staff.in' => __('是否员工格式不正确'),
            'plan_id.integer' => __('订阅计划格式不正确'),
            'commission_rate.integer' => __('推荐返利比例格式不正确'),
            'commission_rate.nullable' => __('推荐返利比例格式不正确'),
            'commission_rate.min' => __('推荐返利比例最小为0'),
            'commission_rate.max' => __('推荐返利比例最大为100'),
            'discount.integer' => __('专属折扣比例格式不正确'),
            'discount.nullable' => __('专属折扣比例格式不正确'),
            'discount.min' => __('专属折扣比例最小为0'),
            'discount.max' => __('专属折扣比例最大为100'),
            'u.integer' => __('上行流量格式不正确'),
            'd.integer' => __('下行流量格式不正确'),
            'balance.integer' => __('余额格式不正确'),
            'commission_balance.integer' => __('佣金格式不正确'),
            'password.min' => __('密码长度最小8位'),
            'speed_limit.integer' => __('限速格式不正确')
        ];
    }
}
