<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\SubscriptionRiskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class EvaluateSubscriptionRisk extends Command
{
    protected $signature = 'subscription:risk {--force : 重算已评估过的周期，仅在确认审计日志完整时使用}';
    protected $description = '评估已完成30天周期的订阅风险';

    public function handle(): int
    {
        $service = new SubscriptionRiskService();
        if (!$service->available() || !Schema::hasTable('v2_subscription')) {
            $this->info('订阅风险表尚未安装，跳过评估。');
            return self::SUCCESS;
        }

        // 常规运行只评估从未评估过的周期。--force 会重算全部周期，但写入本身是
        // 按依据分组的：本轮拿不到依据的字段沿用已存值，所以不会把历史判定抹成零。
        $force = (bool)$this->option('force');
        if ($force) {
            $this->warn('已启用 --force，将重算全部已完成周期。');
        }

        $evaluated = 0;
        Subscription::orderBy('id')->chunkById(100, function ($subscriptions) use ($service, $force, &$evaluated) {
            foreach ($subscriptions as $subscription) {
                $evaluated += count($service->evaluateCompletedCycles($subscription, null, $force));
            }
        });
        $this->info("已评估 {$evaluated} 个新周期。");
        return self::SUCCESS;
    }
}
