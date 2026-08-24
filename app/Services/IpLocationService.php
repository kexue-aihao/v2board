<?php

namespace App\Services;

use App\Models\IpLocationCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use MaxMind\Db\Reader;

class IpLocationService
{
    private const UNKNOWN_STATUS = 'unknown';
    private $readers = [];
    private $readerFailures = [];
    private $sourceVersions = [];
    private $cacheAvailable;

    public function lookup(?string $ip): array
    {
        return $this->decorate($this->resolve($ip));
    }

    /**
     * 批量解析。逐行调用 lookup() 是 N+1：一屏 100 个 IP 就是 100 次
     * `SELECT ... WHERE ip = ?`，未命中的还各带一次 INSERT。这里把「查缓存」合并成一条
     * whereIn，只有真正没缓存过的 IP 才逐个读 mmdb 并回填缓存 —— 一整页从没见过的 IP
     * 由 100 次 SELECT + 100 次 INSERT 降到 1 次 SELECT + 100 次 INSERT。
     *
     * 单个 IP 的解析口径与 lookup() 完全一致（非法/内网地址同样返回 unknown、同样经
     * decorate() 补 is_idc 三态），差别只在缓存查询的合并。
     *
     * @param array $ips 原始 IP 字符串数组，允许重复与空值
     * @return array<string, array> 以 IP 原文为键；调用方取不到键时应回落到 lookup()
     */
    public function lookupMany(array $ips): array
    {
        $result = [];
        $pending = [];
        foreach ($ips as $value) {
            $ip = trim((string)$value);
            if ($ip === '' || isset($result[$ip]) || isset($pending[$ip])) {
                continue;
            }
            // 私有/保留地址与非法字面量（例如 SubscribeAuditService 写下的 'unknown'）
            // 根本不进 mmdb 也不进缓存，直接给 unknown，省掉一次无用的缓存查询。
            if (!filter_var($ip, FILTER_VALIDATE_IP) || !$this->isPublicIp($ip)) {
                $result[$ip] = $this->decorate($this->unknown($ip));
                continue;
            }
            $pending[$ip] = true;
        }
        if (!count($pending)) {
            return $result;
        }

        if ($this->cacheAvailable()) {
            try {
                foreach (IpLocationCache::whereIn('ip', array_keys($pending))->get() as $cached) {
                    $ip = (string)$cached->ip;
                    if (!isset($pending[$ip])) {
                        continue;
                    }
                    $version = strpos($ip, ':') !== false ? 6 : 4;
                    if ($this->cacheIsFresh($cached)) {
                        unset($pending[$ip]);
                        $result[$ip] = $this->decorate($this->fromCache($cached, $ip, $version));
                    }
                }
            } catch (\Throwable $e) {
                // 缓存读失败不能让整页归属地都变成未知：退回逐个解析。
                Log::warning('Batch IP location cache lookup failed', ['error' => $e->getMessage()]);
            }
        }

        foreach (array_keys($pending) as $ip) {
            $result[$ip] = $this->decorate($this->resolveFresh($ip));
        }
        return $result;
    }

    /**
     * 绕过缓存查询直接读 mmdb 并回填缓存。只给 lookupMany() 用：那里已经用一条 whereIn
     * 确认过这些 IP 不在缓存里，再走 resolve() 会为每个未命中的 IP 多打一次 SELECT。
     * 日志里刻意不带 IP —— 完整 IP 不落日志文件。
     */
    private function resolveFresh(string $ip): array
    {
        $version = strpos($ip, ':') !== false ? 6 : 4;
        try {
            $location = $this->lookupMmdb($ip, $version);
            $this->cache($location);
            return $location;
        } catch (\Throwable $e) {
            Log::warning('IP location lookup failed', ['error' => $e->getMessage()]);
            $location = $this->unknown($ip, $version, 'lookup_failed');
            $this->cache($location);
            return $location;
        }
    }

    // 内置库把 IDC/云厂商单独建库，所以「查到了但不落在 IDC 库里」才是可以确定的「非 IDC」，
    // 必须与「压根没查到」区分开：前者可以写否，后者只能写未知。
    // 在 resolve() 之外附加，避免这个派生字段被 cache() 当成列写进 v2_ip_location_cache。
    private function decorate(array $location): array
    {
        if (($location['status'] ?? '') !== 'resolved') {
            $location['is_idc'] = null;
            return $location;
        }
        $location['is_idc'] = (string)($location['idc_vendor'] ?? '') !== ''
            || strpos((string)($location['source'] ?? ''), '_idc') !== false;
        return $location;
    }

    private function resolve(?string $ip): array
    {
        $ip = trim((string)$ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->unknown($ip, 0, 'invalid_ip');
        }

        $version = strpos($ip, ':') !== false ? 6 : 4;
        if (!$this->isPublicIp($ip)) {
            return $this->unknown($ip, $version, 'non_public_ip');
        }

        try {
            if ($this->cacheAvailable()) {
                $cached = IpLocationCache::where('ip', $ip)->first();
                if ($cached && $this->cacheIsFresh($cached)) {
                    return $this->fromCache($cached, $ip, $version);
                }
            }

            $location = $this->lookupMmdb($ip, $version);
            $this->cache($location);
            return $location;
        } catch (\Throwable $e) {
            // Geolocation is enrichment only. It must never break subscription delivery.
            Log::warning('IP location lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            $location = $this->unknown($ip, $version, 'lookup_failed');
            $this->cache($location);
            return $location;
        }
    }

    public function cacheAvailable(): bool
    {
        if ($this->cacheAvailable !== null) {
            return $this->cacheAvailable;
        }
        try {
            return $this->cacheAvailable = Schema::hasTable('v2_ip_location_cache');
        } catch (\Throwable $e) {
            return $this->cacheAvailable = false;
        }
    }

    public function clearCache(): int
    {
        if (!$this->cacheAvailable()) {
            return 0;
        }
        return IpLocationCache::query()->delete();
    }

    private function cacheIsFresh(IpLocationCache $cache): bool
    {
        // 旧缓存行没有 expires_at。让它们自然失效并在下次访问时按新 IP 库重算，
        // 避免把以前的未知结果或过期归属无限期展示下去。
        return $cache->expires_at !== null && (int)$cache->expires_at > time();
    }

    private function lookupMmdb(string $ip, int $version): array
    {
        if ((int)env('IP_GEOIP_ENABLED', 1) !== 1) {
            return $this->unknown($ip, $version, 'geoip_disabled');
        }

        $prefix = $version === 6 ? 'ipv6' : 'ipv4';
        $availableReaders = 0;

        // IDC/云厂商判定必须独立于地理定位，不能靠「首个命中的库」决定。
        // 香港的 AWS 地址同时存在于 china_ipv4.mmdb（有 province/city/isp="Amazon"，
        // 但没有 idc_vendor）和 global_ipv4_idc.mmdb（vendor="AWS"）。原来按文件顺序
        // 首个命中即返回，中国库先命中就再也查不到 vendor，AWS、Azure 这类海外云
        // 会被判成非 IDC。国内云不受影响，因为 china_ipv4.mmdb 自带 idc_vendor。
        // 四个 IDC 库的每条记录都带 vendor，所以命中即可确定是 IDC 且拿得到厂商名。
        $idcSource = '';
        $idcRecord = null;
        $idcVendor = '';
        foreach ([
            "china_{$prefix}_idc.mmdb" => 'china_' . $prefix . '_idc',
            "global_{$prefix}_idc.mmdb" => 'global_' . $prefix . '_idc'
        ] as $file => $source) {
            $reader = $this->reader($file);
            if (!$reader) {
                continue;
            }
            $availableReaders++;
            $record = $reader->get($ip);
            if (!is_array($record)) {
                continue;
            }
            $vendor = $this->value($record, ['idc_vendor', 'vendor']);
            if ($vendor === '') {
                continue;
            }
            $idcSource = $source;
            $idcRecord = $record;
            $idcVendor = $vendor;
            break;
        }

        // 地理信息取更精细的库：中国库有 province/city/isp，其次全球住宅库。
        // IDC 库只在前两者都没有时兜底 —— 云厂商地址通常不在住宅库里。
        foreach ([
            "china_{$prefix}.mmdb" => 'china_' . $prefix,
            "global_{$prefix}_residential.mmdb" => 'global_' . $prefix . '_residential'
        ] as $file => $source) {
            $reader = $this->reader($file);
            if (!$reader) {
                continue;
            }
            $availableReaders++;
            $record = $reader->get($ip);
            if (!is_array($record) || !$this->isKnownRecord($record)) {
                continue;
            }
            return $this->withIdcVendor($this->normalize($ip, $version, $record, $source), $idcVendor);
        }

        if ($idcRecord !== null && $this->isKnownRecord($idcRecord)) {
            return $this->withIdcVendor($this->normalize($ip, $version, $idcRecord, $idcSource), $idcVendor);
        }
        return $this->unknown($ip, $version, $availableReaders ? 'no_matching_record' : 'mmdb_unavailable');
    }

    private function withIdcVendor(array $location, string $vendor): array
    {
        if ($vendor !== '' && ($location['status'] ?? '') === 'resolved') {
            $location['idc_vendor'] = $vendor;
            $location['network_type'] = 'datacenter';
        }
        return $location;
    }

    private function reader(string $file): ?Reader
    {
        if (isset($this->readers[$file])) {
            return $this->readers[$file];
        }
        if (isset($this->readerFailures[$file])) {
            return null;
        }

        $path = base_path(env('IP_GEOIP_PATH', 'resources/ipdb')) . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path) || !is_readable($path)) {
            $message = "MMDB file unavailable: {$file}";
            $this->readerFailures[$file] = $message;
            Log::warning($message, ['path' => $path]);
            return null;
        }
        try {
            return $this->readers[$file] = new Reader($path);
        } catch (\Throwable $e) {
            $this->readerFailures[$file] = $e->getMessage();
            Log::warning('Unable to open MMDB file', ['file' => $file, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function normalize(string $ip, int $version, array $record, string $source): array
    {
        $isChina = strpos($source, 'china_') === 0;
        if ($isChina) {
            $countryCode = strtoupper(trim((string)($record['country_code'] ?? ''))) ?: 'CN';
            $province = $this->value($record, ['province']);
            $region = $province ?: $this->value($record, ['region']);
            $city = $this->value($record, ['city']);
            $countryName = $this->value($record, ['country', 'country_name']) ?: $countryCode;
        } else {
            // 全球库的字段名和含义并不一致：country_code 存的是大洲代码（NA/EU/AS/OC/AF/SA），
            // 真正的 ISO 国家代码在 region，city 存的是一级行政区（California/Bavaria/Guangdong）。
            // continent 字段几乎恒为 NA，不可用。按原来的字面映射会把美国地址标成
            // 「国家 NA / 地区 US」，风控统计的 country_count 数的其实是大洲。
            // 已对美国、澳大利亚、德国、南非、委内瑞拉、香港、中国大陆七地实测确认该规律。
            $countryCode = strtoupper($this->value($record, ['region']));
            $province = $this->value($record, ['city']);
            $region = $province;
            $city = '';
            $countryName = $this->value($record, ['country', 'country_name']) ?: $countryCode;
        }
        if ($countryCode === 'ZZ' || $countryCode === '') {
            return $this->unknown($ip, $version, 'no_matching_record');
        }
        $idc = $this->value($record, ['idc_vendor', 'vendor']);

        return [
            'ip' => $ip,
            'ip_version' => $version,
            'country_code' => $countryCode,
            'country_name' => $countryName,
            'region' => $region,
            'province' => $province,
            'city' => $city,
            'district' => $this->value($record, ['district']),
            'isp' => $this->value($record, ['isp']),
            'idc_vendor' => $idc,
            'location_key' => $this->locationKey($countryCode, $region, $city),
            'latitude' => $this->number($record, 'latitude'),
            'longitude' => $this->number($record, 'longitude'),
            'source' => $source,
            'source_version' => $this->sourceVersion($source),
            'asn' => $this->integer($record, ['asn', 'autonomous_system_number']),
            'organization' => $this->value($record, ['organization', 'organization_name', 'org', 'owner']),
            'network_type' => $this->networkType($record, $source, $idc),
            'status' => 'resolved',
            'lookup_error' => ''
        ];
    }

    private function unknown(string $ip, int $version = 0, string $error = ''): array
    {
        return [
            'ip' => $ip,
            'ip_version' => $version ?: (strpos($ip, ':') !== false ? 6 : 4),
            'country_code' => '', 'country_name' => '', 'region' => '', 'province' => '',
            'city' => '', 'district' => '', 'isp' => '', 'idc_vendor' => '',
            'location_key' => '', 'latitude' => null, 'longitude' => null,
            'source' => '', 'source_version' => '', 'asn' => null, 'organization' => '',
            'network_type' => 'unknown', 'status' => self::UNKNOWN_STATUS,
            'lookup_error' => $error
        ];
    }

    private function cache(array $location): void
    {
        if (!$this->cacheAvailable() || !$location['ip']) {
            return;
        }
        try {
            $now = time();
            $location['resolved_at'] = $now;
            $location['expires_at'] = $this->expiresAt($location, $now);
            IpLocationCache::updateOrCreate(['ip' => $location['ip']], $location);
        } catch (\Throwable $e) {
            Log::warning('Unable to cache IP location', ['ip' => $location['ip'], 'error' => $e->getMessage()]);
        }
    }

    private function fromCache(IpLocationCache $cache, string $ip, int $version): array
    {
        $data = $cache->toArray();
        $data['ip'] = $ip;
        $data['ip_version'] = (int)($data['ip_version'] ?: $version);
        return $data;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function isKnownRecord(array $record): bool
    {
        foreach (['country_code', 'country', 'province', 'region', 'city', 'isp', 'vendor', 'idc_vendor'] as $key) {
            if (isset($record[$key]) && trim((string)$record[$key]) !== '' && strtoupper(trim((string)$record[$key])) !== 'ZZ') {
                return true;
            }
        }
        return false;
    }

    private function value(array $record, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($record[$key]) && is_scalar($record[$key]) && trim((string)$record[$key]) !== '') {
                return trim((string)$record[$key]);
            }
        }
        return '';
    }

    private function number(array $record, string $key): ?float
    {
        return isset($record[$key]) && is_numeric($record[$key]) ? (float)$record[$key] : null;
    }

    private function integer(array $record, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($record[$key]) && is_numeric($record[$key])) {
                return (int)$record[$key];
            }
        }
        return null;
    }

    private function networkType(array $record, string $source, string $idcVendor): string
    {
        if ($idcVendor !== '' || strpos($source, '_idc') !== false) {
            return 'datacenter';
        }

        $declared = strtolower($this->value($record, ['network_type', 'network', 'type']));
        if (in_array($declared, ['datacenter', 'mobile', 'residential', 'business'], true)) {
            return $declared;
        }
        if (strpos($source, '_residential') !== false) {
            return 'residential';
        }

        $isp = strtolower($this->value($record, ['isp']));
        if (strpos($isp, 'mobile') !== false || strpos($isp, '移动') !== false) {
            return 'mobile';
        }
        return 'unknown';
    }

    private function sourceVersion(string $source): string
    {
        if (isset($this->sourceVersions[$source])) {
            return $this->sourceVersions[$source];
        }

        $reader = $this->reader($source . '.mmdb');
        if (!$reader) {
            return $this->sourceVersions[$source] = '';
        }
        try {
            $metadata = $reader->metadata();
            return $this->sourceVersions[$source] = substr(
                $source . '@' . (int)$metadata->buildEpoch,
                0,
                96
            );
        } catch (\Throwable $e) {
            return $this->sourceVersions[$source] = $source;
        }
    }

    private function expiresAt(array $location, int $now): int
    {
        if (($location['status'] ?? '') === 'resolved') {
            $days = max(1, min(365, (int)env('IP_GEOIP_CACHE_TTL_DAYS', 30)));
            return $now + ($days * 86400);
        }

        // 失败缓存采用更短时间，IP 库更新或临时文件不可用后会自动重试。
        $hours = max(1, min(168, (int)env('IP_GEOIP_UNKNOWN_CACHE_TTL_HOURS', 6)));
        return $now + ($hours * 3600);
    }

    public function locationKey(string $countryCode, string $region = '', string $city = ''): string
    {
        return implode('|', array_filter([strtoupper($countryCode), trim($region), trim($city)], function ($value) {
            return $value !== '';
        }));
    }
}
