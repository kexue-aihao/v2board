<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\SubscribeAuditRetentionService;
use App\Services\TelegramBindingService;
use App\Services\TelegramService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class ConfigController extends Controller
{
    public function getEmailTemplate()
    {
        $path = resource_path('views/mail/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return response([
            'data' => $files
        ]);
    }

    public function getThemeTemplate()
    {
        $path = public_path('theme/');
        $files = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
        return response([
            'data' => $files
        ]);
    }

    public function testSendMail(Request $request)
    {
        $obj = new SendEmailJob([
            'email' => $request->user['email'],
            'subject' => 'This is v2board test email',
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'content' => 'This is v2board test email',
                'url' => config('v2board.app_url')
            ]
        ]);
        return response([
            'data' => true,
            'log' => $obj->handle()
        ]);
    }

    public function setTelegramWebhook(Request $request)
    {
        $token = $request->input('telegram_bot_token', config('v2board.telegram_bot_token'));
        $secretToken = bin2hex(random_bytes(32));
        $hookUrl = secure_url('/api/v1/guest/telegram/webhook');
        $telegramService = new TelegramService($token);
        $telegramService->getMe();
        $telegramService->setWebhook($hookUrl, ['secret_token' => $secretToken]);
        $config = config('v2board');
        $config['telegram_webhook_secret'] = $secretToken;
        if (!\Illuminate\Support\Facades\File::put(base_path() . '/config/v2board.php', "<?php\n return " . var_export($config, true) . " ;", LOCK_EX)) {
            abort(500, '保存Webhook密钥失败');
        }
        return response([
            'data' => true
        ]);
    }

    public function fetch(Request $request)
    {
        $key = $request->input('key');
        $data = [
            'ticket' => [
                'ticket_status' => config('v2board.ticket_status', 0)
            ],
            'deposit' => [
                'deposit_bounus' => config('v2board.deposit_bounus', [])
            ],
            'invite' => [
                'invite_force' => (int)config('v2board.invite_force', 0),
                'invite_commission' => config('v2board.invite_commission', 10),
                'invite_gen_limit' => config('v2board.invite_gen_limit', 5),
                'invite_never_expire' => config('v2board.invite_never_expire', 0),
                'commission_first_time_enable' => config('v2board.commission_first_time_enable', 1),
                'commission_auto_check_enable' => config('v2board.commission_auto_check_enable', 1),
                'commission_withdraw_limit' => config('v2board.commission_withdraw_limit', 100),
                'commission_withdraw_method' => config('v2board.commission_withdraw_method', Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT),
                'withdraw_close_enable' => config('v2board.withdraw_close_enable', 0),
                'commission_distribution_enable' => config('v2board.commission_distribution_enable', 0),
                'commission_distribution_l1' => config('v2board.commission_distribution_l1'),
                'commission_distribution_l2' => config('v2board.commission_distribution_l2'),
                'commission_distribution_l3' => config('v2board.commission_distribution_l3')
            ],
            'site' => [
                'logo' => config('v2board.logo'),
                'force_https' => (int)config('v2board.force_https', 0),
                'stop_register' => (int)config('v2board.stop_register', 0),
                'site_status' => config('v2board.site_status', 'normal'),
                'site_status_title' => config('v2board.site_status_title'),
                'site_status_message' => config('v2board.site_status_message'),
                'site_status_recovery_at' => config('v2board.site_status_recovery_at'),
                'app_name' => config('v2board.app_name', 'V2Board'),
                'app_description' => config('v2board.app_description', 'V2Board is best!'),
                'app_url' => config('v2board.app_url'),
                'subscribe_url' => config('v2board.subscribe_url'),
                'subscribe_path' => config('v2board.subscribe_path'),
                'try_out_plan_id' => (int)config('v2board.try_out_plan_id', 0),
                'try_out_hour' => (int)config('v2board.try_out_hour', 1),
                'tos_url' => config('v2board.tos_url'),
                'currency' => config('v2board.currency', 'CNY'),
                'currency_symbol' => config('v2board.currency_symbol', '¥'),
            ],
            'subscribe' => [
                'plan_change_enable' => (int)config('v2board.plan_change_enable', 1),
                'reset_traffic_method' => (int)config('v2board.reset_traffic_method', 0),
                'surplus_enable' => (int)config('v2board.surplus_enable', 1),
                'allow_new_period' => (int)config('v2board.allow_new_period', 0),
                'multi_subscription_enable' => (int)config('v2board.multi_subscription_enable', 0),
                'new_order_event_id' => (int)config('v2board.new_order_event_id', 0),
                'renew_order_event_id' => (int)config('v2board.renew_order_event_id', 0),
                'change_order_event_id' => (int)config('v2board.change_order_event_id', 0),
                'show_info_to_server_enable' => (int)config('v2board.show_info_to_server_enable', 0),
                'show_subscribe_method' => (int)config('v2board.show_subscribe_method', 0),
                'show_subscribe_expire' => (int)config('v2board.show_subscribe_expire', 5),
            ],
            'frontend' => [
                'frontend_theme' => config('v2board.frontend_theme', 'v2board'),
                'frontend_theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
                'frontend_theme_header' => config('v2board.frontend_theme_header', 'dark'),
                'frontend_theme_color' => config('v2board.frontend_theme_color', 'default'),
                'frontend_background_url' => config('v2board.frontend_background_url'),
            ],
            'server' => [
                'server_api_url' => config('v2board.server_api_url'),
                'server_token_configured' => !empty(config('v2board.server_token')),
                'server_pull_interval' => config('v2board.server_pull_interval', 60),
                'server_push_interval' => config('v2board.server_push_interval', 60),
                'server_node_report_min_traffic' => config('v2board.server_node_report_min_traffic', 0),
                'server_device_online_min_traffic' => config('v2board.server_device_online_min_traffic', 0),
                'device_limit_mode' => config('v2board.device_limit_mode', 0)
            ],
            'email' => [
                'email_template' => config('v2board.email_template', 'default'),
                'email_host' => config('v2board.email_host'),
                'email_port' => config('v2board.email_port'),
                'email_username' => config('v2board.email_username'),
                'email_password' => config('v2board.email_password'),
                'email_encryption' => config('v2board.email_encryption'),
                'email_from_address' => config('v2board.email_from_address')
            ],
            'rewards' => [
                'reward_enable' => (int)config('v2board.reward_enable', 1),
                'reward_daily_game_limit' => (int)config('v2board.reward_daily_game_limit', 3),
                'reward_dice_six_gb' => (int)config('v2board.reward_dice_six_gb', 10),
                'reward_dice_win_face' => (int)config('v2board.reward_dice_win_face', 6),
                'reward_slots_jackpot_rate' => (int)config('v2board.reward_slots_jackpot_rate', 100),
                'reward_slots_triple_gb' => (int)config('v2board.reward_slots_triple_gb', 10),
                'reward_poker_winner_gb' => (int)config('v2board.reward_poker_winner_gb', 5),
                'reward_group_enable' => (int)config('v2board.reward_group_enable', 0)
            ],
            'telegram' => [
                'telegram_bot_enable' => config('v2board.telegram_bot_enable', 0),
                'telegram_bot_token_configured' => !empty(config('v2board.telegram_bot_token')),
                'telegram_discuss_id' => config('v2board.telegram_discuss_id'),
                'telegram_discuss_link' => config('v2board.telegram_discuss_link'),
                'telegram_subscription_binding_enable' => (int)config('v2board.telegram_subscription_binding_enable', 0),
                'telegram_binding_check_interval' => (int)config('v2board.telegram_binding_check_interval', 300)
            ],
            'app' => [
                'windows_version' => config('v2board.windows_version'),
                'windows_download_url' => config('v2board.windows_download_url'),
                'macos_version' => config('v2board.macos_version'),
                'macos_download_url' => config('v2board.macos_download_url'),
                'android_version' => config('v2board.android_version'),
                'android_download_url' => config('v2board.android_download_url')
            ],
            'safe' => [
                'email_verify' => (int)config('v2board.email_verify', 0),
                // 仅第三方注册：与 email_verify / oauth_* 同在 safe 组，admin 注册设置区消费
                'oauth_register_only' => (int)config('v2board.oauth_register_only', 0),
                'safe_mode_enable' => (int)config('v2board.safe_mode_enable', 0),
                'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))),
                'email_whitelist_enable' => (int)config('v2board.email_whitelist_enable', 0),
                'email_whitelist_suffix' => config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT),
                'email_gmail_limit_enable' => config('v2board.email_gmail_limit_enable', 0),
                'recaptcha_enable' => (int)config('v2board.recaptcha_enable', 0),
                'arithmetic_verification_enable' => (int)config('v2board.arithmetic_verification_enable', 0),
                'reseller_enable' => (int)config('v2board.reseller_enable', 0),
                'reseller_allowed_payment_drivers' => array_values((array)config('v2board.reseller_allowed_payment_drivers', [])),
                'payment_secure_driver_allowlist' => array_values((array)config('v2board.payment_secure_driver_allowlist', [])),
                'oauth_google_enable' => (int)config('v2board.oauth_google_enable', 0),
                'oauth_google_client_id' => config('v2board.oauth_google_client_id'),
                'oauth_google_client_secret_configured' => (bool)config('v2board.oauth_google_client_secret'),
                'oauth_google_redirect_uri' => config('v2board.oauth_google_redirect_uri'),
                'oauth_github_enable' => (int)config('v2board.oauth_github_enable', 0),
                'oauth_github_client_id' => config('v2board.oauth_github_client_id'),
                'oauth_github_client_secret_configured' => (bool)config('v2board.oauth_github_client_secret'),
                'oauth_github_redirect_uri' => config('v2board.oauth_github_redirect_uri'),
                'oauth_telegram_enable' => (int)config('v2board.oauth_telegram_enable', 0),
                'oauth_telegram_login_domain' => config('v2board.oauth_telegram_login_domain'),
                'oauth_telegram_bot_username' => config('v2board.oauth_telegram_bot_username'),
                'recaptcha_key' => config('v2board.recaptcha_key'),
                'recaptcha_site_key' => config('v2board.recaptcha_site_key'),
                'register_limit_by_ip_enable' => (int)config('v2board.register_limit_by_ip_enable', 0),
                'register_limit_count' => config('v2board.register_limit_count', 3),
                'register_limit_expire' => config('v2board.register_limit_expire', 60),
                'password_limit_enable' => (int)config('v2board.password_limit_enable', 1),
                'password_limit_count' => config('v2board.password_limit_count', 5),
                'password_limit_expire' => config('v2board.password_limit_expire', 60),
                'admin_2fa_force_enable' => (int)config('v2board.admin_2fa_force_enable', 0),
                'subscribe_audit_retention_days' => (int)config(
                    'v2board.subscribe_audit_retention_days',
                    SubscribeAuditRetentionService::DEFAULT_RETENTION_DAYS
                )
            ]
        ];
        if ($key && isset($data[$key])) {
            return response([
                'data' => [
                    $key => $data[$key]
                ]
            ]);
        };
        // TODO: default should be in Dict
        return response([
            'data' => $data
        ]);
    }

    public function save(ConfigSave $request)
    {
        $data = $request->validated();
        $previousTelegramBindingEnabled = (int)config('v2board.telegram_subscription_binding_enable', 0);
        $previousTelegramDiscussId = trim((string)config('v2board.telegram_discuss_id', ''));
        foreach (['google', 'github'] as $provider) {
            $secret = 'oauth_' . $provider . '_client_secret';
            if (array_key_exists($secret, $data) && trim((string)$data[$secret]) === '') {
                unset($data[$secret]);
            }
        }
        if (array_key_exists('payment_secure_driver_allowlist', $data)) {
            $data['payment_secure_driver_allowlist'] = array_values(array_intersect(
                ['BTCPay', 'Coinbase'],
                (array)$data['payment_secure_driver_allowlist']
            ));
        }
        if ((int)($data['admin_2fa_force_enable'] ?? config('v2board.admin_2fa_force_enable', 0)) === 1) {
            $hasUnprotectedStaff = User::where(function ($query) {
                $query->where('is_admin', 1)->orWhere('is_staff', 1);
            })->whereNotIn('id', UserTwoFactor::where('enabled', 1)->pluck('user_id'))->exists();
            if ($hasUnprotectedStaff) {
                abort(422, __('请先为全部管理员和员工绑定二步验证'));
            }
        }
        $config = config('v2board');
        foreach (ConfigSave::RULES as $k => $v) {
            if (!in_array($k, array_keys(ConfigSave::RULES))) {
                unset($config[$k]);
                continue;
            }
            if (array_key_exists($k, $data)) {
                $config[$k] = $data[$k];
            }
        }
        $telegramBindingEnabled = array_key_exists('telegram_subscription_binding_enable', $data)
            ? (int)$data['telegram_subscription_binding_enable']
            : $previousTelegramBindingEnabled;
        $telegramDiscussId = array_key_exists('telegram_discuss_id', $data)
            ? trim((string)$data['telegram_discuss_id'])
            : $previousTelegramDiscussId;
        $path = base_path() . '/config/v2board.php';
        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (!File::put($tempPath, "<?php\n return " . var_export($config, 1) . " ;", LOCK_EX)) {
            abort(500, __('修改失败'));
        }
        @chmod($tempPath, 0644);
        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            abort(500, __('修改失败'));
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        if ($previousTelegramBindingEnabled === 1
            && ($telegramBindingEnabled === 0 || $telegramDiscussId !== $previousTelegramDiscussId)) {
            (new TelegramBindingService())->invalidateAll(
                $telegramBindingEnabled === 0 ? 'binding_feature_disabled' : 'binding_group_changed'
            );
        }
        if (function_exists('opcache_reset')) {
            if (opcache_reset() === false) {
                abort(500, __('缓存清除失败，请卸载或检查opcache配置状态'));
            }
        }
        Artisan::call('config:cache');
        if(Cache::has('WEBMANPID')) {
            $pid = Cache::get('WEBMANPID');
            Cache::forget('WEBMANPID');
            return response([
                'data' => posix_kill($pid, 15)
            ]);
        }
        return response([
            'data' => true
        ]);
    }
}
