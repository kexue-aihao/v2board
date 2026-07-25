<?php

namespace App\Console\Commands;

use App\Services\IpLocationService;
use Illuminate\Console\Command;

class ClearIpLocationCache extends Command
{
    protected $signature = 'ip:clear-location-cache';
    protected $description = '清理订阅请求 IP 归属缓存';

    public function handle(): int
    {
        $deleted = (new IpLocationService())->clearCache();
        $this->info("已清理 {$deleted} 条 IP 归属缓存。");
        return self::SUCCESS;
    }
}
