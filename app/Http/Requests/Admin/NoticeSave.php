<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NoticeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title' => 'required',
            'content' => 'required',
            'img_url' => 'nullable|url',
            'tags' => 'nullable|array'
        ];
    }

    public function messages()
    {
        return [
            'title.required' => __('标题不能为空'),
            'content.required' => __('内容不能为空'),
            'img_url.url' => __('图片URL格式不正确'),
            'tags.array' => __('标签格式不正确')
        ];
    }
}
