<?php

namespace App\Console\Commands;

use App\Models\SubscribeRequestLog;
use App\Models\NodeConnectionLog;
use App\Services\IpLocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillSubscribeLocations extends Command
{
    protected $signature = 'ip:backfill-subscribe-locations {--chunk=200}';
    protected $description = '为历史订阅请求和节点连接记录解析并缓存 IP 归属';

    public function handle(): int
    {
        $hasSubscribeLog = Schema::hasTable('v2_subscribe_request_log');
        $hasNodeLog = Schema::hasTable('v2_node_connection_log');
        if (!$hasSubscribeLog && !$hasNodeLog) {
            $this->info('订阅请求和节点连接日志表均不存在，跳过回填。');
            return self::SUCCESS;
        }

        $service = new IpLocationService();
        $chunk = max(20, min(1000, (int)$this->option('chunk')));
        $counts = ['subscribe' => 0, 'connection' => 0];

        if ($hasSubscribeLog) {
            SubscribeRequestLog::where('request_ip', '<>', '')
                ->orderBy('id')
                ->chunkById($chunk, function ($records) use ($service, &$counts) {
                    // 一批 IP 一次查缓存，避免历史日志里同一 IP 反复发起单条查询。
                    $service->lookupMany($records->pluck('request_ip')->all());
                    $counts['subscribe'] += $records->count();
                    $this->output->write("\r已处理订阅请求 {$counts['subscribe']} 条");
                });
            $this->newLine();
        }

        if ($hasNodeLog) {
            NodeConnectionLog::where('ip', '<>', '')
                ->orderBy('id')
                ->chunkById($chunk, function ($records) use ($service, &$counts) {
                    $service->lookupMany($records->pluck('ip')->all());
                    $counts['connection'] += $records->count();
                    $this->output->write("\r已处理节点连接 {$counts['connection']} 条");
                });
            $this->newLine();
        }

        $this->info("IP 归属回填完成：订阅请求 {$counts['subscribe']} 条，节点连接 {$counts['connection']} 条。");
        return self::SUCCESS;
    }
}
