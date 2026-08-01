<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as Middleware;

class TrimStrings extends Middleware
{
    /**
     * The names of the attributes that should not be trimmed.
     *
     * @var array
     */
    protected $except = [
        'password',
        'password_confirmation',
        // 修复「旧密码输入正确但改密报错」（2026-08-01）：登录/注册/找回密码校验的是
        // password 字段（上面已豁免），所以首尾带空白的密码能注册、能登录；而改密链路
        // （/user/changePassword 的 old_password/new_password、/user/resetPassword 与
        // /user/2fa/* 的 current_password）此前会被本中间件把首尾空白剪掉，同一串密码
        // 登录能过、改密却报「旧密码错误」，重试还会喂 PASSWORD_RESET_ERROR_LIMIT 限流。
        // 密码类字段必须原样透传，与 password 字段保持同一契约。
        'old_password',
        'new_password',
        'current_password',
    ];
}
