<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\SubscribeBlockRule;
use App\Models\SubscribeBlockRuleEvent;
use App\Models\SubscribeRequestLog;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 管理端订阅风控网关。目标必须来自已有拉取审计，避免把这个接口变成任意封禁入口。
 */
class RiskGatewayController extends Controller
{
    private const AUDIT_TABLE = 'v2_subscribe_request_log';
    private const RULE_TABLE = 'v2_subscribe_block_rule';
    private const EVENT_TABLE = 'v2_subscribe_block_rule_event';
    private const PAGE_SIZE_DEFAULT = 20;
    private const PAGE_SIZE_MAX = 100;

    public function fetch(Request $request)
    {
        if (!$this->fetchAvailable()) {
            return $this->emptyList();
        }

        try {
            [$page, $pageSize] = $this->pagination($request);
            $query = SubscribeRequestLog::query();
            $userFilter = trim((string)$request->input('user'));
            if ($userFilter !== '') {
                if (ctype_digit($userFilter)) {
                    $query->where('user_id', (int)$userFilter);
                } else {
                    $userIds = User::where('email', 'like', '%' . $userFilter . '%')
                        ->limit(201)->pluck('id')->all();
                    if (!$userIds) {
                        return $this->emptyAvailableList();
                    }
                    $query->whereIn('user_id', $userIds);
                }
            }

            if ($request->filled('subscription_id')) {
                $subscriptionId = (int)$request->input('subscription_id');
                if ($subscriptionId <= 0) {
                    return $this->emptyAvailableList();
                }
                $query->where('subscription_id', $subscriptionId);
            }
            if ($request->filled('request_ip')) {
                $query->where('request_ip', trim((string)$request->input('request_ip')));
            }
            if ($request->filled('user_agent')) {
                $query->where('user_agent', 'like', '%' . trim((string)$request->input('user_agent')) . '%');
            }
            if (in_array($request->input('decision'), ['allowed', 'blocked', 'error'], true)) {
                $query->where('decision', $request->input('decision'));
            }
            $this->applyTimeWindow($query, $request, 'requested_at');

            $total = (int)(clone $query)->count();
            $logs = $query->orderByDesc('requested_at')->orderByDesc('id')
                ->forPage($page, $pageSize)->get($this->auditColumns());

            $users = $this->usersById($logs->pluck('user_id')->filter()->all());
            [$subscriptions, $plans] = $this->subscriptionsAndPlans($logs->pluck('subscription_id')->filter()->all());
            $rules = $this->rulesById($logs->pluck('block_rule_id')->filter()->all());

            $data = [];
            foreach ($logs as $log) {
                $subscription = $subscriptions->get((int)$log->subscription_id);
                $rule = $rules->get((int)$log->block_rule_id);
                $row = $this->auditRow($log);
                $row['user_email'] = ($users->get((int)$log->user_id))->email ?? null;
                $row['subscription_plan_name'] = $subscription
                    ? (($plans->get((int)$subscription->plan_id))->name ?? null) : null;
                $row['block_rule'] = $rule ? $this->ruleSummary($rule) : null;
                $data[] = $row;
            }

            return response(['data' => $data, 'total' => $total, 'available' => true]);
        } catch (\Throwable $e) {
            report($e);
            return $this->runtimeErrorList();
        }
    }

    public function rules(Request $request)
    {
        if (!$this->rulesAvailable()) {
            return $this->emptyList();
        }

        try {
            [$page, $pageSize] = $this->pagination($request);
            $query = SubscribeBlockRule::query();
            $scope = $request->input('scope');
            if (in_array($scope, ['subscription', 'user', 'ip', 'user_agent'], true)) {
                $query->where('scope', $scope);
            }

            $status = $request->input('status');
            $now = time();
            if ($status === 'active') {
                $query->where('status', 'active')->where(function ($q) use ($now) {
                    $q->whereNull('expires_at')->orWhere('expires_at', 0)->orWhere('expires_at', '>', $now);
                });
            } elseif ($status === 'disabled') {
                $query->where('status', 'disabled');
            } elseif ($status === 'expired') {
                $query->where('status', 'active')->where('expires_at', '>', 0)->where('expires_at', '<=', $now);
            }

            $keyword = trim((string)$request->input('keyword'));
            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword) {
                    $q->where('reason', 'like', '%' . $keyword . '%')
                        ->orWhere('ip', 'like', '%' . $keyword . '%')
                        ->orWhere('user_agent', 'like', '%' . $keyword . '%')
                        ->orWhere('user_agent_hash', 'like', '%' . $keyword . '%');
                    if (ctype_digit($keyword)) {
                        $q->orWhere('id', (int)$keyword)
                            ->orWhere('user_id', (int)$keyword)
                            ->orWhere('subscription_id', (int)$keyword);
                    }
                });
            }

            $total = (int)(clone $query)->count();
            $rules = $query->orderByDesc('id')->forPage($page, $pageSize)->get();
            $admins = $this->usersById(array_merge(
                $rules->pluck('blocked_by')->filter()->all(),
                $rules->pluck('released_by')->filter()->all()
            ));

            $data = [];
            foreach ($rules as $rule) {
                $row = $this->ruleRow($rule);
                $row['blocked_by_email'] = ($admins->get((int)$rule->blocked_by))->email ?? null;
                $row['released_by_email'] = ($admins->get((int)$rule->released_by))->email ?? null;
                $data[] = $row;
            }
            return response(['data' => $data, 'total' => $total, 'available' => true]);
        } catch (\Throwable $e) {
            return $this->emptyList();
        }
    }

    public function history(Request $request)
    {
        $ruleId = (int)$request->input('rule_id');
        if ($ruleId <= 0) {
            abort(404, __('规则不存在'));
        }
        if (!$this->hasRuleTable()) {
            $this->upgradeRequired();
        }

        try {
            $rule = SubscribeBlockRule::find($ruleId);
            if (!$rule) {
                abort(404, __('规则不存在'));
            }
            if (!$this->eventsAvailable()) {
                return response(['data' => [], 'available' => false]);
            }

            $events = SubscribeBlockRuleEvent::where('rule_id', $ruleId)->orderByDesc('id')->get();
            $admins = $this->usersById($events->pluck('actor_id')->filter()->all());
            $data = [];
            foreach ($events as $event) {
                $row = $this->withoutTokenFields($event->getAttributes());
                if (isset($row['metadata'])) {
                    $row['metadata'] = $this->safeMetadata($row['metadata']);
                }
                $email = ($admins->get((int)$event->actor_id))->email ?? null;
                $row['operator_email'] = $email;
                $row['actor_email'] = $email;
                $data[] = $row;
            }
            return response(['data' => $data, 'available' => true]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->upgradeRequired();
        }
    }

    public function block(Request $request)
    {
        $request->validate([
            'log_id' => 'required|integer|min:1',
            'scope' => 'required|in:subscription,user,ip,user_agent',
            'reason' => 'required|string|max:500',
            'expires_at' => 'nullable|integer'
        ]);
        if (!$this->blockAvailable()) {
            $this->upgradeRequired();
        }

        $expiresAt = $request->input('expires_at');
        if ($expiresAt !== null && (int)$expiresAt <= time()) {
            abort(500, __('过期时间必须是未来的 Unix 时间戳'));
        }

        try {
            return DB::transaction(function () use ($request, $expiresAt) {
                $log = SubscribeRequestLog::find((int)$request->input('log_id'));
                if (!$log) {
                    abort(404, __('订阅拉取审计记录不存在'));
                }
                $target = $this->targetFromLog($log, (string)$request->input('scope'));
                $existing = SubscribeBlockRule::where('scope', $target['scope'])
                    ->where($target['column'], $target['value'])
                    ->lockForUpdate()->get();
                foreach ($existing as $rule) {
                    if ($this->effectiveStatus($rule) === 'active') {
                        abort(500, __('该目标已有有效阻断规则'));
                    }
                }

                $rule = new SubscribeBlockRule();
                $this->setRuleAttribute($rule, 'scope', $target['scope']);
                $this->setRuleAttribute($rule, $target['column'], $target['value']);
                $this->setRuleAttribute($rule, 'user_id', $target['user_id']);
                $this->setRuleAttribute($rule, 'subscription_id', $target['subscription_id']);
                $this->setRuleAttribute($rule, 'ip', $target['ip']);
                $this->setRuleAttribute($rule, 'user_agent', $target['user_agent']);
                $this->setRuleAttribute($rule, 'user_agent_hash', $target['user_agent_hash']);
                $this->setRuleAttribute($rule, 'reason', trim((string)$request->input('reason')));
                $this->setRuleAttribute($rule, 'status', 'active');
                $this->setRuleAttribute($rule, 'expires_at', $expiresAt === null ? null : (int)$expiresAt);
                $this->setRuleAttribute($rule, 'blocked_by', $this->actorId($request));
                $this->setRuleAttribute($rule, 'blocked_at', time());
                $rule->save();

                $this->writeEvent($rule, 'block', $request, trim((string)$request->input('reason')), [
                    'scope' => $target['scope'],
                    'target_summary' => $target['summary'],
                    'source_log_id' => (int)$log->id
                ]);
                $this->audit($request, 'RISK GATEWAY BLOCK rule_id=' . $rule->id . ' log_id=' . $log->id
                    . ' scope=' . $target['scope']);

                return response(['data' => $this->ruleRow($rule), 'available' => true]);
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->upgradeRequired();
        }
    }

    public function release(Request $request)
    {
        $request->validate(['id' => 'required|integer|min:1', 'reason' => 'nullable|string|max:500']);
        if (!$this->blockAvailable()) {
            $this->upgradeRequired();
        }

        try {
            return DB::transaction(function () use ($request) {
                $rule = SubscribeBlockRule::where('id', (int)$request->input('id'))->lockForUpdate()->first();
                if (!$rule) {
                    abort(404, __('规则不存在'));
                }
                if (!in_array($this->effectiveStatus($rule), ['active', 'expired'], true)) {
                    abort(500, __('该规则已被解除'));
                }

                $reason = trim((string)$request->input('reason'));
                $this->setRuleAttribute($rule, 'status', 'disabled');
                $this->setRuleAttribute($rule, 'released_by', $this->actorId($request));
                $this->setRuleAttribute($rule, 'released_at', time());
                $this->setRuleAttribute($rule, 'release_reason', $reason === '' ? null : $reason);
                $rule->save();
                $this->writeEvent($rule, 'release', $request, $reason, ['scope' => (string)$rule->scope]);
                $this->audit($request, 'RISK GATEWAY RELEASE rule_id=' . $rule->id . ' scope=' . $rule->scope);

                return response(['data' => $this->ruleRow($rule), 'available' => true]);
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->upgradeRequired();
        }
    }

    private function fetchAvailable(): bool
    {
        return $this->hasAuditTable() && $this->hasRuleTable()
            && $this->hasColumns(self::AUDIT_TABLE, ['decision', 'block_rule_id', 'block_scope', 'block_reason']);
    }

    private function rulesAvailable(): bool
    {
        return $this->hasRuleTable() && $this->hasColumns(self::RULE_TABLE, [
            'scope', 'user_id', 'subscription_id', 'ip', 'user_agent', 'user_agent_hash', 'status', 'reason',
            'expires_at', 'blocked_by', 'blocked_at', 'released_by', 'released_at', 'release_reason'
        ]);
    }

    private function eventsAvailable(): bool
    {
        return $this->hasTable(self::EVENT_TABLE)
            && $this->hasColumns(self::EVENT_TABLE, ['rule_id', 'action', 'actor_id', 'reason', 'metadata', 'created_at']);
    }

    private function blockAvailable(): bool
    {
        return $this->fetchAvailable() && $this->rulesAvailable() && $this->eventsAvailable();
    }

    private function hasAuditTable(): bool
    {
        return $this->hasTable(self::AUDIT_TABLE);
    }

    private function hasRuleTable(): bool
    {
        return $this->hasTable(self::RULE_TABLE);
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasColumns(string $table, array $columns): bool
    {
        try {
            return Schema::hasColumns($table, $columns);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function pagination(Request $request): array
    {
        $page = max(1, (int)($request->input('current') ?: $request->input('page') ?: 1));
        $pageSize = min(self::PAGE_SIZE_MAX, max(1, (int)($request->input('pageSize') ?: self::PAGE_SIZE_DEFAULT)));
        return [$page, $pageSize];
    }

    private function applyTimeWindow($query, Request $request, string $column): void
    {
        $startAt = (int)$request->input('start_at');
        $endAt = (int)$request->input('end_at');
        if ($startAt > 0) {
            $query->where($column, '>=', $startAt);
        }
        if ($endAt > 0) {
            $query->where($column, '<=', $endAt);
        }
    }

    private function auditColumns(): array
    {
        return ['id', 'user_id', 'subscription_id', 'user_agent', 'ua_hash', 'request_ip', 'requested_at',
            'decision', 'block_rule_id', 'block_scope', 'block_reason', 'created_at', 'updated_at'];
    }

    private function auditRow(SubscribeRequestLog $log): array
    {
        return $this->withoutTokenFields($log->only($this->auditColumns()));
    }

    private function subscriptionsAndPlans(array $ids): array
    {
        $subscriptions = collect();
        $plans = collect();
        if (!$ids || !$this->hasTable('v2_subscription')) {
            return [$subscriptions, $plans];
        }
        $subscriptions = Subscription::whereIn('id', array_unique($ids))->get(['id', 'plan_id'])->keyBy('id');
        if ($subscriptions->count() && $this->hasTable('v2_plan')) {
            $plans = Plan::whereIn('id', $subscriptions->pluck('plan_id')->filter()->unique()->all())
                ->get(['id', 'name'])->keyBy('id');
        }
        return [$subscriptions, $plans];
    }

    private function usersById(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$this->hasTable('v2_user')) {
            return collect();
        }
        return User::whereIn('id', $ids)->get(['id', 'email'])->keyBy('id');
    }

    private function rulesById(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !$this->hasRuleTable()) {
            return collect();
        }
        return SubscribeBlockRule::whereIn('id', $ids)->get()->keyBy('id');
    }

    private function ruleSummary(SubscribeBlockRule $rule): array
    {
        return [
            'id' => (int)$rule->id,
            'scope' => $rule->scope,
            'target' => $this->ruleTarget($rule),
            'reason' => $rule->reason,
            'status' => $rule->status,
            'effective_status' => $this->effectiveStatus($rule),
            'expires_at' => $rule->expires_at ? (int)$rule->expires_at : null
        ];
    }

    private function ruleRow(SubscribeBlockRule $rule): array
    {
        $row = $this->withoutTokenFields($rule->getAttributes());
        // UA 规则靠哈希匹配；规则列表不回显原始 UA，避免把任意客户端字符串扩散到后台页面。
        unset($row['user_agent']);
        $row['target'] = $this->ruleTarget($rule);
        $row['effective_status'] = $this->effectiveStatus($rule);
        return $row;
    }

    private function ruleTarget(SubscribeBlockRule $rule)
    {
        switch ((string)$rule->scope) {
            case 'subscription':
                return $rule->subscription_id ? (string)(int)$rule->subscription_id : null;
            case 'user':
                return $rule->user_id ? (string)(int)$rule->user_id : null;
            case 'ip':
                return $rule->ip;
            case 'user_agent':
                return $rule->user_agent_hash;
            default:
                return null;
        }
    }

    private function effectiveStatus(SubscribeBlockRule $rule): string
    {
        if ((string)$rule->status === 'disabled') {
            return 'disabled';
        }
        return $rule->expires_at && (int)$rule->expires_at <= time() ? 'expired' : 'active';
    }

    private function targetFromLog(SubscribeRequestLog $log, string $scope): array
    {
        $base = ['scope' => $scope, 'user_id' => null, 'subscription_id' => null, 'ip' => null,
            'user_agent' => null, 'user_agent_hash' => null];
        if ($scope === 'subscription') {
            if (!(int)$log->subscription_id) {
                abort(500, __('该审计记录没有订阅，不能按订阅阻断'));
            }
            return array_merge($base, ['column' => 'subscription_id', 'value' => (string)(int)$log->subscription_id,
                'subscription_id' => (int)$log->subscription_id, 'summary' => 'subscription:' . (int)$log->subscription_id]);
        }
        if ($scope === 'user') {
            if (!(int)$log->user_id) {
                abort(500, __('该审计记录没有用户，不能按用户阻断'));
            }
            return array_merge($base, ['column' => 'user_id', 'value' => (string)(int)$log->user_id,
                'user_id' => (int)$log->user_id, 'summary' => 'user:' . (int)$log->user_id]);
        }
        if ($scope === 'ip') {
            $ip = trim((string)$log->request_ip);
            if ($ip === '' || strtolower($ip) === 'unknown' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                abort(500, __('该审计记录的请求 IP 无效，不能阻断'));
            }
            return array_merge($base, ['column' => 'ip', 'value' => $ip, 'ip' => $ip,
                'summary' => 'ip:' . $this->maskIp($ip)]);
        }
        $userAgent = trim((string)$log->user_agent);
        if ($userAgent === '' || $userAgent === '(empty)') {
            abort(500, __('该审计记录没有 User-Agent，不能阻断'));
        }
        $userAgentHash = hash('sha256', strtolower($userAgent));
        return array_merge($base, ['column' => 'user_agent_hash', 'value' => $userAgentHash,
            'user_agent' => $userAgent, 'user_agent_hash' => $userAgentHash,
            'summary' => 'ua:sha256:' . $userAgentHash]);
    }

    private function setRuleAttribute(SubscribeBlockRule $rule, string $column, $value): void
    {
        if ($this->hasColumns(self::RULE_TABLE, [$column])) {
            $rule->setAttribute($column, $value);
        }
    }

    private function writeEvent(SubscribeBlockRule $rule, string $type, Request $request, string $reason, array $metadata): void
    {
        $event = new SubscribeBlockRuleEvent();
        $event->setAttribute('rule_id', (int)$rule->id);
        $event->setAttribute('action', $type);
        $event->setAttribute('actor_id', $this->actorId($request));
        $event->setAttribute('reason', $reason === '' ? null : $reason);
        $event->setAttribute('metadata', $metadata);
        $event->setAttribute('created_at', time());
        $event->save();
    }

    private function actorId(Request $request): int
    {
        return is_array($request->user ?? null) ? (int)($request->user['id'] ?? 0) : 0;
    }

    private function safeMetadata($metadata)
    {
        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);
            $metadata = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }
        if (!is_array($metadata)) {
            return [];
        }
        foreach ($metadata as $key => $value) {
            if (stripos((string)$key, 'token') !== false) {
                unset($metadata[$key]);
            } elseif (is_array($value)) {
                $metadata[$key] = $this->safeMetadata($value);
            }
        }
        return $metadata;
    }

    private function withoutTokenFields(array $attributes): array
    {
        foreach (array_keys($attributes) as $key) {
            if (stripos((string)$key, 'token') !== false) {
                unset($attributes[$key]);
            }
        }
        return $attributes;
    }

    private function emptyList($key = 'data')
    {
        return response([$key => [], 'total' => 0, 'available' => false]);
    }

    private function emptyAvailableList($key = 'data')
    {
        return response([$key => [], 'total' => 0, 'available' => true]);
    }

    /**
     * A query failure must not be represented as a missing schema.  The
     * administrator needs an actionable message while the original exception
     * remains available in the application log for diagnosis.
     */
    private function runtimeErrorList($key = 'data')
    {
        return response([
            $key => [],
            'total' => 0,
            'available' => true,
            'error' => __('订阅风控网关暂时无法读取数据，请查看应用日志后重试')
        ]);
    }

    private function upgradeRequired(): void
    {
        abort(500, __('订阅风控网关表尚未安装，请先执行数据库升级'));
    }

    private function maskIp(string $ip): string
    {
        if (strpos($ip, ':') !== false) {
            return implode(':', array_slice(explode(':', $ip), 0, 2)) . ':x';
        }
        $parts = explode('.', $ip);
        return count($parts) === 4 ? $parts[0] . '.' . $parts[1] . '.x.x' : 'masked';
    }

    private function audit(Request $request, string $message): void
    {
        $actor = is_array($request->user ?? null) ? (string)($request->user['email'] ?? '-') : '-';
        info('ADMIN ' . $message . ' by=' . $actor);
    }
}
