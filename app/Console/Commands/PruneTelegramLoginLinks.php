<?php

namespace App\Console\Commands;

use App\Services\TelegramLoginLinkService;
use Illuminate\Console\Command;

class PruneTelegramLoginLinks extends Command
{
    protected $signature = 'telegram:prune-login-links';
    protected $description = 'Delete expired Telegram passwordless login links';

    public function handle(TelegramLoginLinkService $service): int
    {
        $count = $service->prune();
        $this->info("Pruned {$count} expired Telegram login link(s).");
        return self::SUCCESS;
    }
}
