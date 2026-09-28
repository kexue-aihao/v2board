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

    public function forgetByTelegram(Request $request)
    {
        $email = strtolower(trim((string)$request->input('email')));
        $inputCode = (string)$request->input('telegram_code');
        $password = (string)$request->input('password');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(500, __('Email format is incorrect'));
        }
        if (!preg_match('/^\d{6}$/', $inputCode)) {
            abort(500, __('Incorrect verification code'));
        }
        if (strlen($password) < 8 || strlen($password) > 64) {
            abort(500, __('Password must be greater than 8 digits'));
        }

        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $email);
        $forgetRequestLimit    = (int)Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            abort(500, __('Reset failed, Please try again later'));
        }

        $cachedCode = Cache::get(CacheKey::get('TELEGRAM_FORGET_CODE', $email));
        if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit + 1, 300);
            abort(500, __('Incorrect verification code'));
        }
        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, __('This email is not registered in the system'));
        }
        if ((string)($user->telegram_id ?? '') === '') {
            abort(500, __('This account has not bound Telegram, please use the email verification code'));
        }
        $user->password      = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;
        if (!$user->save()) {
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
            abort(500, __('Registration has closed'));
        }
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = (int)Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()));
            if ($registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, __('Register frequently, please try again after :minute minute', [
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
