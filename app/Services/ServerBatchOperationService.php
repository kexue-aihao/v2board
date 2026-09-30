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
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use ParagonIE_Sodium_Compat as SodiumCompat;

/**
 * 节点管理页的批量操作：批量复制、批量填写 Server Name(SNI) / Server Address。
 *
 * 与 ServerHostReplacementService 保持同一套节奏：先 preview 让管理员核对逐节点的
 * 现值与目标值，再带 confirm 落库；落库后在事务内重新读取核对，核对不上直接回滚。
 *
 * 各类节点的 SNI 存放位置并不一致（见 SNI_STORAGE），Server Address 即 REALITY 的
 * 目标地址，只有 v2node 的表单有这个概念，因此只对 v2node 生效。
 */
class ServerBatchOperationService
{
    public const MAX_SELECTION = 200;

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

    /**
     * SNI 的存放位置。column 为 null 表示该类型没有 TLS 名称字段（明文节点）。
     * key 为 null 表示直接写列，否则写 tls_settings / tlsSettings 这个 JSON 列里的键。
     */
    private const SNI_STORAGE = [
        'shadowsocks' => null,
        'vmess' => ['column' => 'tlsSettings', 'key' => 'server_name'],
        'vless' => ['column' => 'tls_settings', 'key' => 'server_name'],
        'trojan' => ['column' => 'server_name', 'key' => null],
        'tuic' => ['column' => 'server_name', 'key' => null],
        'hysteria' => ['column' => 'server_name', 'key' => null],
        'anytls' => ['column' => 'server_name', 'key' => null],
        'v2node' => ['column' => 'tls_settings', 'key' => 'server_name'],
    ];

    /** REALITY 目标地址，仅 v2node 表单存在该字段。 */
    private const DEST_STORAGE = ['column' => 'tls_settings', 'key' => 'dest'];

    /**
     * 批量复制。副本一律先置为隐藏（show=0），避免复制出来就直接对外下发。
     *
     * @param array<int, array{type: string, id: int}> $selection
     */
    public function copyNodes(array $selection, bool $regenerateRealityKeys): array
    {
        return DB::transaction(function () use ($selection, $regenerateRealityKeys) {
            $created = [];

            foreach ($this->resolve($selection) as $entry) {
                $type = $entry['type'];
                $server = $entry['server'];

                $copy = $server->replicate();
                $copy->show = 0;
                if ($regenerateRealityKeys && $type === 'v2node') {
                    $copy->tls_settings = $this->withFreshRealityKeys((array) $copy->tls_settings, (int) $copy->tls);
                }

                if (!$copy->save()) {
                    abort(500, __('节点复制失败，本次操作已回滚'));
                }
                // 自动递增主键只在落库后才有值，这里读回来是为了把新 ID 回给前端，
                // 方便管理员直接找到刚复制出来的那批节点。
                $persisted = $copy->fresh();
                if (!$persisted) {
                    abort(500, __('节点复制失败，本次操作已回滚'));
                }

                $created[] = [
                    'source_id' => (int) $server->id,
                    'id' => (int) $persisted->id,
                    'type' => $type,
                    'name' => (string) $persisted->name,
                ];
            }

            return [
                'requested_count' => count($selection),
                'created_count' => count($created),
                'nodes' => $created,
            ];
        });
    }

    /**
     * 批量填写 Server Name(SNI) / Server Address 的预演：只读，不落库。
     *
     * 两个参数都可以为 null，表示「这一项不动」——批量操作里保持字段原样比强行清空
     * 更安全，管理员只会勾选自己确实要改的那个。
     */
    public function previewTlsFields(array $selection, ?string $serverName, ?string $dest): array
    {
        $nodes = [];
        $changed = 0;
        foreach ($this->load($selection) as $entry) {
            $described = $this->describe($entry, $serverName, $dest);
            if ($described['changes']) {
                $changed++;
            }
            $nodes[] = $described;
        }

        return [
            'server_name' => $serverName,
            'dest' => $dest,
            'matched_count' => count($nodes),
            'changed_count' => $changed,
            'nodes' => $nodes,
        ];
    }

    /**
     * 批量填写 Server Name(SNI) / Server Address。
     *
     * @param array<int, array{type: string, id: int}> $selection
     */
    public function applyTlsFields(array $selection, ?string $serverName, ?string $dest): array
    {
        if ($serverName === null && $dest === null) {
            abort(422, __('请至少填写 Server Name(SNI) 或 Server Address 中的一项'));
        }

        return DB::transaction(function () use ($selection, $serverName, $dest) {
            $updated = [];

            foreach ($this->resolve($selection) as $entry) {
                $type = $entry['type'];
                $server = $entry['server'];

                $before = $this->describe($entry, $serverName, $dest);
                if (!$before['changes']) {
                    continue;
                }

                if ($serverName !== null && self::SNI_STORAGE[$type] !== null) {
                    $this->writeField($server, self::SNI_STORAGE[$type], $serverName);
                }
                if ($dest !== null && $type === 'v2node') {
                    $this->writeField($server, self::DEST_STORAGE, $dest);
                }

                if (!$server->save()) {
                    abort(500, __('节点保存失败，本次操作已回滚'));
                }
                $persisted = $server->fresh();
                if (!$persisted) {
                    abort(500, __('节点保存失败，本次操作已回滚'));
                }
                $after = $this->describe(['type' => $type, 'server' => $persisted], $serverName, $dest);
                if ($after['changes']) {
                    abort(500, __('节点保存后复核不一致，本次操作已回滚'));
                }

                $updated[] = [
                    'id' => (int) $persisted->id,
                    'type' => $type,
                    'name' => (string) $persisted->name,
                    'server_name' => $after['server_name'],
                    'dest' => $after['dest'],
                    'changes' => $before['changes'],
                ];
            }

            return [
                'requested_count' => count($selection),
                'updated_count' => count($updated),
                'nodes' => $updated,
            ];
        });
    }

    /**
     * 描述单个节点在当前入参下会产生哪些改动。readonly，不修改模型。
     */
    private function describe(array $entry, ?string $serverName, ?string $dest): array
    {
        $type = $entry['type'];
        $server = $entry['server'];

        $currentSni = $this->readField($server, self::SNI_STORAGE[$type]);
        $currentDest = $type === 'v2node' ? $this->readField($server, self::DEST_STORAGE) : null;

        $changes = [];
        $sniApplies = $serverName !== null && self::SNI_STORAGE[$type] !== null;
        if ($sniApplies && $currentSni !== $serverName) {
            $changes[] = 'server_name';
        }
        $destApplies = $dest !== null && $type === 'v2node';
        if ($destApplies && $currentDest !== $dest) {
            $changes[] = 'dest';
        }

        return [
            'id' => (int) $server->id,
            'type' => $type,
            'name' => (string) $server->name,
            'server_name' => $currentSni,
            'dest' => $currentDest,
            'new_server_name' => $sniApplies ? $serverName : null,
            'new_dest' => $destApplies ? $dest : null,
            // 该项对这类节点不存在时说明原因，前端据此提示「不适用」而不是静默跳过
            'server_name_applicable' => self::SNI_STORAGE[$type] !== null,
            'dest_applicable' => $type === 'v2node',
            'changes' => $changes,
        ];
    }

    private function readField($server, ?array $storage): ?string
    {
        if ($storage === null) {
            return null;
        }
        if ($storage['key'] === null) {
            return (string) ($server->{$storage['column']} ?? '');
        }
        $bag = (array) ($server->{$storage['column']} ?? []);

        return (string) ($bag[$storage['key']] ?? '');
    }

    private function writeField($server, array $storage, string $value): void
    {
        if ($storage['key'] === null) {
            $server->{$storage['column']} = $value;
            return;
        }
        $bag = (array) ($server->{$storage['column']} ?? []);
        $bag[$storage['key']] = $value;
        $server->{$storage['column']} = $bag;
    }

    /**
     * 为副本重新生成一套 REALITY 密钥。
     *
     * 复制节点时若沿用原节点的 keypair，两台机器就共用同一个 REALITY 私钥，等于把
     * 原节点的身份复制了一份出去，所以默认给副本换一套。short_id 的推导方式与
     * V2nodeController::save() 保持一致，避免出现两处生成规则不同的密钥。
     */
    private function withFreshRealityKeys(array $tlsSettings, int $tls): array
    {
        if ($tls !== 2) {
            return $tlsSettings;
        }

        $keyPair = SodiumCompat::crypto_box_keypair();
        $tlsSettings['private_key'] = Helper::base64EncodeUrlSafe(SodiumCompat::crypto_box_secretkey($keyPair));
        $tlsSettings['public_key'] = Helper::base64EncodeUrlSafe(SodiumCompat::crypto_box_publickey($keyPair));
        $tlsSettings['short_id'] = substr(sha1($tlsSettings['private_key']), 0, 8);
        if (empty($tlsSettings['server_port'])) {
            $tlsSettings['server_port'] = '443';
        }

        return $tlsSettings;
    }

    /**
     * 预演用：不加锁，允许节点在此期间被改动（apply 时会重新加锁复核）。
     */
    private function load(array $selection): array
    {
        $entries = [];
        foreach ($this->normalize($selection) as $item) {
            $server = self::MODELS[$item['type']]::whereKey($item['id'])->first();
            if (!$server) {
                abort(422, __('选中的节点已不存在：:type #:id', ['type' => $item['type'], 'id' => $item['id']]));
            }
            $entries[] = ['type' => $item['type'], 'server' => $server];
        }

        return $entries;
    }

    /**
     * 落库用：在事务内加行锁后重新读取，避免预览与提交之间的并发改动被覆盖。
     */
    private function resolve(array $selection): array
    {
        $entries = [];
        foreach ($this->normalize($selection) as $item) {
            $server = self::MODELS[$item['type']]::whereKey($item['id'])->lockForUpdate()->first();
            if (!$server) {
                abort(422, __('选中的节点已不存在：:type #:id', ['type' => $item['type'], 'id' => $item['id']]));
            }
            $entries[] = ['type' => $item['type'], 'server' => $server];
        }

        return $entries;
    }

    /**
     * 去重 + 校验。同一个节点被勾中两次不应该复制出两个副本，也不应该被写两遍。
     */
    private function normalize(array $selection): array
    {
        if (!$selection) {
            abort(422, __('请先选择要操作的节点'));
        }
        if (count($selection) > self::MAX_SELECTION) {
            abort(422, __('一次最多操作 :max 个节点', ['max' => self::MAX_SELECTION]));
        }

        $normalized = [];
        $seen = [];
        foreach ($selection as $item) {
            $type = (string) ($item['type'] ?? '');
            $id = (int) ($item['id'] ?? 0);
            if (!isset(self::MODELS[$type]) || $id < 1) {
                abort(422, __('节点选择无效'));
            }
            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = ['type' => $type, 'id' => $id];
        }

        return $normalized;
    }
}
