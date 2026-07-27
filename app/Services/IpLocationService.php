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
    private $cacheAvailable;

    public function lookup(?string $ip): array
    {
        return $this->decorate($this->resolve($ip));
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
        $unknown = $this->unknown($ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $unknown;
        }

        $version = strpos($ip, ':') !== false ? 6 : 4;
        if (!$this->isPublicIp($ip)) {
            return $unknown;
        }

        try {
            if ($this->cacheAvailable()) {
                $cached = IpLocationCache::where('ip', $ip)->first();
                if ($cached) {
                    return $this->fromCache($cached, $ip, $version);
                }
            }

            $location = $this->lookupMmdb($ip, $version);
            $this->cache($location);
            return $location;
        } catch (\Throwable $e) {
            // Geolocation is enrichment only. It must never break subscription delivery.
            Log::warning('IP location lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            return $unknown;
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

    private function lookupMmdb(string $ip, int $version): array
    {
        if ((int)env('IP_GEOIP_ENABLED', 1) !== 1) {
            return $this->unknown($ip, $version);
        }

        $prefix = $version === 6 ? 'ipv6' : 'ipv4';

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
            $record = $reader->get($ip);
            if (!is_array($record) || !$this->isKnownRecord($record)) {
                continue;
            }
            return $this->withIdcVendor($this->normalize($ip, $version, $record, $source), $idcVendor);
        }

        if ($idcRecord !== null && $this->isKnownRecord($idcRecord)) {
            return $this->withIdcVendor($this->normalize($ip, $version, $idcRecord, $idcSource), $idcVendor);
        }
        return $this->unknown($ip, $version);
    }

    private function withIdcVendor(array $location, string $vendor): array
    {
        if ($vendor !== '' && ($location['status'] ?? '') === 'resolved') {
            $location['idc_vendor'] = $vendor;
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
            return $this->unknown($ip, $version);
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
            'status' => 'resolved'
        ];
    }

    private function unknown(string $ip, int $version = 0): array
    {
        return [
            'ip' => $ip,
            'ip_version' => $version ?: (strpos($ip, ':') !== false ? 6 : 4),
            'country_code' => '', 'country_name' => '', 'region' => '', 'province' => '',
            'city' => '', 'district' => '', 'isp' => '', 'idc_vendor' => '',
            'location_key' => '', 'latitude' => null, 'longitude' => null,
            'source' => '', 'status' => self::UNKNOWN_STATUS
        ];
    }

    private function cache(array $location): void
    {
        if (!$this->cacheAvailable() || !$location['ip']) {
            return;
        }
        try {
            IpLocationCache::updateOrCreate(['ip' => $location['ip']], $location + ['resolved_at' => time()]);
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

    public function locationKey(string $countryCode, string $region = '', string $city = ''): string
    {
        return implode('|', array_filter([strtoupper($countryCode), trim($region), trim($city)], function ($value) {
            return $value !== '';
        }));
    }
}
