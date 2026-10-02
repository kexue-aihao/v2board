<?php

namespace App\Console\Commands;

use App\Services\ExternalSubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * 抓取外部订阅源，导入成可下发的过渡节点。
 *
 * 失败不删已有节点（见 ExternalSubscriptionService::refresh 的说明），所以这个命令
 * 可以放心挂在定时任务上；`--dry-run` 只抓取与解析并打印，用来在页面上点「刷新」之前
 * 先看这个源到底能不能解析。
 */
class RefreshExternalSubscriptions extends Command
{
    protected $signature = 'external:refresh
        {--source= : 只刷新指定 ID 的源}
        {--dry-run : 只抓取与解析并打印结果，不写库、不改状态}';

    protected $description = '抓取外部订阅源并导入过渡节点（失败时保留上次成功的节点）';

    public function handle(): int
    {
        $service = new ExternalSubscriptionService();
        if (!Schema::hasTable(ExternalSubscriptionService::TABLE_SOURCE)) {
            $this->warn('外部订阅表尚未安装（先跑 php artisan v2board:update），本次跳过。');

            return self::SUCCESS;
        }

        $sources = $service->sources();
        if (!$sources) {
            $this->info('还没有配置外部订阅源。');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $sourceId = $this->option('source');
        $results = $sourceId !== null
            ? [$service->refresh((int) $sourceId, $dryRun)]
            : $service->refreshAll($dryRun);

        $rows = [];
        $failed = 0;
        foreach ($results as $result) {
            if (!$result['ok']) {
                $failed++;
            }
            $rows[] = [
                '#' . $result['source_id'],
                (string) ($result['name'] ?? ''),
                $result['ok'] ? 'ok' : '失败',
                $result['ok'] ? (string) ($result['count'] ?? 0) . ' 个节点' : (string) ($result['error'] ?? ''),
            ];
        }
        $this->table(['源', '名称', '状态', '结果'], $rows);

        if ($dryRun) {
            foreach ($results as $result) {
                if (!$result['ok'] || empty($result['nodes'])) {
                    continue;
                }
                $this->line('源 #' . $result['source_id'] . ' 解析出的节点：');
                $this->table(['名称', '协议', '地址', '端口'], array_map(function (array $node) {
                    return [$node['name'], $node['protocol'], $node['host'], $node['port']];
                }, array_slice($result['nodes'], 0, 20)));
            }
            $this->line('未写入任何数据。');
        }

        if ($failed > 0) {
            $this->warn('有 ' . $failed . ' 个源抓取失败：已有节点保持不变，看管理页的错误信息或 --dry-run 的输出。');
        }

        return self::SUCCESS;
    }
}
