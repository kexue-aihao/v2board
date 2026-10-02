<?php

namespace App\Console\Commands;

use App\Services\IpLocationService;
use App\Services\SubscribeCleanGatewayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearIpLocationCache extends Command
{
    protected $signature = 'ip:clear-location-cache';
    protected $description = '清理订阅请求 IP 归属缓存';

    public function handle(): int
    {
        $deleted = (new IpLocationService())->clearCache();

        // 清洗网关把归属地冗余存了一份（列表按运营商/ASN 筛选要能走索引）。IP 库换版
        // 之后这份冗余会和缓存一起过期，所以这里一并清空 —— 下次打开列表或跑
        // access:locations 会按新库重查，两处口径不会长期分叉。
        $reset = $this->resetAccessSummaryLocations();

        $this->info("已清理 {$deleted} 条 IP 归属缓存。");
        if ($reset !== null) {
            $this->info("已重置 {$reset} 行订阅拉取记录的归属地，下次读取时按新库重查。");
        }

        return self::SUCCESS;
    }

    private function resetAccessSummaryLocations(): ?int
    {
        $table = SubscribeCleanGatewayService::TABLE;
        try {
            if (!Schema::hasTable($table)) {
                return null;
            }
            // 分块更新：这张表最大能到保留期内的全部四元组，单条 UPDATE 会在升级脚本里
            // 卡住整次部署，也会长时间持锁。
            $reset = [
                'isp' => null,
                'organization' => null,
                'asn' => null,
                'location_status' => null,
                'location_resolved_at' => null,
                'updated_at' => time()
            ];
            if (Schema::hasColumn($table, 'country_name')) {
                // country_code 不在这里重置：它已经不参与了（列还在，但代码不再写它）。
                $reset['country_name'] = null;
                $reset['region'] = null;
                $reset['city'] = null;
            }
            $total = 0;
            do {
                $updated = DB::table($table)
                    ->whereNotNull('location_resolved_at')
                    ->orderBy('id')
                    ->limit(2000)
                    ->update($reset);
                $total += $updated;
            } while ($updated > 0);

            return $total;
        } catch (\Throwable $e) {
            // 重置失败不该让缓存清理整体失败：下一次 IP 库换版仍会再清一遍。
            $this->warn('重置订阅拉取记录的归属地失败：' . $e->getMessage());
            return null;
        }
    }
}
