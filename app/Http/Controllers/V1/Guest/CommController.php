<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\ArithmeticVerificationService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CommController extends Controller
{
    public function config()
    {
        $theme = config('v2board.frontend_theme', 'default');
        $themeConfig = [];
        $themeConfigFile = base_path("config/theme/{$theme}.php");
        if (is_file($themeConfigFile)) {
            $loadedThemeConfig = require $themeConfigFile;
            if (is_array($loadedThemeConfig)) {
                $themeConfig = $loadedThemeConfig;
            }
        }
        if (!$themeConfig) {
            $themeConfig = config("theme.{$theme}", []);
        }

        $siteStatus = (string)config('v2board.site_status', 'normal');
        if (!in_array($siteStatus, ['normal', 'maintenance', 'shutdown'], true)) {
            $siteStatus = 'normal';
        }

        $siteStatusTitle = trim(strip_tags((string)config('v2board.site_status_title', '')));
        $siteStatusMessage = trim(strip_tags((string)config('v2board.site_status_message', '')));
        $siteStatusRecoveryAt = config('v2board.site_status_recovery_at');
        $siteStatusRecoveryAt = $siteStatusRecoveryAt === null || $siteStatusRecoveryAt === ''
            ? null
            : (int)$siteStatusRecoveryAt;

        return response([
            'data' => [
                'tos_url' => config('v2board.tos_url'),
                'is_email_verify' => (int)config('v2board.email_verify', 0) ? 1 : 0,
                'is_invite_force' => (int)config('v2board.invite_force', 0) ? 1 : 0,
                'email_whitelist_suffix' => (int)config('v2board.email_whitelist_enable', 0)
                    ? $this->getEmailSuffix()
                    : 0,
                'is_recaptcha' => (int)config('v2board.recaptcha_enable', 0) ? 1 : 0,
                'recaptcha_site_key' => config('v2board.recaptcha_site_key'),
                'is_arithmetic_verification' => (int)config('v2board.arithmetic_verification_enable', 0) ? 1 : 0,
                // 仅第三方注册开关：主题注册页据此隐藏邮箱注册表单，只留 OAuth 区
                'oauth_register_only' => (int)config('v2board.oauth_register_only', 0) ? 1 : 0,
                'oauth' => [
                    'google' => (int)config('v2board.oauth_google_enable', 0) === 1,
                    'github' => (int)config('v2board.oauth_github_enable', 0) === 1,
                    'telegram' => (int)config('v2board.oauth_telegram_enable', 0) === 1,
                    'telegram_bot_username' => config('v2board.oauth_telegram_bot_username'),
                    'telegram_login_domain' => config('v2board.oauth_telegram_login_domain')
                ],
                'site_status' => [
                    'mode' => $siteStatus,
                    'title' => $siteStatusTitle ?: ($siteStatus === 'shutdown' ? __('Service temporarily suspended') : __('Service under maintenance')),
                    'message' => $siteStatusMessage ?: __('The system is undergoing routine processing, please try again later.'),
                    'recovery_at' => $siteStatusRecoveryAt,
                    'server_time' => time(),
                    'support_url' => config('v2board.telegram_discuss_link')
                ],
                'app_description' => config('v2board.app_description'),
                'app_url' => config('v2board.app_url'),
                'logo' => config('v2board.logo'),
                'frontend_theme_color' => is_array($themeConfig) ? ($themeConfig['theme_color'] ?? 'default') : 'default'
            ]
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function arithmetic(Request $request)
    {
        if (!(int)config('v2board.arithmetic_verification_enable', 0)) {
            return response(['data' => ['enabled' => false]]);
        }

        try {
            return response([
                'data' => (new ArithmeticVerificationService())->issue((string)$request->ip())
            ]);
        } catch (\Throwable $e) {
            report($e);
            abort(503, __('Arithmetic verification is temporarily unavailable'));
        }
    }

    public function verifyArithmetic(Request $request)
    {
        if (!(int)config('v2board.arithmetic_verification_enable', 0)) {
            return response(['data' => ['correct' => true, 'verified' => true]]);
        }

        try {
            return response([
                'data' => (new ArithmeticVerificationService())->verify(
                    (string)$request->input('challenge_id'),
                    $request->input('answer'),
                    (string)$request->ip()
                )
            ]);
        } catch (\Throwable $e) {
            report($e);
            abort(503, __('Arithmetic verification is temporarily unavailable'));
        }
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
