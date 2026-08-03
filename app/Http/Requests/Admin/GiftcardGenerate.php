<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class GiftcardGenerate extends FormRequest
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
            'type' => 'required|in:1,2,3,4,5',
            'value' => ['required_if:type,1,2,3,5', 'nullable', 'integer'],
            'plan_id' => ['required_if:type,5', 'nullable','integer'],
            'started_at' => 'required|integer',
            'ended_at' => 'required|integer',
            'limit_use' => 'nullable|integer',
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
            'value.required' => __('数值不能为空'),
            'value.integer' => __('数值格式有误'),
            'plan_id.required' => __('订阅不能为空'),
            'started_at.required' => __('开始时间不能为空'),
            'started_at.integer' => __('开始时间格式有误'),
            'ended_at.required' => __('结束时间不能为空'),
            'ended_at.integer' => __('结束时间格式有误'),
            'limit_use.integer' => __('最大使用次数格式有误')
        ];
    }
}
