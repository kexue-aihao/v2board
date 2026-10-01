<?php

namespace App\Console\Commands;

use App\Services\SubscriptionRiskNotifyService;
use Illuminate\Console\Command;

/**
 * 风险提醒的兜底入口：收集「风险值 >= 阈值」的订阅 → 合成一条摘要私聊给管理员。
 *
 * 判定产出时（subscription:risk / 手动评估 / 重算）各自会调一次，这个命令负责补上
 * 那些漏掉的：中途崩溃、发送失败留在待发状态、判定写入发生在别的路径（如 GET /user/risk）。
 * 幂等由台账唯一键保证，重复跑不会重复提醒。
 */
class NotifySubscriptionRisk extends Command
{
    protected $signature = 'risk:notify {--dry-run : 只收集不发送，打印将要提醒的条数}';
    protected $description = '订阅清洗网关：把高风险订阅汇总提醒给管理员';

    public function handle(): int
    {
        $service = new SubscriptionRiskNotifyService();

        if ($this->option('dry-run')) {
            $collected = $service->collect();
            $this->info("已登记 {$collected} 条高风险订阅（未发送）。");
            return self::SUCCESS;
        }

        if (!$service->enabled()) {
            $this->warn('提醒未启用（risk_notify_enable 或 telegram_bot_enable/token 未配置），仅登记待办。');
        }

        $result = $service->run();
        if (!empty($result['skipped'])) {
            $this->info('另一轮提醒正在执行，本轮跳过。');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '已登记 %d 条，提醒 %d 条（明细 %d 行），送达管理员 %d 人。',
            (int)$result['collected'],
            (int)$result['sent'],
            (int)$result['detailed'],
            (int)$result['recipients']
        ));

        return self::SUCCESS;
    }
}
