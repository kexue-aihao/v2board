<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CouponGenerate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'generate_count' => 'nullable|integer|max:500',
            'name' => 'required',
            'type' => 'required|in:1,2',
            'value' => 'required|integer',
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer',
            'limit_use' => 'nullable|integer',
            'limit_use_with_user' => 'nullable|integer',
            'limit_plan_ids' => 'nullable|array',
            'limit_period' => 'nullable|array',
            'code' => ''
        ];
    }

    public function messages()
    {
        return [
            'generate_count.integer' => __('生成数量必须为数字'),
            'generate_count.max' => __('生成数量最大为500个'),
            'name.required' => __('名称不能为空'),
            'type.required' => __('类型不能为空'),
            'type.in' => __('类型格式有误'),
            'value.required' => __('金额或比例不能为空'),
            'value.integer' => __('金额或比例格式有误'),
            'started_at.required' => __('开始时间不能为空'),
            'started_at.integer' => __('开始时间格式有误'),
            'ended_at.required' => __('结束时间不能为空'),
            'ended_at.integer' => __('结束时间格式有误'),
            'limit_use.integer' => __('最大使用次数格式有误'),
            'limit_use_with_user.integer' => __('限制用户使用次数格式有误'),
            'limit_plan_ids.array' => __('指定订阅格式有误'),
            'limit_period.array' => __('指定周期格式有误')
        ];
    }
}
