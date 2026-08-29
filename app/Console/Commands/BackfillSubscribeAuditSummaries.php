<?php

namespace App\Console\Commands;

use App\Services\SubscribeAuditService;
use Illuminate\Console\Command;

class BackfillSubscribeAuditSummaries extends Command
{
    protected $signature = 'audit:backfill-summaries {--chunk=1000 : 每批重放的原始审计记录数量}';
    protected $description = '从当前保留的原始订阅审计记录幂等重建 IP 与 User-Agent 汇总';

    public function handle(): int
    {
        $service = new SubscribeAuditService();
        $result = $service->rebuildSummaries((int) $this->option('chunk'), function (int $processed) {
            $this->output->write("\r已处理原始订阅审计 {$processed} 条");
        });

        if (!$result['available']) {
            $this->warn('订阅审计汇总表或原始审计表尚未安装，请先执行 php artisan v2board:update。');
            return self::SUCCESS;
        }

        if ($result['audits'] > 0) {
            $this->newLine();
        }
        $this->info("订阅审计汇总回填完成：重建 {$result['audits']} 条原始记录。");
        return self::SUCCESS;
    }
}
