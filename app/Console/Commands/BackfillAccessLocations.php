<?php

namespace App\Console\Commands;

use App\Services\IpLocationService;
use App\Services\SubscribeCleanGatewayService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 给「订阅清洗网关」的拉取记录补 IP 归属地。
 *
 * 归属地是 IP 库的派生结果，而订阅拉取是这张表上最高频的写路径 —— 在那里查一次
 * MMDB 就等于给每个客户端拉订阅都加一次归属地查询。所以写入时只落 IP，运营商 /
 * 归属机构 / ASN 由本命令离线增量补，列表页也会就地把当前这一页补掉，两个入口
 * 互为兜底。
 *
 * 幂等：只处理 location_resolved_at IS NULL 的行，跑多少遍都一样。IP 库换版后要
 * 重算全部历史，用 --refresh。
 */
class BackfillAccessLocations extends Command
{
    protected $signature = 'access:locations
        {--chunk=500 : 每批处理的聚合行数}
        {--limit=5000 : 单次运行最多解析多少个不同 IP，0 表示不限}
        {--refresh : 连同已经解析过的行一起重查（IP 库换版后用）}
        {--dry-run : 只统计待解析的行数，不写库}';

    protected $description = '补齐订阅清洗网关拉取记录的 IP 归属地（运营商 / 归属机构 / ASN）';

    public function handle(): int
    {
        $table = SubscribeCleanGatewayService::TABLE;
        if (!Schema::hasTable($table)) {
            $this->info('订阅拉取聚合表尚未安装，跳过。');
            return self::SUCCESS;
        }

        $chunk = max(10, min(5000, (int)$this->option('chunk')));
        $limit = max(0, (int)$this->option('limit'));
        $refresh = (bool)$this->option('refresh');
        $dryRun = (bool)$this->option('dry-run');

        $query = DB::table($table);
        if (!$refresh) {
            $query->whereNull('location_resolved_at');
        }
        $pending = (int)(clone $query)->count();
        if ($dryRun) {
            $this->info(sprintf('[dry-run] 待解析 %d 行（%s）。', $pending, $refresh ? '含已解析' : '仅未解析'));
            return self::SUCCESS;
        }
        if ($pending === 0) {
            $this->info('没有待解析的拉取记录。');
            return self::SUCCESS;
        }

        $service = new IpLocationService();
        $processed = 0;
        $ips = 0;
        // 按 id 游标推进：每批只取一批行，批内按 IP 去重后再查库 —— 同一 IP 在一屏里
        // 出现几十次是常态，逐行查会把 mmdb 读放大几十倍。
        $lastId = 0;
        while (true) {
            $rows = DB::table($table)
                ->where('id', '>', $lastId)
                ->when(!$refresh, function ($q) {
                    $q->whereNull('location_resolved_at');
                })
                ->orderBy('id')
                ->limit($chunk)
                ->get(['id', 'request_ip', 'location_resolved_at']);
            if ($rows->isEmpty()) {
                break;
            }

            $lastId = (int)$rows->last()->id;
            $batchIps = [];
            $idsByIp = [];
            foreach ($rows as $row) {
                $ip = (string)$row->request_ip;
                $batchIps[$ip] = true;
                $idsByIp[$ip][] = (int)$row->id;
            }
            if ($limit > 0 && $ips + count($batchIps) > $limit) {
                $this->warn(sprintf('已达到单次运行的 IP 上限 %d，剩余留待下次运行。', $limit));
                break;
            }
            $ips += count($batchIps);

            $locations = $service->lookupMany(array_keys($batchIps));
            $now = time();
            foreach ($idsByIp as $ip => $ids) {
                $location = $locations[$ip] ?? [];
                DB::table($table)->whereIn('id', $ids)->update([
                    'isp' => $this->nullable($location['isp'] ?? ''),
                    'organization' => $this->nullable($location['organization'] ?? ''),
                    'asn' => isset($location['asn']) && $location['asn'] !== '' && $location['asn'] !== null
                        ? (int)$location['asn'] : null,
                    'location_status' => (string)($location['status'] ?? 'unknown'),
                    'location_resolved_at' => $now,
                    'updated_at' => $now
                ]);
                $processed += count($ids);
            }

            $this->output->write("\r已处理 {$processed} 行 / {$ips} 个 IP");
        }

        $this->newLine();
        $this->info(sprintf('归属地回填完成：%d 行，%d 个 IP。', $processed, $ips));
        return self::SUCCESS;
    }

    private function nullable($value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
