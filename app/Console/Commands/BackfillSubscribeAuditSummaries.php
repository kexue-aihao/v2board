<?php

namespace App\Console\Commands;

use App\Services\SubscribeAuditService;
use Illuminate\Console\Command;

class BackfillSubscribeAuditSummaries extends Command
{
    protected $signature = 'audit:backfill-summaries {--chunk=1000 : 每批重放的原始审计记录数量}';
    protected $description = '从当前保留的原始订阅审计记录幂等重建「账号 × 订阅 × IP × UA」聚合';

    public function handle(): int
    {
        $service = new SubscribeAuditService();
        $result = $service->rebuildSummaries((int) $this->option('chunk'), function (int $processed) {
            $this->output->write("\r已处理原始订阅审计 {$processed} 条");
        });

        if (!$result['available']) {
            $this->error('订阅拉取聚合表或原始审计表尚未安装，请先执行 php artisan v2board:update。');
            return self::FAILURE;
        }

        if ($result['audits'] > 0) {
            $this->newLine();
        }
        $this->info(
            "订阅拉取聚合回填完成：重建 {$result['audits']} 条原始记录，"
            . "聚合表 {$result['rows']} 行/{$result['hits']} 次拉取。"
        );
        if (!$result['verified']) {
            $this->error('订阅拉取聚合校验失败：原始审计存在，但派生聚合不完整。部署已停止，请检查数据库日志后重新执行回填。');
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
