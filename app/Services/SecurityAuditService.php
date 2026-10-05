<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SecurityAuditService
{
    public static function registerTransactions(): void
    {
        // A service may catch a savepoint failure and let the request continue.
        // Its model events must roll back with its SQL, including inside jobs.
        $keys = ['changes', 'statements', 'targets', 'initial_targets', 'tracked_tables', 'selected', 'decisions', 'unchanged', 'effects', 'business_result'];
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function ($event) use ($keys) {
            if (!self::active()) return;
            $context = request()->attributes->get('security_audit_context');
            $connection = $event->connection->getName();
            $context['transactions'][$connection][$event->connection->transactionLevel()] = array_intersect_key($context, array_flip($keys));
            request()->attributes->set('security_audit_context', $context);
        });
        foreach ([\Illuminate\Database\Events\TransactionCommitted::class, \Illuminate\Database\Events\TransactionRolledBack::class] as $eventClass) {
            \Illuminate\Support\Facades\Event::listen($eventClass, function ($event) use ($keys) {
                if (!self::active()) return;
                $context = request()->attributes->get('security_audit_context');
                $connection = $event->connection->getName();
                $level = $event->connection->transactionLevel();
                $frames = $context['transactions'][$connection] ?? [];
                ksort($frames);
                $restored = false;
                foreach ($frames as $depth => $snapshot) {
                    if ($depth <= $level) continue;
                    if (!$restored && $event instanceof \Illuminate\Database\Events\TransactionRolledBack) {
                        foreach ($keys as $key) unset($context[$key]);
                        $context = array_replace($context, $snapshot);
                        if (!empty($context['external_effects'])) $context['external_effects_replay'] = true;
                        $restored = true;
                    }
                    unset($context['transactions'][$connection][$depth]);
                }
                if (empty($context['transactions'][$connection])) unset($context['transactions'][$connection]);
                if (empty($context['transactions'])) unset($context['transactions']);
                request()->attributes->set('security_audit_context', $context);
            });
        }
    }

    public static function redact($value, int $depth = 0)
    {
        if ($depth > 12) return '[depth limit]';
        if ($value instanceof \Throwable) return ['exception_type' => get_class($value)];
        if (is_object($value)) return '[object ' . get_class($value) . ']';
        if (is_array($value)) {
            if (isset($value['key'], $value['value']) && is_string($value['key']) && SecurityAuditBusiness::secret($value['key'])) $value['value'] = '[redacted]';
            $out = [];
            foreach ($value as $key => $item) {
                if (count($out) >= 1000) { $out['_omitted_entries'] = count($value) - 1000; break; }
                if (preg_match('/password|passwd|secret|token|authorization|auth_data|cookie|private|recovery|api[_-]?key|credential|manual_key|qr_code|certificate|cert_pem|uuid|raw_uri|last_error|^error$|^payload$|^session$|session_id|^challenge$|^key$|^code$|^user$|^message$|^content$|^body$|^headers$|^config$|custom_html|custom_script/i', (string)$key)) {
                    $out[$key] = '[redacted]';
                } else {
                    $out[$key] = self::redact($item, $depth + 1);
                }
            }
            return $out;
        }
        if (is_string($value) && isset($value[0]) && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = json_decode($value, true, 12);
            if (is_array($decoded)) return self::redact($decoded, $depth + 1);
        }
        if (is_string($value) && strlen($value) > 8192) return '[large value sha256:' . hash('sha256', $value) . ']';
        if (is_string($value) && preg_match('~^(?:https?|trojan|vmess|vless|ss|tuic|hysteria2?|anytls)://~i', $value)) return SecurityAuditBusiness::url($value);
        return $value;
    }

    public static function append(string $event, string $result, array $details = [], ?array $actor = null, ?string $requestId = null): array
    {
        $request = app()->bound('request') ? request() : null;
        $context = $request ? $request->attributes->get('security_audit_context', []) : [];
        $actor = $actor ?? ($context['actor'] ?? null);
        $requestId = $requestId ?? ($context['request_id'] ?? bin2hex(random_bytes(16)));
        $action = $context['action'] ?? ($request && $request->route() ? $request->route()->getActionName() : '');
        $details = self::redact($details);
        $description = SecurityAuditDescription::describe($event, $details, $action);
        return DB::transaction(function () use ($event, $result, $details, $actor, $requestId, $request, $description) {
            $head = DB::table('v2_admin_audit_head')->where('id', 1)->lockForUpdate()->first();
            if (!$head) throw new RuntimeException('安全审计尚未初始化，请先运行数据库升级');
            $sequence = (int)$head->sequence + 1;
            $payload = [
                'version' => 1, 'sequence' => $sequence, 'request_id' => $requestId,
                'actor_id' => $actor['id'] ?? null, 'role' => $actor['admin_role'] ?? null,
                'event' => $event, 'result' => $result, 'time' => time(),
                'description' => $description,
                'ip' => $request ? $request->ip() : null,
                'user_agent' => $request ? substr((string)$request->userAgent(), 0, 512) : null,
                'details' => self::redact($details),
            ];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $hash = self::hash($head->hash, $json);
            DB::table('v2_admin_audit')->insert([
                'id' => $sequence, 'request_id' => $requestId,
                'actor_id' => $payload['actor_id'], 'role' => $payload['role'],
                'event' => $event, 'result' => $result, 'payload' => $json,
                'previous_hash' => $head->hash, 'hash' => $hash, 'created_at' => $payload['time'],
            ]);
            DB::table('v2_admin_audit_head')->where('id', 1)->update(['sequence' => $sequence, 'hash' => $hash]);
            return ['id' => $sequence, 'hash' => $hash];
        });
    }

    private static function hash(string $previous, string $payload): string
    {
        return hash_hmac('sha256', $previous . "\n" . $payload, (string)(config('admin_security.audit_key') ?: config('app.key')));
    }

    public static function verify(): array
    {
        $head = DB::table('v2_admin_audit_head')->where('id', 1)->first();
        if (!$head) throw new RuntimeException('安全审计未初始化');
        $previous = str_repeat('0', 64);
        $expected = 1;
        foreach (DB::table('v2_admin_audit')->where('id', '<=', $head->sequence)->orderBy('id')->cursor() as $row) {
            $payload = json_decode($row->payload, true);
            if ((int)$row->id !== $expected || $row->previous_hash !== $previous
                || !hash_equals(self::hash($previous, $row->payload), $row->hash)
                || !is_array($payload) || (int)($payload['sequence'] ?? 0) !== (int)$row->id
                || ($payload['request_id'] ?? null) !== $row->request_id
                || ($payload['actor_id'] ?? null) != $row->actor_id
                || ($payload['role'] ?? null) !== $row->role
                || ($payload['event'] ?? null) !== $row->event
                || ($payload['result'] ?? null) !== $row->result
                || (int)($payload['time'] ?? 0) !== (int)$row->created_at) {
                return ['valid' => false, 'failed_at' => $expected, 'checked' => $expected - 1];
            }
            $previous = $row->hash;
            $expected++;
        }
        return ['valid' => $expected - 1 === (int)$head->sequence && hash_equals($previous, $head->hash),
            'checked' => $expected - 1, 'sequence' => (int)$head->sequence, 'hash' => $head->hash];
    }

    public static function run(Request $request, ?array $actor, callable $callback, array $options = [])
    {
        $previous = $request->attributes->get('security_audit_context');
        $action = $options['action'] ?? ($request->route() ? $request->route()->getActionName() : $request->path());
        $context = ['actor' => $actor, 'request_id' => bin2hex(random_bytes(16)), 'action' => $action, 'changes' => [], 'statements' => [],
            'business' => SecurityAuditBusiness::definition($action, $request), 'channel' => $options['channel'] ?? '后台',
            'targets' => $options['targets'] ?? [], 'effects' => [], 'business_result' => []];
        $context['batch_id'] = $context['request_id'];
        $batch = $request->header('X-Audit-Batch-ID');
        if (is_string($batch) && preg_match('/^[a-f0-9]{32}$/', $batch)) $context['batch_id'] = $batch;
        $request->attributes->set('security_audit_context', $context);
        try {
            $before = SecurityAuditSnapshot::capture($request, null, false);
            // This intent commits before any filesystem, Redis or remote side effect.
            self::append('request.begin', 'pending', ['action' => $action, 'method' => $request->method(), 'input' => SecurityAuditBusiness::requestInput($request, $context['business']['table']), 'before' => SecurityAuditSnapshot::safe($before),
                'business' => SecurityAuditBusiness::envelope($request, $context, 'begin', 'pending')]);
            $execute = function () use ($request, $callback, $action, $before) {
                $response = $callback();
                $status = is_object($response) && method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
                $after = SecurityAuditSnapshot::capture($request, $response, false);
                self::snapshotChanges($before, $after);
                $context = $request->attributes->get('security_audit_context');
                self::replayExternalEffects($context);
                $outcome = SecurityAuditBusiness::outcome($response, $context);
                $context['business_result'] = $outcome['result'];
                self::batchItems($outcome['body'], $context);
                $request->attributes->set('security_audit_context', $context);
                $state = $outcome['state'];
                if ($state === 'success' && ($context['business_result']['skipped_count'] ?? 0) > 0) $state = 'partial';
                if ($state === 'success' && in_array($context['business']['operation'], ['update', 'delete'], true)
                    && !$context['changes'] && !$context['effects'] && (!empty($context['tracked_tables']) || !$context['statements'])) $state = 'no_change';
                $result = in_array($state, ['failure', 'denied'], true) ? $state : 'success';
                $context['detail_records'] = [];
                // Store every batch item across bounded records, without silently
                // losing items to the general-purpose redaction size limit.
                foreach (array_chunk($context['changes'] ?? [], 100) as $index => $changes) {
                    $business = SecurityAuditBusiness::envelope($request, $context, 'changes', $state);
                    $business['items'] = array_values(array_filter(array_column($changes, 'business')));
                    $business['effects'] = []; $business['targets'] = [];
                    $record = self::append('business.changes', $result, ['action' => $action, 'batch' => $index, 'changes' => $changes, 'business' => $business]);
                    $context['detail_records'][] = $record['id'];
                }
                foreach (array_chunk($context['batch_items'] ?? [], 100) as $index => $items) {
                    $business = SecurityAuditBusiness::envelope($request, $context, 'batch', $state);
                    $business['items'] = $items; $business['effects'] = []; $business['targets'] = [];
                    $record = self::append('business.batch', $result, ['batch' => $index, 'business' => $business]);
                    $context['detail_records'][] = $record['id'];
                }
                self::persistEffects($context);
                $batchResult = null;
                if (strpos($action, 'Server\\ManageController@') !== false && !$request->isMethod('GET') && is_object($response) && method_exists($response, 'getContent')) {
                    $batchResult = json_decode($response->getContent(), true);
                }
                $business = SecurityAuditBusiness::envelope($request, $context, 'finish', $state);
                // The complete changes live in bounded records. Only a few fields
                // are needed to form the list summary, never duplicate a bulk payload.
                $business['items'] = self::summaryItems($context);
                self::append('request.finish', $result, [
                    'action' => $action, 'target_id' => $request->input('id'), 'status' => $status, 'change_count' => count($context['changes'] ?? []),
                    'after' => $after !== $before ? SecurityAuditSnapshot::safe($after) : null, 'batch_result' => $batchResult,
                    'statements' => $context['statements'] ?? [], 'business' => $business,
                ]);
                if (isset($response->headers)) $response->headers->set('X-Audit-Request-ID', $context['request_id']);
                return $response;
            };
            return $request->isMethod('GET') || $request->isMethod('HEAD') ? $execute() : DB::transaction($execute);
        } catch (\Throwable $error) {
            // Intent remains durable even when the business transaction rolls back.
            $status = method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 500;
            $context = $request->attributes->get('security_audit_context', $context);
            // Rolled-back SQL is an attempt, not an applied change. Durable
            // external effects have their own records and remain discoverable.
            $context['changes'] = []; $context['statements'] = []; $context['effects'] = $context['external_effects'] ?? [];
            $context['targets'] = array_values($context['initial_targets'] ?? ($options['targets'] ?? []));
            $context['business_result'] = ['reason' => self::failureReason($error), 'exception_type' => get_class($error)];
            self::replayExternalEffects($context);
            self::append('request.finish', $status === 403 ? 'denied' : 'failure', ['action' => $action,
                'target_id' => $request->input('id'),
                'status' => $status,
                'exception_type' => get_class($error), 'business' => SecurityAuditBusiness::envelope($request, $context, 'finish', $status === 403 ? 'denied' : 'failure')]);
            throw $error;
        } finally {
            if ($previous === null) $request->attributes->remove('security_audit_context');
            else $request->attributes->set('security_audit_context', $previous);
        }
    }

    public static function modelChange(string $event, $model): void
    {
        if (!app()->bound('request')) return;
        $request = request();
        $context = $request->attributes->get('security_audit_context');
        if (!$context || strpos($model->getTable(), 'v2_admin_audit') === 0) return;
        self::change($model->getTable(), $model->getKey(), $event,
            $event === 'created' ? null : $model->getRawOriginal(), $event === 'deleted' ? null : $model->getAttributes());
    }

    public static function active(): bool
    {
        return app()->bound('request') && (bool)request()->attributes->get('security_audit_context');
    }

    public static function select(string $table, $id, array $row): void
    {
        if (!self::active()) return;
        $context = request()->attributes->get('security_audit_context');
        // Safe snapshot for unchanged and skipped members; secrets have no values.
        $item = SecurityAuditBusiness::change($table, $id, 'created', null, $row);
        $context['selected'][$table . ':' . $id] = $item;
        request()->attributes->set('security_audit_context', $context);
    }

    public static function batchDecision(array $object, string $state, string $reason = ''): void
    {
        if (!self::active()) return;
        $context = request()->attributes->get('security_audit_context');
        $context['decisions'][] = ['object' => $object, 'operation' => $state,
            'operation_label' => ['skipped' => '跳过', 'unchanged' => '无变化', 'preview' => '预览，未执行'][$state] ?? '处理',
            'reason' => SecurityAuditBusiness::text($reason), 'fields' => []];
        request()->attributes->set('security_audit_context', $context);
    }

    private static function batchItems(?array $body, array &$context): void
    {
        $nodes = $body['data']['nodes'] ?? [];
        $preview = ($context['business']['operation'] ?? '') === 'preview';
        $resultNodes = [];
        $changed = [];
        foreach ($context['changes'] ?? [] as $change) $changed[$change['table'] . ':' . $change['id']] = true;
        foreach (array_merge(array_values($context['unchanged'] ?? []), $context['decisions'] ?? []) as $item) {
            $context['batch_items'][] = $item;
            $resultNodes[$item['object']['type'] . ':' . $item['object']['id']] = true;
        }
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (!is_array($node)) continue;
            $table = isset($node['type']) ? 'v2_server_' . strtolower($node['type']) : 'v2_external_node';
            $id = $node['id'] ?? $node['name'] ?? '';
            $key = $table . ':' . $id;
            $resultNodes[$key] = true;
            // Actual changes already have complete, verified model differences.
            if (!$preview && isset($changed[$key])) continue;
            if (!$preview && empty($node['skip_reason']) && !isset($node['applicable']) && !array_key_exists('changed', $node)) continue;
            $before = []; $after = [];
            foreach ($node as $field => $value) {
                if (strpos($field, 'new_') !== 0) continue;
                $name = substr($field, 4);
                $before[$name] = $node[$name] ?? null;
                $after[$name] = $value;
            }
            $item = SecurityAuditBusiness::change($table, $id, 'updated', $before ?: [], $after ?: $node);
            $item['object'] = SecurityAuditBusiness::object($table, $id, $node);
            $item['operation'] = $preview ? 'preview' : (!empty($node['skip_reason']) || isset($node['applicable']) && !$node['applicable'] ? 'skipped' : (empty($node['changed']) ? 'unchanged' : 'updated'));
            $item['operation_label'] = ['preview' => '预览，未执行', 'skipped' => '跳过', 'unchanged' => '无变化', 'updated' => '已修改'][$item['operation']];
            $item['reason'] = SecurityAuditBusiness::text($node['skip_reason'] ?? '');
            $context['batch_items'][] = $item;
        }
        foreach ($context['selected'] ?? [] as $key => $item) {
            if (isset($changed[$key]) || isset($resultNodes[$key])) continue;
            $item['operation'] = 'unchanged'; $item['operation_label'] = '无变化';
            foreach ($item['fields'] as &$field) {
                $field['before'] = $field['after']; $field['before_label'] = $field['after_label'];
            }
            unset($field);
            $context['batch_items'][] = $item;
        }
        if (!empty($context['batch_items'])) {
            $states = array_count_values(array_column($context['batch_items'], 'operation'));
            foreach (['skipped', 'unchanged'] as $state) if (isset($states[$state])) $context['business_result'][$state . '_count'] = $states[$state];
        }
    }

    public static function change(string $table, $id, string $event, ?array $before, ?array $after): void
    {
        if (!self::active() || strpos($table, 'v2_admin_audit') === 0) return;
        $request = request(); $context = $request->attributes->get('security_audit_context');
        $business = SecurityAuditBusiness::change($table, $id, $event, $before, $after);
        $context['tracked_tables'][$table] = true;
        if ($business['operation'] === 'unchanged') {
            $context['business_result']['unchanged_count'] = ($context['business_result']['unchanged_count'] ?? 0) + 1;
            $context['unchanged'][$table . ':' . $business['object']['identity']] = $business;
        } else $context['changes'][] = self::redact(['table' => $table, 'id' => $id, 'operation' => $event,
            'before' => SecurityAuditBusiness::snapshot($table, $before), 'after' => SecurityAuditBusiness::snapshot($table, $after), 'business' => $business]);
        if ($table === ($context['business']['table'] ?? null) && count($context['targets'] ?? []) < 20) {
            $target = $business['object']; $exists = false;
            if ($before !== null) {
                $oldTarget = SecurityAuditBusiness::object($table, $before['id'] ?? $id, $before);
                $oldKey = $table . ':' . $oldTarget['identity'];
                if (!isset($context['initial_targets'][$oldKey])) $context['initial_targets'][$oldKey] = $oldTarget;
            }
            foreach ($context['targets'] ?? [] as $item) if ($item['type'] === $target['type'] && $item['identity'] === $target['identity']) $exists = true;
            if (!$exists) $context['targets'][] = $target;
        }
        $request->attributes->set('security_audit_context', $context);
    }

    public static function result(array $result): void
    {
        if (!self::active()) return;
        $context = request()->attributes->get('security_audit_context');
        $context['business_result'] = array_replace($context['business_result'] ?? [], self::redact($result));
        request()->attributes->set('security_audit_context', $context);
    }

    public static function effect(string $label, array $values = [], string $state = 'success', bool $durable = false): void
    {
        if (!self::active()) return;
        $context = request()->attributes->get('security_audit_context');
        $effect = ['label' => $label, 'state' => $state, 'values' => self::redact($values)];
        if ($durable) {
            $context['external_effects'][] = $effect;
            request()->attributes->set('security_audit_context', $context);
            $business = SecurityAuditBusiness::envelope(request(), $context, 'effect', $state);
            $business['effects'] = [$effect]; $business['criteria'] = []; $business['items'] = [];
            self::append('business.effect', $state === 'failure' ? 'failure' : 'success', ['business' => $business]);
        } else {
            $context['effects'][] = $effect;
            request()->attributes->set('security_audit_context', $context);
        }
    }

    public static function fileChanged(string $table, string $identity, array $before, array $after): void
    {
        if (!self::active()) return;
        $item = SecurityAuditBusiness::change($table, $identity, 'updated', $before, $after);
        if ($item['operation'] === 'unchanged') return;
        self::effect('配置文件已写入', ['配置对象' => $identity, 'changes' => [$item]], 'success', true);
    }

    public static function replayExternalEffects(array &$context): void
    {
        if (empty($context['external_effects_replay'])) return;
        // Savepoint rollback can remove the original evidence row, while the
        // mail/file/Redis effect remains. Re-append bounded evidence segments.
        foreach (array_chunk($context['external_effects'] ?? [], 100) as $effects) {
            $business = SecurityAuditBusiness::envelope(request(), $context, 'effect', 'success');
            $business['effects'] = $effects;
            $business['items'] = [];
            foreach ($effects as $effect) foreach ($effect['values']['changes'] ?? [] as $item) $business['items'][] = $item;
            $business['result'] = ['reason' => '数据库回滚后保留已发生的外部效果，各项结果以明细为准'];
            self::append('business.effect', 'success', ['business' => $business]);
        }
        unset($context['external_effects_replay']);
        request()->attributes->set('security_audit_context', $context);
    }

    public static function persistEffects(array &$context): void
    {
        foreach (array_chunk($context['effects'] ?? [], 100) as $effects) {
            $business = SecurityAuditBusiness::envelope(request(), $context, 'effect', 'success');
            $business['effects'] = $effects;
            $business['effects_total'] = count($effects);
            $record = self::append('business.effect', 'success', ['business' => $business]);
            $context['detail_records'][] = $record['id'];
        }
    }

    public static function summaryItems(array $context): array
    {
        $items = [];
        $changes = $context['changes'] ?? [];
        $primary = array_values(array_filter($changes, function ($change) use ($context) { return ($change['table'] ?? '') === ($context['business']['table'] ?? ''); }));
        foreach ($primary ?: $changes as $change) {
            if (empty($change['business'])) continue;
            $item = $change['business']; $item['fields'] = array_slice($item['fields'], 0, 4);
            $items[] = $item;
            if (count($items) >= 2) break;
        }
        return $items;
    }

    private static function snapshotChanges(array $before, array $after): void
    {
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $table) {
            if ($table === 'theme') continue;
            if (!empty(request()->attributes->get('security_audit_context', [])['tracked_tables'][$table])) continue;
            if ($table === 'settings' || $table === 'values') {
                self::change($table === 'values' ? 'theme' : 'settings', $after['theme'] ?? $before['theme'] ?? 'global', 'updated', $before[$table] ?? [], $after[$table] ?? []);
                continue;
            }
            foreach (array_unique(array_merge(array_keys($before[$table] ?? []), array_keys($after[$table] ?? []))) as $id) {
                $a = $before[$table][$id] ?? null; $b = $after[$table][$id] ?? null;
                if ($a === $b) continue;
                self::change($table, $id, $a === null ? 'created' : ($b === null ? 'deleted' : 'updated'), $a, $b);
            }
        }
    }

    public static function failureReason(\Throwable $error): string
    {
        // Database/transport exceptions may embed bindings, response bodies or URLs.
        if ($error instanceof \Illuminate\Validation\ValidationException) return '提交参数未通过校验';
        if ($error instanceof \Illuminate\Auth\Access\AuthorizationException) return '管理员权限不足或已经变更';
        if ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) return SecurityAuditBusiness::text($error->getMessage());
        return '执行异常，请按异常类型与请求编号排查';
    }
}
