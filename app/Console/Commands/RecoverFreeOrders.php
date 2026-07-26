<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Console\Command;

class RecoverFreeOrders extends Command
{
    protected $signature = 'order:recover-free
                            {trade_no? : Recover one order by trade number}
                            {--chunk=100 : Number of orders to process per batch}';

    protected $description = 'Recover paid free orders that are still waiting to open';

    public function handle(): int
    {
        $tradeNo = $this->argument('trade_no');
        $chunk = max((int)$this->option('chunk'), 1);
        $query = Order::where('status', 1)->where('total_amount', '<=', 0);

        if ($tradeNo) {
            $query->where('trade_no', $tradeNo);
            $order = $query->first();
            if (!$order) {
                $this->error('Free pending order was not found.');
                return self::FAILURE;
            }

            return $this->recover($order) ? self::SUCCESS : self::FAILURE;
        }

        $processed = 0;
        $failed = 0;
        $query->orderBy('id')->chunkById($chunk, function ($orders) use (&$processed, &$failed) {
            foreach ($orders as $order) {
                if ($this->recover($order)) {
                    $processed++;
                } else {
                    $failed++;
                }
            }
        });

        $this->info("Recovered {$processed} free order(s).");
        if ($failed > 0) {
            $this->error("Failed to recover {$failed} free order(s).");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function recover(Order $order): bool
    {
        $success = (new OrderService($order))->completeFree();
        if ($success) {
            $this->info("Recovered {$order->trade_no}.");
            return true;
        }

        $this->error("Failed {$order->trade_no}.");
        return false;
    }
}
