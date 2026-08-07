<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\ArithmeticVerificationService;
use App\Services\TelegramLoginLinkService;
use App\Services\TwoFactorService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ReCaptcha\ReCaptcha;

class AuthController extends Controller
{
    public function register(AuthRegister $request, bool $skipEmailVerification = false)
    {
        // 仅第三方注册开关：必须钉死在函数最顶部、一切副作用之前 —— 本函数后面依次有
        // IP 限流计数写入、邮箱验证码核销（Cache::forget）、邀请码消耗（status=1 落库）、
        // 算术验证 consume（一次性核销）。认知文档（EZ-COGNITION）登记过 register()
        // 「先烧邀请码、后验算术」的历史缺陷：拦截一旦放到副作用之后，被拒绝的用户会
        // 白白烧掉一次性资源。OAuth 注册走 OAuthController 自己的流程，不经过这里，
        // 天然不受本开关影响；登录/找回密码也不在此函数内。
        // 波及面：Store\Controller::register（reseller 店面注册）内部调用本函数，
        // 因此开关开启时店面邮箱注册同样被 403——店面页面不含 OAuth 按钮，等于
        // 关闭全部 reseller 店面新客注册。这是「拦截钉死在最顶部」规格的自然覆盖，
        // 详见 Store\Controller::register 处的波及面声明。
        if ((int)config('v2board.oauth_register_only', 0)) {
            abort(403, __('Registration is only available through third-party accounts'));
        }
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int)$registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, __('Register frequently, please try again after :minute minute', [
                    'minute' => config('v2board.register_limit_expire', 60)
                ]));
            }
        }
        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, __('Invalid code is incorrect'));
            }
        }
        // @telegram.invalid / @github.io 是 OAuth 注册的内部占位域（本地部分来自
        // UID 或「邮箱局部_用户名」，都可预测）。邮箱验证关闭时，任何人都能抢注
        // 占位地址，让真主人永远在 consumeTicket() 的 409 上撞墙。OAuth 手动补邮箱
        // 那条路不用拦 —— validateEmailVerification 必须收到发往该邮箱的验证码，
        // 而这两个域都收不到信。
        if (preg_match('/@(telegram\.invalid|github\.io)$/i', (string)$request->input('email'))) {
            abort(422, 'This email domain is reserved');
        }
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, __('Email suffix is not in the Whitelist'));
            }
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, __('Gmail alias is not supported'));
            }
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, __('Registration has closed'));
        }
        if ((int)config('v2board.invite_force', 0)) {
            if (empty($request->input('invite_code'))) {
                abort(500, __('You must use the invitation code to register'));
            }
        }
        $email = $request->input('email');
        $cacheKeyEmail = is_string($email) ? strtolower(trim($email)) : '';
        if (!$skipEmailVerification && (int)config('v2board.email_verify', 0)) {
            $inputCode = $request->input('email_code');
            if (!is_string($inputCode) || !preg_match('/^\d{6}$/', $inputCode)) {
                abort(500, __('Incorrect email verification code'));
            }
            $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
            if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
                abort(500, __('Incorrect email verification code'));
            }
        }
        $password = $request->input('password');
        $exist = User::where('email', $email)->first();
        if ($exist) {
            abort(500, __('Email already exists'));
        }
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        // 注册照旧允许弱密码（不然没人能完成注册），但从这一刻起持续提醒重置。跟着同一条
        // INSERT 走，不额外发一次 UPDATE。
        \App\Services\PasswordPolicyService::stampRequired($user);
        if ($request->input('invite_code')) {
            $inviteCode = InviteCode::where('code', $request->input('invite_code'))
                ->where('status', 0)
                ->first();
            if (!$inviteCode) {
                if ((int)config('v2board.invite_force', 0)) {
                    abort(500, __('Invalid invitation code'));
                }
            } else {
                $user->invite_user_id = $inviteCode->user_id ? $inviteCode->user_id : null;
                if (!(int)config('v2board.invite_never_expire', 0)) {
                    $inviteCode->status = 1;
                    $inviteCode->save();
                }
            }
        }

        // try out
        if ((int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->device_limit = $plan->device_limit;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
            }
        }

        if ((int)config('v2board.arithmetic_verification_enable', 0)) {
            try {
                $verified = (new ArithmeticVerificationService())->consume(
                    (string)$request->input('arithmetic_challenge_id'),
                    $request->input('arithmetic_answer'),
                    (string)$request->ip()
                );
            } catch (\Throwable $e) {
                report($e);
                abort(503, __('Arithmetic verification is temporarily unavailable'));
            }
            if (!$verified) {
                abort(422, __('Incorrect arithmetic verification'));
            }
        }

        $registered = \App\Utils\TokenRotationContext::using('register', function () use ($user) {
            return $user->save();
        });
        if (!$registered) {
            abort(500, __('Register failed'));
        }
        if (!$skipEmailVerification && (int)config('v2board.email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        }

        $user->last_login_at = time();
        $user->save();

        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int)$registerCountByIP + 1,
                (int)config('v2board.register_limit_expire', 60) * 60
            );
        }

        $authService = new AuthService($user);

        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function login(AuthLogin $request)
    {
        return $this->performLogin($request, false);
    }

    public function adminLogin(AuthLogin $request)
    {
        return $this->performLogin($request, true);
    }

    private function performLogin(AuthLogin $request, $adminOnly = false)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if ((int)config('v2board.password_limit_enable', 1)) {
            $passwordErrorCount = (int)Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int)config('v2board.password_limit_count', 5)) {
                abort(500, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('Incorrect email or password'));
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ((int)config('v2board.password_limit_enable')) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int)$passwordErrorCount + 1,
                    60 * (int)config('v2board.password_limit_expire', 60)
                );
            }
            abort(500, __('Incorrect email or password'));
        }

        if ($user->banned) {
            abort(500, __('Your account has been suspended'));
        }
        if ($adminOnly && !(bool)$user->is_admin) {
            abort(403, __('Administrator access required'));
        }

        $authService = new AuthService($user);
        $twoFactor = (new TwoFactorService())->issueLoginResult($user, $request);
        if ($twoFactor) {
            return response(['data' => $twoFactor]);
        }
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function adminVerify2fa(Request $request)
    {
        $this->assertAdminChallenge($request->input('challenge'), 'login');
        return $this->verify2fa($request);
    }

    public function adminSetup2fa(Request $request)
    {
        $this->assertAdminChallenge($request->input('setup_token'), 'setup');
        return $this->setup2fa($request);
    }

    public function adminConfirmSetup2fa(Request $request)
    {
        $this->assertAdminChallenge($request->input('setup_token'), 'setup');
        return $this->confirmSetup2fa($request);
    }

    private function assertAdminChallenge($token, $type)
    {
        $challenge = (new TwoFactorService())->getChallenge($token, $type);
        $user = $challenge && !empty($challenge['user_id'])
            ? User::find($challenge['user_id'])
            : null;
        if (!$user || !(bool)$user->is_admin) {
            abort(403, __('Administrator access required'));
        }
    }

    public function verify2fa(Request $request)
    {
        $service = new TwoFactorService();
        $user = $service->verifyLogin(
            $request->input('challenge'),
            $request->input('code'),
            $request->input('recovery_code'),
            $request
        );
        return response([
            'data' => (new AuthService($user))->generateAuthData($request, true)
        ]);
    }

    public function setup2fa(Request $request)
    {
        $service = new TwoFactorService();
        $setupToken = $request->input('setup_token');
        $challenge = $service->getChallenge($setupToken, 'setup');
        if (!$challenge || empty($challenge['user_id'])) abort(500, '二步验证设置请求已过期，请重新登录');
        $user = User::find($challenge['user_id']);
        if (!$user || !($user->is_admin || $user->is_staff) || !$service->requiresSetup($user)) abort(403, '无权执行二步验证设置');
        $data = $service->beginSetup($user);
        $data['setup_token'] = $setupToken;
        return response(['data' => $data]);
    }

    public function confirmSetup2fa(Request $request)
    {
        $service = new TwoFactorService();
        $setupToken = $request->input('setup_token');
        $challenge = $service->getChallenge($setupToken, 'setup');
        if (!$challenge || empty($challenge['user_id'])) abort(500, '二步验证设置请求已过期，请重新登录');
        $user = User::find($challenge['user_id']);
        if (!$user || !($user->is_admin || $user->is_staff) || !$service->requiresSetup($user)) abort(403, '无权执行二步验证设置');
        $codes = $service->confirmSetup($user, $request->input('code'), $request, $setupToken);
        $service->forgetChallenge($setupToken, 'setup');
        $authData = (new AuthService($user))->generateAuthData($request, true);
        $authData['recovery_codes'] = $codes;
        return response(['data' => $authData]);
    }

    public function token2Login(Request $request)
    {
        if ($request->input('token')) {
            $redirect = '/#/login?verify=' . $request->input('token') . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
            if (config('v2board.app_url')) {
                $location = config('v2board.app_url') . $redirect;
            } else {
                $location = url($redirect);
            }
            return redirect()->to($location)->send();
        }

        if ($request->input('verify')) {
            $verify = $request->input('verify');
            if (!is_string($verify)) {
                abort(500, __('Token error'));
            }
            if (TelegramLoginLinkService::isLoginToken($verify)) {
                $user = (new TelegramLoginLinkService())->consume($verify);
                if (!$user) {
                    abort(500, __('Token error'));
                }
                return $this->quickLoginResponse($user, $request);
            }

            $key =  CacheKey::get('TEMP_TOKEN', $verify);
            $userId = Cache::get($key);
            if (!$userId) {
                abort(500, __('Token error'));
            }
            $user = User::find($userId);
            if (!$user) {
                abort(500, __('The user does not exist'));
            }
            if ($user->banned) {
                abort(500, __('Your account has been suspended'));
            }
            Cache::forget($key);
            return $this->quickLoginResponse($user, $request);
        }
    }

    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) abort(403, '未登录或登陆已过期');

        $model = User::find($user['id']);
        if (!$model || $model->banned) {
            abort(403, __('Your account has been suspended'));
        }

        $url = (new TelegramLoginLinkService())->issue(
            $model,
            null,
            $request->input('redirect') ? $request->input('redirect') : 'dashboard'
        );
        return response([
            'data' => $url
        ]);
    }

    private function quickLoginResponse(User $user, Request $request)
    {
        $authService = new AuthService($user);
        $twoFactor = (new TwoFactorService())->issueLoginResult($user, $request);
        if ($twoFactor) {
            return response(['data' => $twoFactor]);
        }
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function forget(AuthForget $request)
    {
        $email     = $request->input('email');
        $inputCode = $request->input('email_code');
        $password  = $request->input('password');

        if (!is_string($email) || !is_string($inputCode) || !is_string($password)) {
            abort(500, __('Incorrect email verification code'));
        }
        if (!preg_match('/^\d{6}$/', $inputCode)) {
            abort(500, __('Incorrect email verification code'));
        }

        $cacheKeyEmail         = strtolower(trim($email));
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $cacheKeyEmail);
        $forgetRequestLimit    = (int)Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            abort(500, __('Reset failed, Please try again later'));
        }

        $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit + 1, 300);
            abort(500, __('Incorrect email verification code'));
        }
        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('This email is not registered in the system'));
        }
        $user->password      = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;
        if (!$user->save()) {
            abort(500, __('Reset failed'));
        }
        // 走完邮件验证码设的仍然是用户自选密码，按策略不合规，重新开始提醒。
        \App\Services\PasswordPolicyService::markRequired($user);
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        (new AuthService($user))->removeAllSession();
        return response([
            'data' => true
        ]);
    }
}
