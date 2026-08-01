<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Models\InviteCode;
use App\Models\OAuthIdentity;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\OAuthService;
use App\Services\TwoFactorService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use App\Utils\TokenRotationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReCaptcha\ReCaptcha;

class OAuthController extends Controller
{
    public function redirect(Request $request, $provider)
    {
        return redirect()->to((new OAuthService())->begin((string)$provider, $request));
    }

    public function callback(Request $request, $provider)
    {
        return redirect()->to((new OAuthService())->callback((string)$provider, $request));
    }

    /**
     * Hand out a bare state so the Telegram login widget can be rendered
     * without first bouncing the browser through /redirect. Only Telegram
     * qualifies: the redirect providers must go through begin() so the
     * authorization URL carries the verifier and nonce belonging to the same
     * state, and handing those out over JSON would defeat PKCE.
     */
    public function state(Request $request, $provider)
    {
        $service = new OAuthService();
        $provider = $service->assertProvider((string)$provider);
        if ($provider !== 'telegram') {
            abort(404, 'This provider does not issue a bare state');
        }

        [$state] = $service->issueState($provider, $request);
        return response(['data' => ['state' => $state]]);
    }

    public function complete(Request $request)
    {
        $service = new OAuthService();
        $provider = strtolower(trim((string)$request->input('provider', '')));
        if ($provider === 'telegram') {
            $provider = $service->assertProvider($provider);
            $data = $request->input('data', $request->except(['provider', 'state', 'ticket', 'email', 'email_code', 'recaptcha_data', 'invite_code', 'arithmetic_challenge_id', 'arithmetic_answer']));
            if (!is_array($data)) abort(422, 'Telegram login data is invalid');
            $profile = $service->telegramProfile($data, (string)$request->input('state'), $request);
            $ticket = $service->storeTicket($profile);
        } else {
            $ticket = trim((string)$request->input('ticket', ''));
        }

        if ($ticket === '') abort(422, 'OAuth ticket is required');
        return $this->consumeTicket($ticket, $request, $service);
    }

    public function link(Request $request)
    {
        $ticket = trim((string)$request->input('ticket', ''));
        $service = new OAuthService();
        return $service->withTicketLock($ticket, function () use ($request, $ticket, $service) {
            $payload = $service->ticket($ticket);
            if (!$payload) abort(422, 'OAuth ticket is invalid or expired');
            $profile = $payload['profile'];
            $user = User::find($request->user['id']);
            if (!$user) abort(403, 'Login required');
            if (!empty($profile['email']) && strtolower((string)$profile['email']) !== strtolower((string)$user->email)) {
                abort(403, 'The verified provider email does not match this account');
            }
            if (empty($profile['verified_email'])) {
                $this->validateEmailVerification($request, (string)$user->email);
            }
            $existing = OAuthIdentity::where('provider', $profile['provider'])
                ->where('provider_subject', $profile['subject'])
                ->where('provider_tenant', $profile['tenant'] ?? '')
                ->first();
            if ($existing && (int)$existing->user_id !== (int)$user->id) {
                abort(409, 'This provider account is already linked');
            }
            DB::transaction(function () use ($profile, $user) {
                OAuthIdentity::updateOrCreate([
                    'provider' => $profile['provider'],
                    'provider_subject' => $profile['subject'],
                    'provider_tenant' => $profile['tenant'] ?? ''
                ], [
                    'user_id' => $user->id,
                    'provider_email' => $profile['email'] ?? null,
                    'provider_username' => $profile['username'] ?? null,
                    'updated_at' => time()
                ]);
            });
            $service->forgetTicket($ticket);
            return response(['data' => true]);
        });
    }

    private function consumeTicket(string $ticket, Request $request, OAuthService $service)
    {
        return $service->withTicketLock($ticket, function () use ($ticket, $request, $service) {
            $payload = $service->ticket($ticket);
            if (!$payload) abort(422, 'OAuth ticket is invalid or expired');
            $profile = $payload['profile'];
            $identity = OAuthIdentity::where('provider', $profile['provider'])
                ->where('provider_subject', $profile['subject'])
                ->where('provider_tenant', $profile['tenant'] ?? '')
                ->first();
            if ($identity) {
                $user = User::find($identity->user_id);
                if ($user) {
                    if ($user->banned) abort(403, 'Your account has been suspended');
                    $this->touchIdentity($identity, $profile);
                    $service->forgetTicket($ticket);
                    return $this->loginResponse($user, $request);
                }
                // Admin user deletion (delUser/allDel) does not cascade to
                // this table, so a mapping can outlive its user -- and it
                // would pin this provider identity to a 403 forever. Drop the
                // orphan and fall through to registration as a fresh account.
                $identity->delete();
            }

            $isTelegram = ($profile['provider'] ?? '') === 'telegram';
            $isGithub = ($profile['provider'] ?? '') === 'github';
            $email = strtolower(trim((string)($profile['email'] ?? '')));
            if ($isTelegram) {
                // Telegram is the verified identity for this account. The
                // synthetic email only satisfies v2_user's existing unique
                // column and must never be used for account linking.
                $email = $service->telegramAccountEmail((string)$profile['subject']);
                $profile['email'] = $email;
                $profile['verified_email'] = true;
                $service->updateTicketProfile($ticket, $profile);
            } elseif ($isGithub) {
                // Operator requirement: GitHub accounts never carry the real
                // GitHub email into the panel -- they get the synthetic
                // <email-local>_<username>@github.io address, with the same
                // never-links-accounts semantics as the Telegram placeholder.
                $email = $service->githubAccountEmail($profile);
                $profile['email'] = $email;
                $profile['verified_email'] = true;
                $service->updateTicketProfile($ticket, $profile);
            } elseif (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($profile['verified_email'])) {
                $email = strtolower(trim((string)$request->input('email', $email)));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return response(['data' => [
                        'requires_email' => true,
                        'ticket' => $ticket,
                        'provider' => $profile['provider']
                    ]]);
                }
                $this->validateEmailVerification($request, $email);
                $profile['email'] = $email;
                $profile['verified_email'] = true;
                $service->updateTicketProfile($ticket, $profile);
            }
            if (User::where('email', $email)->exists()) {
                if ($isTelegram) {
                    // Never let a Telegram authorization claim an existing
                    // account through the internal placeholder email.
                    abort(409, 'Telegram account cannot be linked to an existing account');
                }
                if ($isGithub) {
                    // Same rule for the GitHub placeholder: a collision means
                    // another GitHub account produced the same combination (or
                    // a lost identity row) -- never a licence to take over.
                    abort(409, 'GitHub account cannot be linked to an existing account');
                }
                return response(['data' => [
                    'link_required' => true,
                    'ticket' => $ticket,
                    'provider' => $profile['provider'],
                    'email' => $email
                ]]);
            }

            $requirements = $this->registrationRequirements($request, $isTelegram);
            if ($requirements) {
                return response(['data' => [
                    'registration_required' => true,
                    'ticket' => $ticket,
                    'provider' => $profile['provider'],
                    'email' => $email,
                    'requirements' => $requirements
                ]]);
            }

            $this->validateRegistration($request, $email, $profile, $isTelegram);
            $user = $this->createUser($request, $email, $profile);
            $service->forgetTicket($ticket);
            return $this->loginResponse($user, $request);
        });
    }

    private function registrationRequirements(Request $request, bool $isTelegram = false): array
    {
        $requirements = [];

        if ((int)config('v2board.invite_force', 0) && trim((string)$request->input('invite_code', '')) === '') {
            $requirements[] = 'invite_code';
        }
        if (!$isTelegram && (int)config('v2board.recaptcha_enable', 0) && trim((string)$request->input('recaptcha_data', '')) === '') {
            $requirements[] = 'recaptcha';
        }
        // No arithmetic here: every completion in this controller already
        // authenticated against a provider (GitHub/Google/Telegram).
        // The bot check stays on the plain email path (AuthController::register).

        return $requirements;
    }

    private function validateRegistration(Request $request, string $email, array $profile, bool $isTelegram = false): void
    {
        $rateKey = CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip());
        $registerCount = (int)Cache::get($rateKey, 0);
        if ((int)config('v2board.register_limit_by_ip_enable', 0)
            && $registerCount >= (int)config('v2board.register_limit_count', 3)) {
            abort(429, __('Register frequently, please try again after :minute minute', [
                'minute' => config('v2board.register_limit_expire', 60)
            ]));
        }
        if ((int)config('v2board.stop_register', 0)) abort(403, __('Registration has closed'));
        // 占位邮箱（telegram/github）不适用面向真实邮箱的门槛：白名单、gmail 别名
        // 限制、验证码都以「用户拥有该邮箱」为前提，占位邮箱谁都不拥有。
        $syntheticEmail = $isTelegram || ($profile['provider'] ?? '') === 'github';
        if (!$isTelegram && (int)config('v2board.recaptcha_enable', 0)) {
            $result = (new ReCaptcha(config('v2board.recaptcha_key')))->verify($request->input('recaptcha_data'));
            if (!$result->isSuccess()) abort(422, __('Invalid code is incorrect'));
        }
        if (!$syntheticEmail && (int)config('v2board.email_whitelist_enable', 0)
            && !Helper::emailSuffixVerify($email, config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))) {
            abort(422, __('Email suffix is not in the Whitelist'));
        }
        if (!$syntheticEmail && (int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $email)[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) abort(422, __('Gmail alias is not supported'));
        }
        if ((int)config('v2board.invite_force', 0) && !$request->input('invite_code')) {
            abort(422, __('You must use the invitation code to register'));
        }
        if (!$syntheticEmail && empty($profile['verified_email'])) {
            $this->validateEmailVerification($request, $email);
        }
    }

    private function validateEmailVerification(Request $request, string $email): void
    {
        $code = (string)$request->input('email_code', '');
        if (!preg_match('/^\d{6}$/', $code)) abort(422, __('Incorrect email verification code'));
        $cached = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', strtolower(trim($email))));
        if ($cached === null || !hash_equals((string)$cached, $code)) {
            abort(422, __('Incorrect email verification code'));
        }
    }

    private function createUser(Request $request, string $email, array $profile): User
    {
        $now = time();
        $user = DB::transaction(function () use ($request, $email, $profile, $now) {
            $user = new User();
            $user->email = $email;
            $user->password = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $user->uuid = Helper::guid(true);
            $user->token = Helper::guid();
            // 不打 password_reset_required：这条路径的密码是上面 random_bytes(32)
            // 生成的，从未被用户设置过，「自设密码有撞库风险」的提醒对它是谎言 ——
            // Telegram 注册的账号甚至根本无从知道自己的密码。列默认 0 即「合规」。
            $invite = null;
            if ($request->input('invite_code')) {
                $invite = InviteCode::where('code', $request->input('invite_code'))->where('status', 0)->lockForUpdate()->first();
                if (!$invite && (int)config('v2board.invite_force', 0)) abort(422, __('Invalid invitation code'));
                if ($invite) {
                    $user->invite_user_id = $invite->user_id ?: null;
                    if (!(int)config('v2board.invite_never_expire', 0)) $invite->status = 1;
                }
            }
            if ((int)config('v2board.try_out_plan_id', 0)) {
                $plan = Plan::find(config('v2board.try_out_plan_id'));
                if ($plan) {
                    $user->transfer_enable = $plan->transfer_enable * 1073741824;
                    $user->device_limit = $plan->device_limit;
                    $user->plan_id = $plan->id;
                    $user->group_id = $plan->group_id;
                    $user->expired_at = $now + (config('v2board.try_out_hour', 1) * 3600);
                    $user->speed_limit = $plan->speed_limit;
                }
            }
            TokenRotationContext::using('oauth_register', function () use ($user) {
                if (!$user->save()) abort(500, __('Register failed'));
            });
            if ($invite) $invite->save();
            OAuthIdentity::create([
                'user_id' => $user->id,
                'provider' => $profile['provider'],
                'provider_subject' => $profile['subject'],
                'provider_tenant' => $profile['tenant'] ?? '',
                'provider_email' => $profile['email'] ?? $email,
                'provider_username' => $profile['username'] ?? null,
                'created_at' => $now,
                'updated_at' => $now
            ]);
            return $user;
        });
        // This save runs after the registration transaction has committed, so
        // anything it throws turns "account created" into an error response --
        // the user sees a failed login while the row exists. Hence the
        // try/catch: the write is cosmetic bookkeeping and must never take
        // the login response down with it. The ip2long() guard is a data
        // fix, not a crash fix: for an IPv6 client it returns false, which
        // Laravel's prepareBindings() coerces to int 0 -- silently recording
        // every IPv6 registration as 0.0.0.0. NULL is the honest value an
        // int(11) IPv4 column can hold for an IPv6 address.
        try {
            $ip = ip2long((string)$request->ip());
            $user->last_login_at = $now;
            $user->last_login_ip = $ip === false ? null : $ip;
            $user->save();
        } catch (\Throwable $e) {
            report($e);
        }
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $key = CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip());
            Cache::put($key, (int)Cache::get($key, 0) + 1, (int)config('v2board.register_limit_expire', 60) * 60);
        }
        if (empty($profile['verified_email']) || (int)config('v2board.email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        }
        return $user->fresh();
    }

    private function touchIdentity(OAuthIdentity $identity, array $profile): void
    {
        $identity->provider_email = $profile['email'] ?? $identity->provider_email;
        $identity->provider_username = $profile['username'] ?? $identity->provider_username;
        $identity->updated_at = time();
        $identity->save();
    }

    private function loginResponse(User $user, Request $request)
    {
        $twoFactor = (new TwoFactorService())->issueLoginResult($user, $request);
        if ($twoFactor) return response(['data' => $twoFactor]);
        return response(['data' => (new AuthService($user))->generateAuthData($request)]);
    }
}
