<?php

namespace App\Services;

use App\Models\ServerAnytls;
use App\Models\ServerHysteria;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Models\ServerVmess;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the connection host on all supported node protocols.
 * TLS SNI and transport settings are intentionally outside this service.
 */
class ServerHostReplacementService
{
    private const MODE_EXACT = 'exact';
    private const MODE_CONTAINS = 'contains';

    private const MODELS = [
        'shadowsocks' => ServerShadowsocks::class,
        'vmess' => ServerVmess::class,
        'vless' => ServerVless::class,
        'trojan' => ServerTrojan::class,
        'tuic' => ServerTuic::class,
        'hysteria' => ServerHysteria::class,
        'anytls' => ServerAnytls::class,
        'v2node' => ServerV2node::class,
    ];

    public function preview(string $mode, string $oldHost, string $newHost): array
    {
        $matches = $this->findMatches($mode, $oldHost);
        return [
            'mode' => $mode,
            'old_host' => $oldHost,
            'new_host' => $newHost,
            'matched_count' => count($matches),
            'nodes' => array_map(function (array $node) use ($mode, $oldHost, $newHost) {
                $node['new_host'] = $this->replacement($mode, $node['host'], $oldHost, $newHost);
                return $node;
            }, $matches),
        ];
    }

    public function replace(string $mode, string $oldHost, string $newHost): array
    {
        return DB::transaction(function () use ($mode, $oldHost, $newHost) {
            $updated = [];

            foreach ($this->findMatches($mode, $oldHost) as $match) {
                $model = self::MODELS[$match['type']];
                $server = $model::whereKey($match['id'])->lockForUpdate()->first();
                if (!$server || !$this->matches($mode, (string) $server->host, $oldHost)) {
                    continue;
                }

                $replacement = $this->replacement($mode, (string) $server->host, $oldHost, $newHost);
                if ($replacement === (string) $server->host) {
                    continue;
                }
                $originalHost = (string) $server->host;
                $server->host = $replacement;
                $persistedServer = $server->save() ? $server->fresh() : null;
                if (!$persistedServer || (string) $persistedServer->host !== $replacement) {
                    abort(500, __('节点域名保存失败，本次替换已回滚'));
                }
                $updated[] = [
                    'id' => (int) $server->id,
                    'type' => $match['type'],
                    'name' => (string) $server->name,
                    'old_host' => $originalHost,
                    'new_host' => $replacement,
                ];
            }

            return [
                'mode' => $mode,
                'old_host' => $oldHost,
                'new_host' => $newHost,
                'matched_count' => count($updated),
                'updated_count' => count($updated),
                'nodes' => $updated,
            ];
        });
    }

    private function findMatches(string $mode, string $oldHost): array
    {
        $matches = [];
        foreach (self::MODELS as $type => $model) {
            $servers = $model::query()->get(['id', 'name', 'host', 'rate', 'show']);
            foreach ($servers as $server) {
                $host = (string) $server->host;
                if (!$this->matches($mode, $host, $oldHost)) {
                    continue;
                }
                $matches[] = [
                    'id' => (int) $server->id,
                    'type' => $type,
                    'name' => (string) $server->name,
                    'host' => $host,
                    'rate' => $server->rate,
                    'show' => (int) $server->show,
                ];
            }
        }
        return $matches;
    }

    private function matches(string $mode, string $host, string $oldHost): bool
    {
        if ($mode === self::MODE_EXACT) {
            return $host === $oldHost;
        }
        return $mode === self::MODE_CONTAINS && strpos($host, $oldHost) !== false;
    }

    private function replacement(string $mode, string $host, string $oldHost, string $newHost): string
    {
        $replacement = $mode === self::MODE_EXACT ? $newHost : str_replace($oldHost, $newHost, $host);
        if (strlen($replacement) > 255) {
            abort(422, __('替换后的节点域名超过255个字符'));
        }
        return $replacement;
    }
}
