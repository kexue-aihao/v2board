<?php

namespace App\Console\Commands;

use App\Services\DynamicRateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * 动态倍率的每分钟心跳。
 *
 * 取上一分钟的每用户原始字节（TrafficFetchJob 记的那份，不乘倍率）→ 算 Mbps →
 * 推进峰值状态机 → 发布两份热路径要用的键（规则、正在叠加的用户倍率）+ 落台账。
 *
 * 必须排在 traffic:update 之前或之后都无所谓：两者 drain 的是不同的键，
 * 账面流量那条链路完全不经过这里。
 */
class RateTick extends Command
{
    protected $signature = 'rate:tick
        {--dry-run : 只采样与判定并打印，不写 Redis 键、不落台账}';

    protected $description = '动态倍率：采样上一分钟的每用户带宽、推进峰值判定、发布生效倍率';

    public function handle(): int
    {
        $service = new DynamicRateService();
        if (!Schema::hasTable(DynamicRateService::TABLE_STATE)) {
            $this->warn('动态倍率表尚未安装（先跑 php artisan v2board:update），本次跳过。');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $service->tick($dryRun);
        $settings = $service->settings();

        $this->info(sprintf(
            '%s采样 %d 秒：%d 个用户有流量，推进 %d 个状态，其中 %d 个正在叠加倍率。',
            $dryRun ? '[dry-run] ' : '',
            $result['sampled_seconds'],
            $result['sampled_users'],
            $result['advanced_users'],
            $result['stacked_users']
        ));

        if ((int) $settings['enabled'] !== 1) {
            $this->warn('总开关是关的：状态照常推进（便于调参观察），但倍率一律按 1.0 计费。');
        }
        if ($dryRun) {
            $this->line('未写入任何 Redis 键与台账行。');
        }

        return self::SUCCESS;
    }
}
