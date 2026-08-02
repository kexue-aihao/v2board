<?php

namespace App\Console\Commands;

use App\Services\IpAccountLinkService;
use App\Services\SubscribeAuditRetentionService;
use Illuminate\Console\Command;

class AggregateIpAccountLink extends Command
{
    protected $signature = 'audit:ip-link
        {--chunk=2000 : 每批从订阅拉取日志读取的行数}
        {--limit=200000 : 单次运行最多处理的原始日志行数，0 表示不限}
        {--full : 【会丢历史】清空累积表后只从「现存的」原始日志重新回填：早于保留期的原始行已被 audit:clean 物理删除，那段累积会永久丢失。仅在首次启用本功能（表还是空的）时使用；表非空时必须另加 --force}
        {--force : 配合 --full：确认接受上面的历史丢失}
        {--prune-days= : 可选，删除最近活动早于该天数的累积记录，默认不删}
        {--dry-run : 只统计将要处理的行数，不写入}';

    protected $description = '把订阅拉取日志增量聚合成「IP + 账号 + UA」累积记录';

    public function handle(): int
    {
        $service = new IpAccountLinkService();
        if (!$service->available()) {
            $this->info('累积表 v2_ip_account_link 不存在，请先执行 php artisan v2board:update。');
            return self::SUCCESS;
        }
        if (!$service->sourceAvailable()) {
            $this->info('订阅拉取日志表不存在，跳过聚合。');
            return self::SUCCESS;
        }

        $full = (bool)$this->option('full');
        $dryRun = (bool)$this->option('dry-run');
        if ($full && !$dryRun) {
            // --full 会不可逆地销毁这张表存在的唯一理由：比原始日志保留期更长的历史。
            // 清空后只能从**当前还活着的**原始日志重扫，早于保留期的行已被 audit:clean
            // 物理删除，重扫拿不回来。所以表非空时一律拒绝，除非显式 --force。
            // （这条命令会出现在面板的修复提示里，一个「数字看着不对」的管理员很自然会
            //   照抄它 —— 护栏必须在命令这一侧。）
            $existing = $service->rowCount();
            if ($existing > 0 && !(bool)$this->option('force')) {
                $retentionDays = (new SubscribeAuditRetentionService())->retentionDays();
                $this->error(sprintf(
                    '拒绝执行：累积表里已有 %d 行历史记录，--full 会先把它们全部清空。',
                    $existing
                ));
                $this->line($retentionDays > 0
                    ? sprintf('重扫只能覆盖原始日志里还留着的部分（保留期 %d 天，更早的行已被 audit:clean 物理删除），'
                        . '超出保留期的那段累积会永久丢失、无法恢复。', $retentionDays)
                    : '原始日志当前不自动清理，但重扫仍只覆盖现存日志；此前被手工清理过的时段拿不回来。');
                $this->line('只想补上尚未聚合的增量：php artisan audit:ip-link（不带 --full）。');
                $this->line('只想看会处理多少行：php artisan audit:ip-link --full --dry-run（不写入、不清表）。');
                $this->line('确实要接受历史丢失并重建：php artisan audit:ip-link --full --force。');
                return self::FAILURE;
            }
            // 回填必须先清空：upsert 是「累加」语义，在已有基数上重扫会把次数算两遍。
            $this->warn('--full：先清空累积表再从最早的日志重新聚合（已有 ' . $existing . ' 行将被丢弃）。');
            $service->truncate();
        }

        $result = $service->aggregate(
            (int)$this->option('chunk'),
            max(0, (int)$this->option('limit')),
            $full,
            $dryRun
        );

        $this->info(sprintf(
            '%s原始日志 %d 行，写入/更新累积记录 %d 行（log id %d → %d，上界 %d）。',
            $dryRun ? '[dry-run] 将处理 ' : '已处理 ',
            $result['log_rows'],
            $result['link_rows'],
            $result['from_log_id'],
            $result['to_log_id'],
            $result['ceiling']
        ));
        if ($result['truncated']) {
            $this->warn('已达到 --limit 上限，剩余日志留待下次运行聚合。');
        }

        $pruneDays = $this->option('prune-days');
        if ($pruneDays !== null && (int)$pruneDays > 0 && !$dryRun) {
            $pruned = $service->prune((int)$pruneDays);
            $this->info(sprintf('已删除最近活动早于 %d 天的累积记录 %d 行。', (int)$pruneDays, $pruned));
        }

        $status = $service->status();
        $this->info(sprintf(
            '当前游标 %d，日志最大 id %d，累积至 %s。',
            $status['cursor'],
            $status['log_max_id'],
            $status['aggregated_through'] ? date('Y-m-d H:i:s', $status['aggregated_through']) : '-'
        ));
        return self::SUCCESS;
    }
}
