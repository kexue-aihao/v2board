<?php

namespace App\Console\Commands;

use App\Models\SubscribeRequestLog;
use App\Services\IpLocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillSubscribeLocations extends Command
{
    protected $signature = 'ip:backfill-subscribe-locations {--chunk=200}';
    protected $description = '为历史订阅请求解析并缓存 IP 归属';

    public function handle(): int
    {
        if (!Schema::hasTable('v2_subscribe_request_log')) {
            $this->info('订阅请求日志表不存在，跳过回填。');
            return self::SUCCESS;
        }

        $service = new IpLocationService();
        $count = 0;
        $chunk = max(20, min(1000, (int)$this->option('chunk')));
        SubscribeRequestLog::where('request_ip', '<>', '')
            ->orderBy('id')
            ->chunkById($chunk, function ($records) use ($service, &$count) {
                foreach ($records as $record) {
                    $service->lookup($record->request_ip);
                    $count++;
                }
                $this->output->write("\r已处理 {$count} 条请求记录");
            });
        $this->newLine();
        $this->info("IP 归属回填完成，共处理 {$count} 条记录。");
        return self::SUCCESS;
    }
}
