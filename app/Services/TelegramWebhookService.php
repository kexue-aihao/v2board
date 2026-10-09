<?php

namespace App\Services;

use Curl\Curl;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/** Keep the deployed callback and the local secret in sync without changing hosts. */
class TelegramWebhookService
{
    public function refresh(TelegramService $telegram): string
    {
        $config = (array)config('v2board', []);
        $info = $telegram->getWebhookInfo()->result;
        // The callback registered at Telegram is authoritative for existing sites.
        // app_url can belong to an independent frontend and must not replace it.
        $url = trim((string)($info->url ?? ''));
        if ($url === '') $url = trim((string)($config['telegram_webhook_url'] ?? ''));
        if ($url === '') $url = rtrim((string)($config['app_url'] ?? ''), '/') . '/api/v1/guest/telegram/webhook';
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || ($parts['path'] ?? '') !== '/api/v1/guest/telegram/webhook') {
            throw new \RuntimeException('Telegram 回调地址无效，请在后台设置可公开访问的 HTTPS Webhook');
        }
        // Legacy access_token query parameters are superseded by the secret header.
        $url = 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $parts['path'];
        $secret = trim((string)($config['telegram_webhook_secret'] ?? ''));
        if ($secret === '') $secret = bin2hex(random_bytes(32));
        $config['telegram_webhook_url'] = $url;
        $config['telegram_webhook_secret'] = $secret;
        // Persist and rebuild before registering: restarted workers must load the
        // same secret Telegram will send. Never report a failed cache as success.
        $path = base_path('config/v2board.php');
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (!File::put($temporaryPath, "<?php\n return " . var_export($config, true) . " ;", LOCK_EX)) {
            throw new \RuntimeException('无法保存 Telegram 回调配置');
        }
        @chmod($temporaryPath, 0644);
        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('无法保存 Telegram 回调配置');
        }
        if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
        config(['v2board' => $config]);
        if (Artisan::call('config:cache') !== 0) throw new \RuntimeException('Telegram 回调配置缓存刷新失败');
        $telegram->setWebhook($url, ['secret_token' => $secret]);
        return $url;
    }

    public function verify(Curl $curl): void
    {
        $url = (string)config('v2board.telegram_webhook_url', '');
        $secret = (string)config('v2board.telegram_webhook_secret', '');
        if ($url === '' || $secret === '') throw new \RuntimeException('callback configuration is missing');
        // Check the public route and active worker's secret without consuming an
        // update_id, sending a message or dropping pending Telegram updates.
        try {
            $curl->setConnectTimeout(3);
            $curl->setTimeout(10);
            $curl->setHeader('X-Telegram-Bot-Api-Secret-Token', $secret);
            $curl->setHeader('Content-Type', 'application/json');
            $curl->post($url, '{}');
            $ok = !$curl->error && (int)$curl->httpStatusCode === 200
                && is_object($curl->response) && ($curl->response->data ?? null) === true;
            if (!$ok) throw new \RuntimeException('HTTP ' . (int)$curl->httpStatusCode);
        } finally {
            $curl->close();
        }
    }
}
