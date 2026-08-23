<?php

use App\Services\TelegramService;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if ((int)config('v2board.telegram_bot_enable', 0) !== 1) {
    echo "Telegram webhook refresh skipped: bot is disabled.\n";
    exit(0);
}

$token = trim((string)config('v2board.telegram_bot_token', ''));
$secret = trim((string)config('v2board.telegram_webhook_secret', ''));
$appUrl = rtrim((string)config('v2board.app_url', ''), '/');
if ($token === '' || $appUrl === '') {
    echo "Telegram webhook refresh skipped: token or app URL is missing.\n";
    exit(0);
}

$url = $appUrl . '/api/v1/guest/telegram/webhook';
$parts = parse_url($url);
if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
    fwrite(STDERR, "Telegram webhook refresh requires an HTTPS app URL.\n");
    exit(1);
}

if ($secret === '') {
    $secret = bin2hex(random_bytes(32));
    $config = config('v2board');
    $config['telegram_webhook_secret'] = $secret;
    if (!Illuminate\Support\Facades\File::put(base_path('config/v2board.php'), "<?php\n return " . var_export($config, true) . " ;", LOCK_EX)) {
        fwrite(STDERR, "Unable to persist the Telegram webhook secret.\n");
        exit(1);
    }
}

(new TelegramService())->setWebhook($url, ['secret_token' => $secret]);
echo "Telegram webhook refreshed with callback updates enabled.\n";
