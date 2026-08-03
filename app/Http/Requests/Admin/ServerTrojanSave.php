<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServerTrojanSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'show' => '',
            'name' => 'required',
            'group_id' => 'required|array',
            'route_id' => 'nullable|array',
            'parent_id' => 'nullable|integer',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'network' => 'required',
            'network_settings' => 'nullable',
            'allow_insecure' => 'nullable|in:0,1',
            'server_name' => 'nullable',
            'tags' => 'nullable|array',
            'rate' => 'required|numeric'
        ];
    }

    public function messages()
    {
        return [
            'name.required' => __('节点名称不能为空'),
            'group_id.required' => __('权限组不能为空'),
            'group_id.array' => __('权限组格式不正确'),
            'route_id.array' => __('路由组格式不正确'),
            'parent_id.integer' => __('父节点格式不正确'),
            'host.required' => __('节点地址不能为空'),
            'port.required' => __('连接端口不能为空'),
            'server_port.required' => __('后端服务端口不能为空'),
            'allow_insecure.in' => __('允许不安全格式不正确'),
            'tags.array' => __('标签格式不正确'),
            'rate.required' => __('倍率不能为空'),
            'rate.numeric' => __('倍率格式不正确')
        ];
    }
}
