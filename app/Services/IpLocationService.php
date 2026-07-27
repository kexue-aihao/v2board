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
        $files = [
            "china_{$prefix}.mmdb" => 'china_' . $prefix,
            "china_{$prefix}_idc.mmdb" => 'china_' . $prefix . '_idc',
            "global_{$prefix}_idc.mmdb" => 'global_' . $prefix . '_idc',
            "global_{$prefix}_residential.mmdb" => 'global_' . $prefix . '_residential'
        ];

        foreach ($files as $file => $source) {
            $reader = $this->reader($file);
            if (!$reader) {
                continue;
            }
            $record = $reader->get($ip);
            if (!is_array($record) || !$this->isKnownRecord($record)) {
                continue;
            }
            return $this->normalize($ip, $version, $record, $source);
        }
        return $this->unknown($ip, $version);
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
        $countryCode = strtoupper(trim((string)($record['country_code'] ?? '')));
        $isChina = strpos($source, 'china_') === 0;
        if ($isChina) {
            $countryCode = $countryCode ?: 'CN';
        }
        if ($countryCode === 'ZZ') {
            return $this->unknown($ip, $version);
        }

        $province = $this->value($record, ['province']);
        $region = $this->value($record, ['region']);
        if ($isChina) {
            $region = $province ?: $region;
        }
        $city = $this->value($record, ['city']);
        $countryName = $this->value($record, ['country', 'country_name']);
        if (!$countryName) {
            $countryName = $countryCode ?: '';
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
