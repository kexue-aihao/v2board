<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Utils\Dict;
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
                'app_description' => config('v2board.app_description'),
                'app_url' => config('v2board.app_url'),
                'logo' => config('v2board.logo'),
                'frontend_theme_color' => is_array($themeConfig) ? ($themeConfig['theme_color'] ?? 'default') : 'default'
            ]
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
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
