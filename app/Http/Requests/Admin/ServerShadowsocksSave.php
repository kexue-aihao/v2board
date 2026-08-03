<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ServerShadowsocksSave extends FormRequest
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
            'parent_id' => 'nullable|integer',
            'route_id' => 'nullable|array',
            'host' => 'required',
            'port' => 'required',
            'server_port' => 'required',
            'cipher' => 'required|in:aes-128-gcm,aes-192-gcm,aes-256-gcm,chacha20-ietf-poly1305,2022-blake3-aes-128-gcm,2022-blake3-aes-256-gcm',
            'obfs' => 'nullable|in:http',
            'obfs_settings' => 'nullable|array',
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
            'cipher.required' => __('加密方式不能为空'),
            'tags.array' => __('标签格式不正确'),
            'rate.required' => __('倍率不能为空'),
            'rate.numeric' => __('倍率格式不正确'),
            'obfs.in' => __('混淆格式不正确'),
            'obfs_settings.array' => __('混淆设置格式不正确')
        ];
    }
}
