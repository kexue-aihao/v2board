<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ConfigSave;
use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Models\UserTwoFactor;
use App\Services\SubscribeAccountRiskService;
use App\Services\SubscribeAuditRetentionService;
use App\Services\SiteStatusService;
use App\Services\TelegramBindingService;
use App\Services\TelegramService;
use App\Services\PaymentReturnUrlService;
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
        $token = trim((string)$request->input('telegram_bot_token', config('v2board.telegram_bot_token')));
        $secretToken = bin2hex(random_bytes(32));
        // 注册到「当前请求的域名」，也就是管理员此刻打开后台用的那个域名 —— 上游 v2board 的
        // 原始行为（backup / master / release 三个分支都是这一行）。
        //
        // 这个域名从哪个来、要不要改成固定值，为它来回翻过两次，结论记在这里：
        //
        // 前台域名与后端域名指向同一个 vhost（同一个 root、同一套 webman）时，注册到其中
        // 任何一个功能上等价，都能收到投递。那次真正的故障是配置缓存里的 webhook secret
        // 落后一代导致的全量 401（见本方法结尾），跟域名无关 —— 别再把「机器人不回话」
        // 往域名上归因。
        //
        // 唯一的实际约束：这个按钮必须在「对外可达、且 /api 能落到后端」的域名下点。若后台
        // 域名只对内开放，从它注册上去才会真的静默失效。
        //
        // 若哪天要求 webhook 必须固定落在某个域名（不随点击位置变），把这一行换成
        // `rtrim(config('v2board.app_url'), '/') . '/api/v1/guest/telegram/webhook'` 即可。
        // 注意：那样一旦 app_url 指向的不是后端域名，就会重演「注册到一个到不了后端的域名」。
        $hookUrl = secure_url('/api/v1/guest/telegram/webhook');
        $telegramService = new TelegramService($token);
        $telegramService->getMe();
        $telegramService->setWebhook($hookUrl, ['secret_token' => $secretToken]);
        $config = config('v2board');
        // 必须把「这次真正用来注册 webhook 的 token」一并落盘：config('v2board') 是磁盘上
        // 已经生效的那份配置，而管理员完全可能是在表单里改完 token、还没点保存就直接点
        // 「一键设置 webhook」。不写回的话会出现：webhook 注册在新 token 上、应用读到的
        // 却是旧 token（甚至为空）—— 表现就是「刷新后 token 变空、机器人不生效」。
        $config['telegram_bot_token'] = $token;
        $config['telegram_webhook_secret'] = $secretToken;

        // 写盘手法与 ConfigController::save / SubscribeCleanGatewayController::saveConfig 一致：
        // 临时文件 + 原子 rename。直接覆写有写到一半留下语法错误文件的风险，那会把整站打死。
        $path = base_path() . '/config/v2board.php';
        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (!File::put($tempPath, "<?php\n return " . var_export($config, true) . " ;", LOCK_EX)) {
            abort(500, '保存Webhook密钥失败');
        }
        @chmod($tempPath, 0644);
        if (!@rename($tempPath, $path)) {
            @unlink($tempPath);
            abort(500, '保存Webhook密钥失败');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        Artisan::call('config:cache');

        // 下面这段不能省，删了就是线上事故：webman 是常驻进程，config() 取的是启动快照。
        // 把新 secret 写进 config/v2board.php 并重建 bootstrap/cache/config.php 之后，**已经
        // 在服务的 worker 仍然拿着旧 secret**（TrafficRewardService::reloadWebman 的注释里
        // 写过同一件事）。于是 Telegram 用 setWebhook 时登记的新 secret 投递、worker 用旧
        // secret 比对 —— 每一条更新都 401，而 setWebhook 返回成功、getWebhookInfo 也只给一句
        // "Wrong response from the webhook: 401 Unauthorized"，看上去像域名/网络问题，极难定位。
        // 2026-10-03 的事故就是这里：配置缓存里的 secret 比文件里落后一代，投递连续 401、
        // 积压 11 条，而 webhook 地址本身完全可达。
        if (Cache::has('WEBMANPID')) {
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
                'server_token' => config('v2board.server_token'),
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
                'reward_dice_daily_limit' => (int)config('v2board.reward_dice_daily_limit', 0),
                'reward_dice_enable' => (int)config('v2board.reward_dice_enable', config('v2board.reward_enable', 1)),
                'reward_dice_win_probability' => number_format((float)config('v2board.reward_dice_win_probability', config('v2board.reward_dice_odds', 10)), 2, '.', ''),
                'reward_dice_payout_multiplier' => number_format((float)config('v2board.reward_dice_payout_multiplier', 1), 2, '.', ''),
                'reward_dice_odds' => (int)config('v2board.reward_dice_odds', 10),
                'reward_dice_win_face' => (int)config('v2board.reward_dice_win_face', 6),
                'reward_slots_daily_limit' => (int)config('v2board.reward_slots_daily_limit', 0),
                'reward_slots_enable' => (int)config('v2board.reward_slots_enable', config('v2board.reward_enable', 1)),
                'reward_slots_win_probability' => number_format((float)config('v2board.reward_slots_win_probability', config('v2board.reward_slots_odds', 10)), 2, '.', ''),
                'reward_slots_payout_multiplier' => number_format((float)config('v2board.reward_slots_payout_multiplier', 1), 2, '.', ''),
                'reward_slots_odds' => (int)config('v2board.reward_slots_odds', 10),
                'reward_slots_jackpot_rate' => (int)config('v2board.reward_slots_jackpot_rate', 100),
                'reward_group_enable' => (int)config('v2board.reward_group_enable', 0)
            ],
            'telegram' => [
                'telegram_bot_enable' => config('v2board.telegram_bot_enable', 0),
                'telegram_admin_operation_enable' => (int)config('v2board.telegram_admin_operation_enable', 0),
                'telegram_admin_operation_topic_id' => config('v2board.telegram_admin_operation_topic_id'),
                'telegram_account_binding_enable' => (int)config('v2board.telegram_account_binding_enable', 0),
                'telegram_register_enable' => (int)config('v2board.telegram_register_enable', 0),
                'telegram_register_code_delay' => (int)config('v2board.telegram_register_code_delay', 10),
                'telegram_bot_token_configured' => !empty(config('v2board.telegram_bot_token')),
                // 按运维要求**不打掩码**：直接回显完整 token，前端输入框因此能看到当前存的值。
                // 代价是任何能打开后台的人都能从这个响应里拿到密钥（备份分支原本就是明文回显）。
                // 要改回掩码：把下面两行换成只回 token 的前 6 位 + 后 4 位即可。
                'telegram_bot_token' => (string)config('v2board.telegram_bot_token', ''),
                'telegram_bot_token_preview' => (string)config('v2board.telegram_bot_token', ''),
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
                // 仅第三方注册：与 email_verify / oauth_* 同在 safe 组，admin 注册设置区消费
                'oauth_register_only' => (int)config('v2board.oauth_register_only', 0),
                'safe_mode_enable' => (int)config('v2board.safe_mode_enable', 0),
                'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))),
                'recaptcha_enable' => (int)config('v2board.recaptcha_enable', 0),
                'arithmetic_verification_enable' => (int)config('v2board.arithmetic_verification_enable', 0),
                'reseller_enable' => (int)config('v2board.reseller_enable', 0),
                'reseller_allowed_payment_drivers' => array_values((array)config('v2board.reseller_allowed_payment_drivers', [])),
                'payment_secure_driver_allowlist' => array_values((array)config('v2board.payment_secure_driver_allowlist', [])),
                'payment_return_url_allowlist' => array_values((array)config('v2board.payment_return_url_allowlist', [])),
                'oauth_telegram_enable' => (int)config('v2board.oauth_telegram_enable', 0),
                'oauth_google_enable' => (int)config('v2board.oauth_google_enable', 0),
                'oauth_google_client_id' => config('v2board.oauth_google_client_id'),
                'oauth_google_client_secret_configured' => (bool)config('v2board.oauth_google_client_secret'),
                'oauth_google_redirect_uri' => config('v2board.oauth_google_redirect_uri'),
                'oauth_github_enable' => (int)config('v2board.oauth_github_enable', 0),
                'oauth_github_client_id' => config('v2board.oauth_github_client_id'),
                'oauth_github_client_secret_configured' => (bool)config('v2board.oauth_github_client_secret'),
                'oauth_github_redirect_uri' => config('v2board.oauth_github_redirect_uri'),
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
                ),
                'subscribe_risk_notify_threshold' => (int)config(
                    'v2board.subscribe_risk_notify_threshold',
                    SubscribeAccountRiskService::DEFAULT_THRESHOLD
                )
            ]
        ];
        if (($request->user['admin_role'] ?? 'super') !== 'super') {
            unset($data['rewards']);
            foreach ($data as &$section) {
                if (is_array($section)) foreach (\App\Services\AdminAccessService::SECURITY_FIELDS as $field) unset($section[$field]);
            }
            unset($section);
        }
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

        // 通讯密钥留空即保留原值：输入框每次改动都会防抖自动保存，清空或编辑到一半时
        // 提交的空串经 ConvertEmptyStringsToNull 变 null 后 nullable 会放行，落盘就成了
        // server_token => NULL，所有节点鉴权失败。
        if (array_key_exists('server_token', $data) && trim((string)$data['server_token']) === '') {
            unset($data['server_token']);
        }
        // bot token 同理，而且这里更容易中招：配置接口刻意不回显密钥（只回一个布尔值），
        // 于是输入框每次打开都是空的，管理员只要保存一次配置就会提交空串 —— 旧行为会把
        // 已配置的 token 直接抹成 NULL，表现成「保存后 token 没了、机器人也不回话」。
        if (array_key_exists('telegram_bot_token', $data) && trim((string)$data['telegram_bot_token']) === '') {
            unset($data['telegram_bot_token']);
        }
        if (array_key_exists('reseller_allowed_payment_drivers', $data)) {
            $data['reseller_allowed_payment_drivers'] = array_values(array_filter(
                (array)$data['reseller_allowed_payment_drivers'],
                function ($driver) {
                    return $driver !== 'PaytaroQR';
                }
            ));
        }
        if (array_key_exists('payment_secure_driver_allowlist', $data)) {
            $data['payment_secure_driver_allowlist'] = array_values(array_intersect(
                ['BTCPay', 'Coinbase', 'PaytaroQR'],
                (array)$data['payment_secure_driver_allowlist']
            ));
        }
        if (array_key_exists('payment_return_url_allowlist', $data)) {
            $configuredOrigins = (array)$data['payment_return_url_allowlist'];
            $returnUrlService = new PaymentReturnUrlService();
            $normalizedOrigins = [];
            foreach ($configuredOrigins as $origin) {
                $origin = trim((string)$origin);
                if ($origin === '') {
                    continue;
                }
                $normalized = $returnUrlService->normalizeOrigin($origin);
                if ($normalized === null) {
                    abort(422, 'Payment return URL allowlist contains an invalid origin');
                }
                $normalizedOrigins[] = $normalized;
            }
            $data['payment_return_url_allowlist'] = $returnUrlService->normalizeAllowlist($normalizedOrigins);
        }
        if ((int)($data['admin_2fa_force_enable'] ?? config('v2board.admin_2fa_force_enable', 0)) === 1) {
            $hasUnprotectedStaff = User::where(function ($query) {
                $query->where('is_admin', 1)->orWhere('is_staff', 1);
            })->whereNotIn('id', UserTwoFactor::where('enabled', 1)->pluck('user_id'))->exists();
            if ($hasUnprotectedStaff) {
                abort(422, __('请先为全部管理员和员工绑定二步验证'));
            }
        }
        foreach (['dice', 'slots'] as $game) {
            $probability = 'reward_' . $game . '_win_probability';
            $legacyOdds = 'reward_' . $game . '_odds';
            if (!array_key_exists($probability, $data) && array_key_exists($legacyOdds, $data)) {
                $data[$probability] = $data[$legacyOdds];
            }
            unset($data[$legacyOdds]);
            if (array_key_exists($probability, $data)) {
                $data[$probability] = number_format((float)$data[$probability], 2, '.', '');
            }
            if (array_key_exists('reward_' . $game . '_payout_multiplier', $data)) {
                $data['reward_' . $game . '_payout_multiplier'] = number_format((float)$data['reward_' . $game . '_payout_multiplier'], 2, '.', '');
            }
        }
        $previousConfig = (array)config('v2board', []);
        $config = $previousConfig;
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
        SiteStatusService::sync($config);

        // Site status is consumed through SiteStatusService on every request,
        // so toggling maintenance mode must not stop a Webman process that may
        // not be managed by Supervisor and therefore would not restart itself.
        if (!SiteStatusService::onlyStatusChanges($previousConfig, $config) && Cache::has('WEBMANPID')) {
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
