<?php

namespace App\Console\Commands;

use App\Services\PaymentAttemptService;
use Illuminate\Console\Command;

class InvalidateLegacyPaymentOrders extends Command
{
    protected $signature = 'payment:invalidate-legacy {--force : Cancel pending orders without a payment attempt}';
    protected $description = 'Invalidate legacy pending payment orders before enabling secure payment callbacks';

    public function handle(PaymentAttemptService $attempts): int
    {
        if (!$this->option('force')) {
            $this->warn('No orders were changed. Re-run with --force after pausing checkout.');
            return self::FAILURE;
        }

        $count = $attempts->invalidateLegacyPendingOrders();
        $this->info("Invalidated {$count} legacy pending order(s).");
        return self::SUCCESS;
    }
}
