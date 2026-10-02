<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * 外部订阅源：抓取、解析入库、以及在订阅组装时把节点按权限组挂进去。
 *
 * 几个刻意的取舍：
 *  - 抓取失败**绝不动已有的节点**。源临时抽风时用户订阅里少一批备用线路，比多一批
 *    连不上的线路更糟；只把错误写进 last_error，管理页能看到。
 *  - 导入的节点不进 v2_server_*，因此节点永远不会上报它们的流量 —— 不参与计费，
 *    也不占用户的流量额度，这一点不需要任何开关。
 *  - 注入的节点行 type 用 'external' 而不是 'v2node'：Clash / sing-box / 行式渲染器
 *    遇到不认识的 type 会静默跳过，于是「还没支持凭据覆盖的客户端」不会拿到一批
 *    用用户自己的 uuid 去连别人服务器的坏节点；而 v2ray 系渲染器都走
 *    Helper::buildUri()，那里直接吐原始 URI，一次覆盖 8 个客户端。
 */
class ExternalSubscriptionService
{
    public const TABLE_SOURCE = 'v2_external_source';
    public const TABLE_NODE = 'v2_external_node';

    /** 注入行的 type：不参与任何协议渲染，只走 Helper::buildUri() 的原始 URI 分支。 */
    public const EXTERNAL_TYPE = 'external';

    /** 用户看到的节点名前缀，用来和自建节点区分开。 */
    public const NAME_PREFIX = '【过渡】';

    public const FETCH_TIMEOUT = 10;
    public const MAX_BODY_BYTES = 2097152;

    private const USER_AGENT = 'v2board-external-subscription/1.0';

    /** @var Client|null 测试时注入带 MockHandler 的客户端，跑真实代码路径但不发真请求 */
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client;
    }

    // ---------------------------------------------------------------- 源

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sources(bool $onlyEnabled = false): array
    {
        if (!$this->hasTable(self::TABLE_SOURCE)) {
            return [];
        }
        $query = DB::table(self::TABLE_SOURCE);
        if ($onlyEnabled) {
            $query->where('enabled', 1);
        }

        return array_map(function ($row) {
            return (array) $row;
        }, $query->orderBy('id')->get()->all());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function saveSource(array $data, ?int $id = null): int
    {
        $attributes = $this->normalizeSource($data) + ['updated_at' => time()];
        if ($id !== null) {
            DB::table(self::TABLE_SOURCE)->where('id', $id)->update($attributes);

            return $id;
        }

        $attributes['created_at'] = time();

        return (int) DB::table(self::TABLE_SOURCE)->insertGetId($attributes);
    }

    public function deleteSource(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            DB::table(self::TABLE_NODE)->where('source_id', $id)->delete();

            return DB::table(self::TABLE_SOURCE)->where('id', $id)->delete() > 0;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeSource(array $data): array
    {
        $url = trim((string) ($data['url'] ?? ''));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
            abort(422, __('订阅链接必须是 http 或 https 地址'));
        }

        return [
            'name' => $this->text($data['name'] ?? '', 64) ?: (string) parse_url($url, PHP_URL_HOST),
            'url' => $this->text($url, 512),
            'group_id' => max(0, (int) ($data['group_id'] ?? 0)),
            'enabled' => (int) ($data['enabled'] ?? 1) === 1 ? 1 : 0,
            'remark' => $this->text($data['remark'] ?? '', 255)
        ];
    }

    // ---------------------------------------------------------------- 抓取

    /**
     * 抓一个源：拉取 → 解析 → 事务内整源替换。
     *
     * @return array<string, mixed>
     */
    public function refresh(int $sourceId, bool $dryRun = false): array
    {
        $source = DB::table(self::TABLE_SOURCE)->where('id', $sourceId)->first();
        if (!$source) {
            return ['source_id' => $sourceId, 'ok' => false, 'error' => '源不存在'];
        }

        try {
            $body = $this->download((string) $source->url);
            $parsed = (new ExternalSubscriptionParser())->parse($body);
            if ($parsed['count'] === 0) {
                throw new RuntimeException('没有解析出任何节点（可能是 Clash / sing-box 等暂不支持的订阅格式）');
            }
            if ($dryRun) {
                return [
                    'source_id' => $sourceId,
                    'name' => (string) $source->name,
                    'ok' => true,
                    'dry_run' => true,
                    'count' => $parsed['count'],
                    'skipped' => $parsed['skipped'],
                    'base64' => $parsed['base64'],
                    'nodes' => array_map(function (array $node) {
                        return [
                            'name' => $node['name'],
                            'protocol' => $node['protocol'],
                            'host' => $node['host'],
                            'port' => $node['port']
                        ];
                    }, $parsed['nodes'])
                ];
            }

            $now = time();
            DB::transaction(function () use ($sourceId, $parsed, $now) {
                DB::table(self::TABLE_NODE)->where('source_id', $sourceId)->delete();
                $rows = [];
                foreach ($parsed['nodes'] as $index => $node) {
                    $rows[] = [
                        'source_id' => $sourceId,
                        'name' => $node['name'],
                        'protocol' => $node['protocol'],
                        'host' => $node['host'],
                        'port' => (int) $node['port'],
                        'payload' => json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'enabled' => 1,
                        'sort' => $index,
                        'fetched_at' => $now
                    ];
                }
                foreach (array_chunk($rows, 200) as $chunk) {
                    DB::table(self::TABLE_NODE)->insert($chunk);
                }
            });

            DB::table(self::TABLE_SOURCE)->where('id', $sourceId)->update([
                'last_fetch_at' => $now,
                'last_status' => 'ok',
                'last_error' => null,
                'node_count' => $parsed['count'],
                'updated_at' => $now
            ]);

            return [
                'source_id' => $sourceId,
                'name' => (string) $source->name,
                'ok' => true,
                'count' => $parsed['count'],
                'skipped' => $parsed['skipped'],
                'base64' => $parsed['base64']
            ];
        } catch (\Throwable $e) {
            $message = $this->text($e->getMessage(), 500);
            if (!$dryRun) {
                // 只记错误，不动已经导入的节点：源抽风不该把用户的备用线路清空。
                DB::table(self::TABLE_SOURCE)->where('id', $sourceId)->update([
                    'last_fetch_at' => time(),
                    'last_status' => 'error',
                    'last_error' => $message,
                    'updated_at' => time()
                ]);
            }

            return ['source_id' => $sourceId, 'name' => (string) $source->name, 'ok' => false, 'error' => $message];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function refreshAll(bool $dryRun = false): array
    {
        $results = [];
        foreach ($this->sources() as $source) {
            if (!$dryRun && (int) $source['enabled'] !== 1) {
                continue;
            }
            $results[] = $this->refresh((int) $source['id'], $dryRun);
        }

        return $results;
    }

    private function download(string $url): string
    {
        $client = $this->client ?: new Client([
            'timeout' => self::FETCH_TIMEOUT,
            'connect_timeout' => 5,
            'http_errors' => false
        ]);
        $response = $client->get($url, [
            'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => '*/*']
        ]);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('源返回 HTTP ' . $status);
        }
        $body = (string) $response->getBody();
        if (trim($body) === '') {
            throw new RuntimeException('源返回空内容');
        }
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new RuntimeException('订阅体超过 ' . (self::MAX_BODY_BYTES / 1048576) . 'MB，已拒绝');
        }

        return $body;
    }

    /**
     * 某个源解析出来的节点（管理页预览用）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function nodesForSource(int $sourceId): array
    {
        if (!$this->hasTable(self::TABLE_NODE)) {
            return [];
        }

        return array_map(function ($row) {
            return (array) $row;
        }, DB::table(self::TABLE_NODE)->where('source_id', $sourceId)->orderBy('sort')->orderBy('id')->get()->all());
    }

    // ---------------------------------------------------------------- 注入订阅

    /**
     * 按权限组取出要下发的过渡节点，组装成订阅能用的节点行。
     *
     * id 取负数：订阅里的节点身份是 type + id，真实节点的 id 一定是正数，负数不可能撞上，
     * 免得不小心把用户的节点列表缓存（cache_key 含 id）串了。
     *
     * @param array<int, int|string> $groupIds
     * @return array<int, array<string, mixed>>
     */
    public function serverRowsForGroups(array $groupIds): array
    {
        $groupIds = array_values(array_filter(array_map('intval', $groupIds), function (int $id) {
            return $id > 0;
        }));
        if (!$groupIds || !$this->hasTable(self::TABLE_SOURCE) || !$this->hasTable(self::TABLE_NODE)) {
            return [];
        }

        $sources = DB::table(self::TABLE_SOURCE)
            ->where('enabled', 1)
            ->whereIn('group_id', $groupIds)
            ->orderBy('id')
            ->get();
        if ($sources->isEmpty()) {
            return [];
        }

        $rows = [];
        foreach ($sources as $source) {
            $nodes = DB::table(self::TABLE_NODE)
                ->where('source_id', (int) $source->id)
                ->where('enabled', 1)
                ->orderBy('sort')
                ->orderBy('id')
                ->get();
            foreach ($nodes as $node) {
                $payload = json_decode((string) $node->payload, true);
                $payload = is_array($payload) ? $payload : [];
                $rawUri = (string) ($payload['raw_uri'] ?? '');
                if ($rawUri === '') {
                    continue;
                }
                $rows[] = [
                    'id' => -1 * (int) $node->id,
                    'type' => self::EXTERNAL_TYPE,
                    'protocol' => (string) $node->protocol,
                    'name' => self::NAME_PREFIX . (string) $node->name,
                    'host' => (string) $node->host,
                    'port' => (int) $node->port,
                    // 不参与计费，但用户面板会显示倍率 —— 显示 1 比显示 0 更不容易被理解成「免费但计量」
                    'rate' => '1',
                    'show' => 1,
                    'sort' => 0,
                    // getAvailableServers() 之后会用这两个字段归一化出 is_online 与 cache_key：
                    // last_check_at 取当下（外部线路我们探测不到，按在线显示），
                    // updated_at 用抓取时间（cache_key 里带它，重抓后客户端缓存自然失效）。
                    'last_check_at' => time(),
                    'updated_at' => (int) $node->fetched_at,
                    'source_id' => (int) $source->id,
                    'source_name' => (string) $source->name,
                    // Helper::buildUri() 见到它就原样返回，不再走「按协议拼 URI」那条路
                    '_external_uri' => $rawUri
                ];
            }
        }

        return $rows;
    }

    private function text($value, int $max): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
