<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use App\Services\TelegramService;
use App\Services\UserTelegramBindingService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use ReCaptcha\ReCaptcha;
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

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        $ip = $request->ip();
        if (RateLimiter::tooManyAttempts($ip, 3)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit($ip, 60);

        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }
        $email = $request->input('email');
        $cacheKeyEmail = strtolower(trim((string)$email));
        $isForget = filter_var(
            $request->input('isforget', $request->input('isForgetPassword', false)),
            FILTER_VALIDATE_BOOLEAN
        );

        if ((int)config('v2board.email_verify', 0)
            && (int)config('v2board.invite_force', 0)
            && !$isForget) {
            $inviteCode = trim((string)$request->input('invite_code', ''));
            if ($inviteCode === '') {
                abort(500, __('You must use the invitation code to register'));
            }
            if (!InviteCode::where('code', $inviteCode)->where('status', 0)->exists()) {
                abort(500, __('Invalid invitation code'));
            }
        }

        $email_exists = User::where('email', $email)->exists();
        //检查是否在白名单内
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }
        // 检查是否是gmail别名邮箱
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if (!$isForget && $email_exists) {
            abort(500, __('This email is registered'));
        }
        if ($isForget && !$email_exists) {
            abort(500, __('This email is not registered in the system'));
        }
        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $cacheKeyEmail))) {
            abort(500, __('Email verification code has been sent, please request again later'));
        }
        $code = (string)rand(100000, 999999);
        $subject = config('v2board.app_name', 'V2Board') . __('Email verification code');

        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'code' => $code,
                'url' => config('v2board.app_url')
            ]
        ]);

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $cacheKeyEmail), time(), 60);
        return response([
            'data' => true
        ]);
    }

    /**
     * 用 Telegram 下发找回密码验证码。
     * 只对已绑定 Telegram 的账号发送；没绑定的账号继续走原来的邮箱验证码路径。
     */
    public function sendTelegramForgetCode(Request $request)
    {
        $ip = $request->ip();
        if (RateLimiter::tooManyAttempts($ip, 3)) {
            abort(429, __('Too many requests, please try again later.'));
        }
        RateLimiter::hit($ip, 60);

        if (!(new UserTelegramBindingService())->enabled()) {
            abort(503, __('Telegram account binding is not enabled'));
        }
        $email = strtolower(trim((string)$request->input('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(500, __('Email format is incorrect'));
        }
        $sentKey = CacheKey::get('LAST_SEND_TELEGRAM_FORGET_TIMESTAMP', $email);
        if (Cache::get($sentKey)) {
            abort(500, __('Email verification code has been sent, please request again later'));
        }
        $user = User::where('email', $email)->first();
        if (!$user || (string)($user->telegram_id ?? '') === '') {
            abort(500, __('This account has not bound Telegram, please use the email verification code'));
        }
        $code = (string)rand(100000, 999999);
        // 先发后存：发送抛异常时验证码不会留在缓存里，避免「已下发」的假象。
        (new TelegramService())->sendMessage(
            (int)$user->telegram_id,
            sprintf(
                '【%s】找回密码验证码：%s，5 分钟内有效。如非本人操作请忽略本条消息。',
                config('v2board.app_name', 'V2Board'),
                $code
            )
        );
        Cache::put(CacheKey::get('TELEGRAM_FORGET_CODE', $email), $code, 300);
        Cache::put($sentKey, time(), 60);
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
