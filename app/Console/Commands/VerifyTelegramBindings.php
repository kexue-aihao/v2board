<?php

namespace App\Console\Commands;

use App\Models\TelegramSubscriptionBinding;
use App\Services\TelegramBindingService;
use Illuminate\Console\Command;

class VerifyTelegramBindings extends Command
{
    protected $signature = 'telegram:verify-bindings';
    protected $description = 'Verify Telegram subscription bindings';

    public function handle(): int
    {
        $service = new TelegramBindingService();
        if (!$service->enabled()) return self::SUCCESS;
        TelegramSubscriptionBinding::where('status', 'active')->chunkById(100, function ($bindings) use ($service) {
            foreach ($bindings as $binding) {
                $service->verifyOne($binding);
            }
        });
        return self::SUCCESS;
    }
}
