<?php

namespace App\Console\Commands;

use App\Services\SubscribeAuditRetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanSubscribeAudit extends Command
{
    protected $signature = 'audit:clean
        {--days= : 覆盖配置里的保留天数，0 表示不清理}
        {--chunk=2000 : 每批删除的行数}
        {--limit=500000 : 单次运行每张表最多删除的行数，0 表示不限}
        {--dry-run : 只统计将被删除的行数，不实际删除}';

    protected $description = '按保留期清理订阅审计记录';

    public function handle(): int
    {
        if (!Schema::hasTable('v2_subscribe_request_log') && !Schema::hasTable('v2_node_connection_log')) {
            $this->info('订阅审计表尚未安装，跳过清理。');
            return self::SUCCESS;
        }
        $this->warnMissingIndexes();

        $service = new SubscribeAuditRetentionService();
        $days = $this->option('days') === null ? null : max(0, (int)$this->option('days'));
        $dryRun = (bool)$this->option('dry-run');

        $result = $service->purgeExpired(
            $days,
            (int)$this->option('chunk'),
            max(0, (int)$this->option('limit')),
            $dryRun
        );

        if ($result['days'] <= 0) {
            $this->info('保留天数为 0，清理已关闭。');
            return self::SUCCESS;
        }

        $source = $days === null ? '配置' : '--days';
        $this->info(sprintf('保留天数：%d（来自%s）', $result['days'], $source));
        $this->info('截止时间：' . date('Y-m-d H:i:s', $result['cutoff']) . ' 之前的记录会被清理');
        $this->info(sprintf(
            '%s订阅拉取记录 %d 条，节点连接记录 %d 条。',
            $dryRun ? '[dry-run] 将清理 ' : '已清理 ',
            $result['subscribe_request_log'],
            $result['node_connection_log']
        ));
        if ($result['truncated']) {
            $this->warn('已达到 --limit 上限，剩余记录留待下次运行清理。');
        }
        return self::SUCCESS;
    }

    /**
     * 缺索引时按时间删除会全表扫描。索引由 v2board:update 建立，手工先跑本命令时提醒一下。
     */
    private function warnMissingIndexes(): void
    {
        foreach (['v2_subscribe_request_log' => 'requested_at', 'v2_node_connection_log' => 'last_seen_at'] as $table => $index) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            try {
                if (empty(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))) {
                    $this->warn("表 {$table} 缺少索引 {$index}，删除会全表扫描，请先执行 php artisan v2board:update。");
                }
            } catch (\Throwable $e) {
                // 索引探测失败不该阻断清理。
            }
        }
    }
}
