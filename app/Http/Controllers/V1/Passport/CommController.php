<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Models\InviteCode;
use App\Models\User;
use App\Services\TelegramPasswordResetService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

use function PHPUnit\Framework\isEmpty;

class CommController extends Controller
{
    private function isEmailVerify()
    {
        return response([
            'data' => (int)config('v2board.email_verify', 0) ? 1 : 0
        ]);
    }

    public function sendTelegramForgetCode(Request $request)
    {
        $ip = $request->ip();
        if (RateLimiter::tooManyAttempts($ip, 3)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit($ip, 60);

        $email = strtolower(trim((string)$request->input('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(500, __('Email format is incorrect'));
        }
        // 账号不存在与未绑定 Telegram 回同一句话，避免把这里变成邮箱枚举接口
        $user = User::where('email', $email)->first();
        $result = (new TelegramPasswordResetService())->issue($user ?: new User());
        if (!$result['ok']) {
            abort(500, $result['message']);
        }
        return response([
            'data' => true
        ]);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return response([
            'data' => true
        ]);
    }

    private function getEmailSuffix()
    {
        $suffix = config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        if (!is_array($suffix)) {
            return preg_split('/,/', $suffix);
        }
        return $suffix;
    }
}
