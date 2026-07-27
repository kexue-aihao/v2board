<?php

namespace App\Console\Commands;

use App\Services\SubscriptionTokenHistoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ReconcileTokenHistory extends Command
{
    protected $signature = 'token-history:reconcile
        {--chunk=2000 : 每批处理的行数}
        {--max-live=500000 : 活 token 数超过此值时跳过退役阶段，只做补录}
        {--dry-run : 只统计将要写入的行数，不实际写入}';

    protected $description = '让订阅 Token 历史与当前活凭证列保持一致';

    public function handle(): int
    {
        if (!Schema::hasTable('v2_subscription_token_history')) {
            $this->info('Token 历史表尚未安装，跳过对账。');
            return self::SUCCESS;
        }

        $dryRun = (bool)$this->option('dry-run');
        $result = (new SubscriptionTokenHistoryService())->reconcile(
            (int)$this->option('chunk'),
            max(1, (int)$this->option('max-live')),
            $dryRun
        );

        $this->info(sprintf('当前活 token %d 个。', $result['live']));
        $this->info(sprintf(
            '%s补录 %d 条，退役 %d 条。',
            $dryRun ? '[dry-run] 将' : '已',
            $result['inserted'],
            $result['retired']
        ));
        if ($result['orphaned'] > 0) {
            // delUser/allDel 从不删 v2_subscription 行，已删用户的订阅 token 仍然活着。
            // 这些行不补进历史，否则会永远指向一个不存在的 user_id。
            $this->warn(sprintf('跳过 %d 个归属用户已被删除的活 token（订阅行未随账号删除，属既有缺陷）。', $result['orphaned']));
        }
        if ($result['skipped_retire']) {
            $this->warn('活 token 数超过 --max-live，本轮跳过退役阶段。');
        }

        // 稳态下补录与退役都应该是 0：非零说明观察者漏写了，这条日志就是它的证据。
        $context = ['inserted' => $result['inserted'], 'retired' => $result['retired'],
            'orphaned' => $result['orphaned'], 'live' => $result['live'], 'dry_run' => $dryRun];
        if (!$dryRun && ($result['inserted'] > 0 || $result['retired'] > 0)) {
            Log::warning('Token 历史对账写入了记录，观察者可能漏写', $context);
        } else {
            Log::info('Token 历史对账完成', $context);
        }

        return self::SUCCESS;
    }
}
