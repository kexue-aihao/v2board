<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\OrderService;
use App\Services\ResellerSharedSubscriptionService;
use App\Models\ResellerOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class OrderHandleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $tradeNo;

    public $tries = 3;
    public $timeout = 5;
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($tradeNo)
    {
        $this->onQueue('order_handle');
        $this->tradeNo = $tradeNo;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            $order = Order::where('trade_no', $this->tradeNo)
                ->first();

            if (!$order) return;

            $orderService = new OrderService($order);
            switch ($order->status) {
                case 0:
                    if ($order->created_at <= (time() - 3600 * 2)) {
                        $orderService->cancel();
                    }
                    break;
                case 1:
                    $orderService->open();
                    break;
            }
            $openedOrder = Order::where('id', $order->id)->first();
            if ($openedOrder && (int)$openedOrder->status === 3) {
                $mapping = ResellerOrder::where('platform_order_id', $openedOrder->id)->first();
                if ($mapping) {
                    (new ResellerSharedSubscriptionService())->synchronizePaidOrder($mapping);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Order opening job failed', [
                'trade_no' => $this->tradeNo,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e)
    {
        Log::critical('Order opening job exhausted retries', [
            'trade_no' => $this->tradeNo,
            'error' => $e->getMessage()
        ]);
    }
}
