<?php

use App\Services\TelegramService;
use App\Services\TelegramWebhookService;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if ((int)config('v2board.telegram_bot_enable', 0) !== 1) {
    echo "Telegram webhook refresh skipped: bot is disabled.\n";
    exit(0);
}

$token = trim((string)config('v2board.telegram_bot_token', ''));
if ($token === '') {
    fwrite(STDERR, "Telegram bot is enabled but its token is missing.\n");
    exit(1);
}
try {
    (new TelegramWebhookService())->refresh(new TelegramService($token));
    echo "Telegram webhook refreshed; existing callback host and secret preserved.\n";
} catch (\Throwable $error) {
    // Do not print request URLs: Bot API URLs contain the bot token.
    fwrite(STDERR, "Telegram webhook refresh failed. Check connectivity to api.telegram.org and the saved callback configuration.\n");
    exit(1);
}
