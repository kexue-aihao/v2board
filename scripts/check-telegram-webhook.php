<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ((int)config('v2board.telegram_bot_enable', 0) !== 1) {
    echo "Telegram webhook check skipped: bot is disabled.\n";
    exit(0);
}

try {
    (new App\Services\TelegramWebhookService())->verify(new Curl\Curl());
} catch (Throwable $exception) {
    $detail = preg_match('/^HTTP [0-9]+$/', $exception->getMessage()) ? ' (' . $exception->getMessage() . ')' : '';
    fwrite(STDERR, "Telegram webhook check failed{$detail}. Check the callback host's /api proxy, TLS and worker configuration; this upgrade is not healthy.\n");
    exit(1);
}
echo "Telegram webhook public endpoint and active worker secret verified.\n";
