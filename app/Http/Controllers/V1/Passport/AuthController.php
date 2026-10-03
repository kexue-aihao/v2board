<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthLogin;
use App\Models\User;
use App\Services\AuthService;
use App\Services\TelegramLoginLinkService;
use App\Services\TwoFactorService;
use App\Services\TelegramRegistrationService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ReCaptcha\ReCaptcha;

/**
 * 登录 / 注册 / 找回密码 / 二步验证的入口。
 *
 * 失败响应的状态码按语义定档。上游 v2board 对这些一律 abort(500)，那会把「用户输错密码」
 * 和「服务端炸了」混成同一件事：监控里全是 500，前端也无法区分该提示什么。约定如下：
 *
 *   400  请求本身不合法（比如参数传成了数组）
 *   401  凭据或一次性令牌不对/已过期 —— 登录密码、免密登录 token、二步验证 challenge
 *   403  账号被封、权限不足、功能关闭（停注册、要求管理员）
 *   404  目标资源不存在（邮箱未注册）
 *   409  请求与账号当前状态冲突（例如该账号没绑 Telegram，走不了这条路）
 *   422  提交的值没通过校验（邮箱格式、验证码格式或对不上、密码长度）
 *   429  频率限制（密码错误次数、找回次数、注册次数）
 *   500  只有真正的服务端故障才留 500（整个文件仅剩 Reset failed 一处）
 *
 * 前端据此判断「会话失效」时必须排除 /passport/* —— 这里本来就是未登录入口区，
 * 401 只代表「这次没通过」，不代表会话没了。详见 scripts/patch-signature-auth-logout.js。
 */
class AuthController extends Controller
{
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
                abort(429, __('There are too many password errors, please try again after :minute minutes.', [
                    'minute' => config('v2board.password_limit_expire', 60)
                ]));
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            // 登录失败是客户端错误，上游用的 abort(500) 既污染监控、也会让前端把它当成
            // 「服务端炸了」。这里按语义定档：凭据不对 → 401，账号被封 → 403，尝试过频 → 429。
            // 前端据此判断会话是否失效时必须排除 /passport/* —— 那一片本来就是未登录入口，
            // 401 只代表「这次没通过」，不代表会话没了，见 signature 产物的响应拦截器。
            abort(401, __('Incorrect email or password'));
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
            abort(401, __('Incorrect email or password'));
        }

        if ($user->banned) {
            abort(403, __('Your account has been suspended'));
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
        if (!$challenge || empty($challenge['user_id'])) abort(401, '二步验证设置请求已过期，请重新登录');
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
        if (!$challenge || empty($challenge['user_id'])) abort(401, '二步验证设置请求已过期，请重新登录');
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
                // verify 传成数组之类属于请求本身就不合法，不是「凭据不对」
                abort(400, __('Token error'));
            }
            if (TelegramLoginLinkService::isLoginToken($verify)) {
                $user = (new TelegramLoginLinkService())->consume($verify);
                if (!$user) {
                    abort(401, __('Token error'));
                }
                return $this->quickLoginResponse($user, $request);
            }

            $key =  CacheKey::get('TEMP_TOKEN', $verify);
            $userId = Cache::get($key);
            if (!$userId) {
                abort(401, __('Token error'));
            }
            $user = User::find($userId);
            if (!$user) {
                abort(401, __('The user does not exist'));
            }
            if ($user->banned) {
                abort(403, __('Your account has been suspended'));
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

    public function forgetByTelegram(Request $request)
    {
        $email = strtolower(trim((string)$request->input('email')));
        $inputCode = (string)$request->input('telegram_code');
        $password = (string)$request->input('password');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, __('Email format is incorrect'));
        }
        if (!preg_match('/^\d{6}$/', $inputCode)) {
            abort(422, __('Incorrect verification code'));
        }
        if (strlen($password) < 8 || strlen($password) > 64) {
            abort(422, __('Password must be greater than 8 digits'));
        }

        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $email);
        $forgetRequestLimit    = (int)Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            abort(429, __('Reset failed, Please try again later'));
        }

        $cachedCode = Cache::get(CacheKey::get('TELEGRAM_FORGET_CODE', $email));
        if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit + 1, 300);
            abort(422, __('Incorrect verification code'));
        }
        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(404, __('This email is not registered in the system'));
        }
        if ((string)($user->telegram_id ?? '') === '') {
            abort(409, __('This account has not bound Telegram, please use the email verification code'));
        }
        $user->password      = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;
        if (!$user->save()) {
            // 这一处刻意保持 500：save() 返回 false 意味着服务端没能落库，是真正的服务端故障，
            // 不是客户端错误。全文件的 abort(500) 都已按语义改档，只有这里该留。
            abort(500, __('Reset failed'));
        }
        // 与邮箱那条路一致：用户自选密码按策略要重新提醒。
        \App\Services\PasswordPolicyService::markRequired($user);
        Cache::forget(CacheKey::get('TELEGRAM_FORGET_CODE', $email));
        (new AuthService($user))->removeAllSession();
        return response([
            'data' => true
        ]);
    }
    /**
     * Telegram 注册的网页一步：凭「邮箱 + 验证码」建号并直接登录。
     * 传统邮箱注册下线后，这是唯一的自主注册入口。
     */
    public function registerByTelegram(Request $request)
    {
        if ((int)config('v2board.stop_register', 0)) {
            abort(403, __('Registration has closed'));
        }
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = (int)Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()));
            if ($registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(429, __('Register frequently, please try again after :minute minute', [
                    'minute' => config('v2board.register_limit_expire', 60)
                ]));
            }
        }

        $user = (new TelegramRegistrationService())->register(
            (string)$request->input('email'),
            (string)$request->input('code')
        );

        // 与邮箱注册、OAuth 注册同一套计数，避免绕开按 IP 的注册限流
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int)Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) + 1,
                (int)config('v2board.register_limit_expire', 60) * 60
            );
        }

        return response()->json([
            'data' => (new AuthService($user))->generateAuthData($request)
        ]);
    }

}
