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
use App\Utils\CacheKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 节点 ID 的指定与变更。
 *
 * 节点 id 是各自节点表的主键，同时被这几处引用，改 ID 时必须一起迁移：
 *  - 同表的 parent_id（父/子节点关系）
 *  - v2_stat_server.server_id（配合 server_type 定位节点，节点流量排行用它）
 *  - Redis 的 SERVER_{TYPE}_{ONLINE_USER|LAST_CHECK_AT|LAST_PUSH_AT}_{id}
 *
 * v2_server_log（只有 server_id、没有类型列）与 v2_node_connection_log（连接审计）
 * 属于历史记录，改了反而会写乱别人的历史，这里一律不动：改 ID 后旧记录仍挂在旧 ID 上。
 *
 * 另外本服务只改面板侧数据：节点端写死的 node_id（v2node 的 --node-id、
 * 其它节点配置文件里的 node_id）必须自行同步，否则节点会以旧 ID 上报而查不到。
 */
class ServerIdService
{
    /**
     * 节点类型 => [模型类, 统计表里该类型节点可能出现的 server_type]
     * server_type 存的是节点上报的 protocol，历史版本用过 v2ray / hysteria2 这类别名。
     */
    const TYPES = [
        'shadowsocks' => [ServerShadowsocks::class, ['shadowsocks']],
        'vmess' => [ServerVmess::class, ['vmess', 'v2ray']],
        'vless' => [ServerVless::class, ['vless']],
        'trojan' => [ServerTrojan::class, ['trojan']],
        'tuic' => [ServerTuic::class, ['tuic']],
        'hysteria' => [ServerHysteria::class, ['hysteria', 'hysteria2']],
        'anytls' => [ServerAnytls::class, ['anytls']],
        'v2node' => [ServerV2node::class, ['v2node']],
    ];

    private static function type(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            abort(500, __('不支持的节点类型'));
        }
        return self::TYPES[$type];
    }

    public static function assertIdAvailable(string $type, int $id): void
    {
        if ($id < 1) {
            abort(500, __('节点ID必须为正整数'));
        }
        $modelClass = self::type($type)[0];
        if ($modelClass::where('id', $id)->exists()) {
            abort(500, __('节点ID已被占用'));
        }
    }

    /**
     * 用指定 ID 新建节点。模型的 $guarded 里有 id，fill() 写不进去，这里直接赋值。
     */
    public static function createWithId(string $type, array $params, int $id): Model
    {
        self::assertIdAvailable($type, $id);
        $modelClass = self::type($type)[0];
        $model = new $modelClass();
        $model->fill($params);
        $model->setAttribute($model->getKeyName(), $id);
        $model->save();
        return $model;
    }

    /**
     * 变更已有节点的 ID：主键 + 子节点 parent_id + 统计表 server_id + 缓存键。
     * 统计表按 server_type 限定范围，避免误伤 id 相同的其它类型节点。
     */
    public static function changeId(string $type, Model $server, int $newId): void
    {
        $oldId = (int)$server->getKey();
        if ($newId === $oldId) {
            return;
        }
        self::assertIdAvailable($type, $newId);

        $statTypes = self::type($type)[1];
        $table = $server->getTable();

        (new RatePolicyService())->mutate(function () use ($table, $type, $statTypes, $oldId, $newId) {
            DB::transaction(function () use ($table, $type, $statTypes, $oldId, $newId) {
                DB::table($table)->where('id', $oldId)->update(['id' => $newId]);
                DB::table($table)->where('parent_id', $oldId)->update(['parent_id' => $newId]);
                DB::table('v2_stat_server')
                    ->where('server_id', $oldId)
                    ->whereIn('server_type', $statTypes)
                    ->update(['server_id' => $newId]);
                if ((new RatePolicyService())->ready()) {
                    DB::table('v2_rate_node_policy')->where('node_type', $type)->where('node_id', $newId)->delete();
                    DB::table('v2_rate_node_policy')->where('node_type', $type)->where('node_id', $oldId)->update(['node_id' => $newId]);
                    DB::table(DynamicRateService::TABLE_RULE)->where('scope', 'node')->where('node_type', $type)->where('node_id', $oldId)->update(['node_id' => $newId]);
                }
            });
        });

        self::migrateCacheKeys($type, $oldId, $newId);

        $keyName = $server->getKeyName();
        $server->setAttribute($keyName, $newId);
        $server->syncOriginalAttribute($keyName);
    }

    private static function migrateCacheKeys(string $type, int $oldId, int $newId): void
    {
        $prefix = strtoupper($type);
        foreach (['ONLINE_USER', 'LAST_CHECK_AT', 'LAST_PUSH_AT'] as $suffix) {
            $oldKey = CacheKey::get("SERVER_{$prefix}_{$suffix}", $oldId);
            if (!Cache::has($oldKey)) {
                continue;
            }
            $value = Cache::get($oldKey);
            Cache::forget($oldKey);
            Cache::put(CacheKey::get("SERVER_{$prefix}_{$suffix}", $newId), $value, 3600);
        }
    }
}
