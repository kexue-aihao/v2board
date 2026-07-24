<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionRiskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class EvaluateSubscriptionRisk extends Command
{
    protected $signature = 'subscription:risk';
    protected $description = '评估已完成30天周期的订阅风险';

    public function handle(): int
    {
        $service = new SubscriptionRiskService();
        if (!$service->available() || !Schema::hasTable('v2_subscription')) {
            $this->info('订阅风险表尚未安装，跳过评估。');
            return self::SUCCESS;
        }

        $evaluated = 0;
        Subscription::orderBy('id')->chunkById(100, function ($subscriptions) use ($service, &$evaluated) {
            foreach ($subscriptions as $subscription) {
                $evaluated += count($service->evaluateCompletedCycles($subscription));
            }
        });
        $this->info("已评估 {$evaluated} 个订阅周期。");
        return self::SUCCESS;
    }
}
