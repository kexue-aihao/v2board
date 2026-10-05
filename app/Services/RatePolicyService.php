<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/** Scene policies share counters across nodes, never across subscription identities. */
class RatePolicyService
{
    public const CONFIG = 'v2board_rate_policy_config';
    public const SAMPLES = 'v2board_rate_policy_samples';
    public const MULTIPLIERS = 'v2board_rate_policy_multipliers';
    public const LAST_TICK = 'v2board_rate_policy_last_tick';
    public const STATE_TTL = 180;

    public function ready(): bool
    {
        return Schema::hasTable('v2_rate_policy_state') && Schema::hasTable('v2_rate_policy')
            && Schema::hasTable('v2_rate_node_policy') && Schema::hasTable(DynamicRateService::TABLE_SETTING)
            && DB::table(DynamicRateService::TABLE_SETTING)
                ->whereIn('setting_key', ['policy_config_revision', 'global_policy_revision'])->count() === 2;
    }

    public function requireReady(): void
    {
        if (!$this->ready()) abort(503, '请先执行 php artisan v2board:update 完成倍率策略升级');
    }

    /** Serialize configuration edits and ticks, including Redis publication. */
    private function locked(callable $callback)
    {
        return DB::transaction(function () use ($callback) {
            DB::table(DynamicRateService::TABLE_SETTING)->where('setting_key', 'policy_config_revision')->lockForUpdate()->first();
            return $callback();
        });
    }

    public function mutate(callable $callback)
    {
        if (!$this->ready()) return $callback();
        $nested = DB::transactionLevel() > 0;
        return $this->locked(function () use ($callback, $nested) {
            $result = $callback();
            $this->bump('policy_config_revision');
            if ($nested) {
                // Model deletion / ID edits may be inside a larger batch transaction.
                // Never publish changes that the enclosing operation could roll back.
                DB::afterCommit(function () {
                    $this->locked(function () { $this->publish($this->configuration()); });
                });
            } else $this->publish($this->configuration());
            return $result;
        });
    }

    private function bump(string $key): void
    {
        $value = (int) DB::table(DynamicRateService::TABLE_SETTING)->where('setting_key', $key)->value('setting_value');
        DB::table(DynamicRateService::TABLE_SETTING)->where('setting_key', $key)
            ->update(['setting_value' => (string) ($value + 1), 'updated_at' => time()]);
    }

    public function resetGlobal(): void
    {
        if ($this->ready()) $this->bump('global_policy_revision');
    }

    public function policies(): array
    {
        if (!$this->ready()) return [];
        $counts = DB::table('v2_rate_node_policy')->where('mode', 'policy')
            ->selectRaw('policy_id, COUNT(*) AS total')->groupBy('policy_id')->pluck('total', 'policy_id');
        return DB::table('v2_rate_policy')->orderBy('id')->get()->map(function ($row) use ($counts) {
            return ['id' => (int) $row->id, 'name' => $row->name, 'revision' => (int) $row->revision,
                'node_count' => (int) ($counts[$row->id] ?? 0)] +
                (new RatePeakStateMachine())->normalizeParams(json_decode($row->settings, true) ?: []);
        })->all();
    }

    public function save(array $values): array
    {
        $this->requireReady();
        return $this->mutate(function () use ($values) {
            $params = (new RatePeakStateMachine())->normalizeParams($values);
            $attributes = ['name' => $values['name'], 'settings' => json_encode($params), 'updated_at' => time()];
            if (!empty($values['id'])) {
                $old = DB::table('v2_rate_policy')->where('id', $values['id'])->first();
                if (!$old) abort(404, '倍率策略不存在');
                if ((int) $old->revision !== (int) ($values['revision'] ?? 0)) abort(409, '策略已被修改，请刷新后重试');
                $attributes['revision'] = (int) $old->revision + 1;
                SecurityAuditMutation::update(DB::table('v2_rate_policy')->where('id', $old->id), $attributes);
                $id = (int) $old->id;
            } else {
                $id = SecurityAuditMutation::insertGetId(DB::table('v2_rate_policy'), $attributes + ['revision' => 1, 'created_at' => time()]);
            }
            return ['id' => $id];
        });
    }

    public function drop(int $id, int $revision): bool
    {
        $this->requireReady();
        return $this->mutate(function () use ($id, $revision) {
            $policy = DB::table('v2_rate_policy')->where('id', $id)->first();
            if (!$policy) abort(404, '倍率策略不存在');
            if ((int) $policy->revision !== $revision) abort(409, '策略已被修改，请刷新后重试');
            if (DB::table('v2_rate_node_policy')->where('policy_id', $id)->exists()) abort(409, '请先解除节点绑定，再删除策略');
            $count = DB::table('v2_rate_policy_state')->where('policy_id', $id)->delete();
            SecurityAuditService::effect('清理倍率策略运行状态', ['策略编号' => $id, '删除数量' => $count]);
            return SecurityAuditMutation::delete(DB::table('v2_rate_policy')->where('id', $id)) > 0;
        });
    }

    public function configuration(): array
    {
        $service = new DynamicRateService();
        $policies = [0 => ['id' => 0, 'name' => '全局策略',
            'revision' => (int) DB::table(DynamicRateService::TABLE_SETTING)->where('setting_key', 'global_policy_revision')->value('setting_value')]
            + $service->settings()];
        foreach ($this->policies() as $policy) $policies[$policy['id']] = $policy;
        $bindings = [];
        foreach (DB::table('v2_rate_node_policy')->get() as $row) {
            $bindings[$row->node_type . ':' . $row->node_id] = ['mode' => $row->mode, 'policy_id' => (int) $row->policy_id];
        }
        return ['revision' => (int) DB::table(DynamicRateService::TABLE_SETTING)->where('setting_key', 'policy_config_revision')->value('setting_value'),
            'policies' => $policies, 'bindings' => $bindings, 'rules' => $service->rules(true)];
    }

    private function publish(array $config): void
    {
        if (!Redis::set(self::CONFIG, json_encode($config, JSON_UNESCAPED_UNICODE))) {
            throw new \RuntimeException('倍率配置发布失败，请重试');
        }
        SecurityAuditService::effect('倍率配置已发布到运行缓存', ['配置版本' => $config['revision'] ?? null], 'success', true);
    }

    /** No process-local cache: successful edits reach the next traffic report. */
    public function runtime(): ?array
    {
        try {
            $config = json_decode((string) Redis::get(self::CONFIG), true);
            return is_array($config) && isset($config['policies'][0], $config['bindings'], $config['rules']) ? $config : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function policyFor(array $config, string $type, int $id): ?array
    {
        $binding = $config['bindings'][$type . ':' . $id] ?? ['mode' => 'global', 'policy_id' => 0];
        if ($binding['mode'] === 'off') return null;
        return $config['policies'][$binding['mode'] === 'policy' ? $binding['policy_id'] : 0] ?? null;
    }

    public function multipliers(?array $policy, array $userIds): array
    {
        if (!$policy || !(int) $policy['enabled'] || !$userIds) return [];
        try {
            $fields = array_map(function ($id) use ($policy) { return $policy['id'] . ':' . $policy['revision'] . ':' . $id; }, $userIds);
            $values = Redis::hmget(self::MULTIPLIERS, $fields);
            $result = [];
            foreach (array_values($userIds) as $index => $id) {
                $value = (float) ($values[$index] ?? 1);
                $result[(int) $id] = is_finite($value) && $value >= 1 ? $value : 1.0;
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function annotate(array $nodes): array
    {
        if (!$this->ready()) return $nodes;
        $config = $this->configuration();
        foreach ($nodes as &$node) {
            $binding = $config['bindings'][$node['type'] . ':' . $node['id']] ?? ['mode' => 'global', 'policy_id' => 0];
            $policy = $this->policyFor($config, $node['type'], (int) $node['id']);
            $node['rate_policy'] = $binding + ['name' => $policy ? $policy['name'] : '不参与带宽动态加倍', 'enabled' => $policy ? (int) $policy['enabled'] : 0];
        }
        return $nodes;
    }

    public function forgetNode(string $type, int $id): void
    {
        if (!$this->ready() || !DB::table('v2_rate_node_policy')->where('node_type', $type)->where('node_id', $id)->exists()) return;
        // A model's created/deleted event can run after an autocommitted write.
        // Commit cleanup before publication; Redis failure must not preserve a
        // binding to a deleted or reused ID. The next tick republishes on recovery.
        DB::transaction(function () use ($type, $id) {
            $this->mutate(function () use ($type, $id) {
                SecurityAuditMutation::delete(DB::table('v2_rate_node_policy')->where('node_type', $type)->where('node_id', $id), 'node_id');
            });
        });
    }

    public function bind(array $selection, string $mode, int $policyId, ?int $expectedRevision = null): array
    {
        $this->requireReady();
        $operation = function () use ($selection, $mode, $policyId, $expectedRevision) {
            $config = $this->configuration();
            if ($expectedRevision !== null && $config['revision'] !== $expectedRevision) abort(409, '倍率配置已变更，请重新预览');
            if ($mode === 'policy' && !isset($config['policies'][$policyId])) abort(422, '请选择有效的场景策略');
            $label = $mode === 'policy' ? $config['policies'][$policyId]['name'] : ($mode === 'off' ? '不参与带宽动态加倍' : '全局策略');
            $nodes = []; $seen = [];
            // Lock in a stable order to match other batch operations.
            usort($selection, function ($a, $b) { return [$a['type'], $a['id']] <=> [$b['type'], $b['id']]; });
            foreach ($selection as $item) {
                $key = $item['type'] . ':' . $item['id'];
                if (isset($seen[$key])) abort(422, '不能重复选择同一节点');
                $seen[$key] = true;
                $node = DB::table('v2_server_' . $item['type'])->where('id', $item['id'])->lockForUpdate()->first();
                if (!$node) abort(404, '所选节点不存在，请刷新列表');
                $old = $config['bindings'][$key] ?? ['mode' => 'global', 'policy_id' => 0];
                $changed = $old !== ['mode' => $mode, 'policy_id' => $policyId];
                $nodes[] = ['type' => $item['type'], 'id' => (int) $item['id'], 'name' => $node->name,
                    'old_name' => $old['mode'] === 'off' ? '不参与带宽动态加倍' : ($config['policies'][$old['policy_id']]['name'] ?? '策略已删除'),
                    'new_name' => $label, 'changed' => $changed];
                if ($expectedRevision !== null && $changed) {
                    if ($mode === 'global') SecurityAuditMutation::delete(DB::table('v2_rate_node_policy')->where('node_type', $item['type'])->where('node_id', $item['id']), 'node_id');
                    else SecurityAuditMutation::upsertOne(DB::table('v2_rate_node_policy'), ['node_type' => $item['type'], 'node_id' => $item['id']],
                        ['mode' => $mode, 'policy_id' => $mode === 'policy' ? $policyId : null]);
                }
            }
            return ['revision' => $config['revision'], 'mode' => $mode, 'policy_id' => $policyId, 'nodes' => $nodes,
                'matched_count' => count($nodes), 'changed_count' => count(array_filter($nodes, function ($row) { return $row['changed']; }))];
        };
        return $expectedRevision === null ? $this->locked($operation) : $this->mutate($operation);
    }

    public function tick(bool $dryRun = false): array
    {
        return $this->locked(function () use ($dryRun) {
            $now = time(); $config = $this->configuration();
            $last = (int) Redis::get(self::LAST_TICK);
            $elapsed = $last ? max(10, min(600, $now - $last)) : 60;
            if ($dryRun) $samples = Redis::hgetall(self::SAMPLES);
            else {
                // HGETALL + DEL must be atomic against concurrent queue workers.
                $flat = Redis::eval("local v = redis.call('HGETALL', KEYS[1]); redis.call('DEL', KEYS[1]); return v", 1, self::SAMPLES);
                $samples = [];
                for ($i = 0; $i < count($flat); $i += 2) $samples[$flat[$i]] = $flat[$i + 1];
            }
            $pending = [];
            foreach (DB::table('v2_rate_policy_state')->where(function ($q) { $q->where('high', '>', 0)->orWhere('burst', '>', 0)->orWhere('multiplier', '>', 1); })->get() as $row) {
                $pending[$row->policy_id . ':' . $row->node_user_id] = (array) $row;
            }
            $bytes = [];
            foreach ($samples as $field => $value) {
                if (!preg_match('/^(\d+):(\d+):([1-9]\d*)$/D', $field, $match)) continue;
                $policy = $config['policies'][(int) $match[1]] ?? null;
                if (!$policy || (int) $match[2] !== $policy['revision']) continue;
                $key = $match[1] . ':' . $match[3];
                $bytes[$key] = max(0, (int) $value);
                if (!isset($pending[$key])) {
                    $pending[$key] = ['policy_id' => (int) $match[1], 'node_user_id' => (int) $match[3]];
                }
            }
            $machine = new RatePeakStateMachine(); $advanced = []; $stacked = [];
            foreach ($pending as $key => $row) {
                $policy = $config['policies'][$row['policy_id']] ?? null;
                if (!$policy) continue;
                $state = (int) ($row['revision'] ?? 0) === $policy['revision'] && $now - ($row['computed_at'] ?? 0) <= self::STATE_TTL ? $row : [];
                $mbps = $machine->bytesToMbps($bytes[$key] ?? 0, $elapsed);
                $next = $machine->advance($state, $mbps, $policy);
                $row = ['policy_id' => $policy['id'], 'node_user_id' => (int) $row['node_user_id'], 'revision' => $policy['revision'],
                    'multiplier' => $next['applied'] ? $next['multiplier'] : 1.0, 'high' => $next['high'], 'burst' => $next['burst'],
                    'state' => $next['state'], 'rate_bps' => (int) round($mbps * 1000000), 'sampled_at' => $now, 'computed_at' => $now];
                $advanced[] = $row;
                if ($row['multiplier'] > 1) $stacked[$policy['id'] . ':' . $policy['revision'] . ':' . $row['node_user_id']] = (string) $row['multiplier'];
            }
            if (!$dryRun) {
                $this->persistStates($advanced);
                $this->publishMultipliers($stacked);
                $this->publish($config);
                Redis::set(self::LAST_TICK, (string) $now);
            }
            return ['sampled_seconds' => $elapsed, 'sampled_users' => count($bytes), 'advanced_users' => count($advanced),
                'stacked_users' => count($stacked), 'dry_run' => $dryRun];
        });
    }

    public function listStates(array $options = []): array
    {
        $query = DB::table('v2_rate_policy_state as r');
        $subscriptions = Schema::hasTable('v2_subscription');
        if ($subscriptions) {
            $query->leftJoin('v2_subscription as s', 's.node_user_id', '=', 'r.node_user_id')
                ->leftJoin('v2_user as u', 'u.id', '=', DB::raw('COALESCE(s.user_id, r.node_user_id)'));
        } else $query->leftJoin('v2_user as u', 'u.id', '=', 'r.node_user_id');
        if ($options['only_stacked'] ?? true) {
            $query->where(function ($q) { $q->where('r.multiplier', '>', 1)->orWhere('r.state', 'stacked'); });
        }
        if (isset($options['policy_id']) && $options['policy_id'] !== '') $query->where('r.policy_id', (int) $options['policy_id']);
        $keyword = trim((string) ($options['keyword'] ?? ''));
        if ($keyword !== '') $query->where(function ($q) use ($keyword) {
            $q->where('u.email', 'like', '%' . $keyword . '%')->orWhere('u.id', (int) $keyword);
        });
        $total = $query->count();
        $limit = max(1, min(200, (int) ($options['limit'] ?? 50)));
        $fields = ['r.*', 'u.id as account_id', 'u.email'];
        if ($subscriptions) $fields[] = 's.id as subscription_id';
        $rows = $query->select($fields)->orderByDesc('r.computed_at')->orderBy('r.policy_id')->orderBy('r.node_user_id')
            ->offset((max(1, (int) ($options['page'] ?? 1)) - 1) * $limit)->limit($limit)->get();
        $config = $this->configuration();
        return ['total' => $total, 'rows' => $rows->map(function ($row) use ($config) {
            $policy = $config['policies'][$row->policy_id] ?? null;
            $valid = $policy && (int) $row->revision === $policy['revision'] && time() - $row->computed_at <= self::STATE_TTL;
            return ['user_id' => $row->account_id ? (int) $row->account_id : null, 'email' => $row->email ?? '',
                'node_user_id' => (int) $row->node_user_id, 'subscription_id' => isset($row->subscription_id) ? (int) $row->subscription_id : null,
                'policy_id' => (int) $row->policy_id, 'policy_name' => $policy['name'] ?? '策略已删除',
                'multiplier' => $valid && $policy['enabled'] ? (float) $row->multiplier : 1.0,
                'high' => $valid ? (int) $row->high : 0, 'burst' => $valid ? (int) $row->burst : 0,
                'state' => $valid ? $row->state : 'normal', 'rate_bps' => (int) $row->rate_bps,
                'stack_minutes' => $policy['stack_minutes'] ?? 0, 'sampled_at' => (int) $row->sampled_at, 'computed_at' => (int) $row->computed_at];
        })->all()];
    }

    public function explain(int $userId, ?int $nodeUserId = null, ?int $at = null): array
    {
        if (!DB::table('v2_user')->where('id', $userId)->exists()) abort(404, '用户不存在');
        $subscription = null;
        if (Schema::hasTable('v2_subscription')) {
            $query = DB::table('v2_subscription')->where('user_id', $userId);
            if ($nodeUserId !== null) $query->where('node_user_id', $nodeUserId);
            else $query->orderByDesc('is_primary')->orderBy('id');
            $subscription = $query->first();
        }
        if ($nodeUserId !== null && !$subscription && $nodeUserId !== $userId) abort(422, '该订阅不属于所选用户');
        $nodeUserId = $subscription ? (int) $subscription->node_user_id : $userId;
        $config = $this->runtime();
        $published = $config !== null;
        $config = $config ?: $this->configuration();
        $at = $at ?: time(); $matcher = new RateRuleMatcher(); $factors = []; $nodes = [];
        foreach ($config['policies'] as $id => $policy) $factors[$id] = $published ? ($this->multipliers($policy, [$nodeUserId])[$nodeUserId] ?? 1.0) : 1.0;
        foreach (array_keys(ServerIdService::TYPES) as $type) {
            if (!Schema::hasTable('v2_server_' . $type)) continue;
            foreach (DB::table('v2_server_' . $type)->orderBy('id')->get(['id', 'name', 'rate']) as $node) {
                $policy = $this->policyFor($config, $type, (int) $node->id);
                $band = $published ? $matcher->multiplierFor($config['rules'], $type, (int) $node->id, $at) : 1.0;
                $user = $policy ? $factors[$policy['id']] : 1.0;
                $rate = (float) $node->rate > 0 ? (float) $node->rate : 1.0;
                $nodes[] = ['type' => $type, 'id' => (int) $node->id, 'name' => $node->name, 'rate' => $rate,
                    'policy_id' => $policy ? $policy['id'] : null, 'policy_name' => $policy ? $policy['name'] : '不参与带宽动态加倍',
                    'band_multiplier' => $band, 'user_multiplier' => $user, 'effective' => round($rate * $band * $user, 2)];
            }
        }
        return ['user_id' => $userId, 'node_user_id' => $nodeUserId, 'subscription_id' => $subscription ? (int) $subscription->id : null,
            'runtime_available' => $published, 'nodes' => $nodes, 'state' => null, 'user_multiplier' => $factors[0],
            'rules_matched_now' => $matcher->matchesAt($config['rules'], '', 0, $at),
            'global_multiplier' => $published ? $matcher->globalMultiplier($config['rules'], $at) : 1.0,
            'settings' => $config['policies'][0]];
    }

    private function persistStates(array $rows): void
    {
        if (!$rows) return;
        $columns = array_keys($rows[0]);
        foreach (array_chunk($rows, 500) as $chunk) {
            $bindings = [];
            foreach ($chunk as $row) foreach ($columns as $column) $bindings[] = $row[$column];
            $updates = [];
            foreach (array_slice($columns, 2) as $column) {
                $updates[] = '`' . $column . '` = ' . (DB::getDriverName() === 'sqlite' ? 'excluded.`' . $column . '`' : 'VALUES(`' . $column . '`)');
            }
            DB::statement('INSERT INTO v2_rate_policy_state (`' . implode('`,`', $columns) . '`) VALUES ' .
                implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, count($columns), '?')) . ')')) .
                (DB::getDriverName() === 'sqlite' ? ' ON CONFLICT(policy_id,node_user_id) DO UPDATE SET ' : ' ON DUPLICATE KEY UPDATE ') . implode(',', $updates), $bindings);
        }
    }

    private function publishMultipliers(array $values): void
    {
        if (!$values) { Redis::del(self::MULTIPLIERS); return; }
        $temporary = self::MULTIPLIERS . ':build:' . bin2hex(random_bytes(8));
        Redis::pipeline(function ($pipe) use ($temporary, $values) {
            foreach ($values as $field => $value) $pipe->hset($temporary, $field, $value);
            $pipe->expire($temporary, self::STATE_TTL);
        });
        Redis::rename($temporary, self::MULTIPLIERS);
    }
}
