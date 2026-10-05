<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SecurityAuditService
{
    public static function redact($value, int $depth = 0)
    {
        if ($depth > 12) return '[depth limit]';
        if ($value instanceof \Throwable) return ['exception_type' => get_class($value)];
        if (is_object($value)) return '[object ' . get_class($value) . ']';
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                if (count($out) >= 1000) { $out['_omitted_entries'] = count($value) - 1000; break; }
                if (preg_match('/password|passwd|secret|token|authorization|auth_data|cookie|private|recovery|api[_-]?key|credential|manual_key|qr_code|certificate|cert_pem|uuid|^key$|^code$|^user$|^message$|^content$|^body$|^headers$|^config$|custom_html|custom_script/i', (string)$key)) {
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
        return $value;
    }

    public static function append(string $event, string $result, array $details = [], ?array $actor = null, ?string $requestId = null): array
    {
        $request = app()->bound('request') ? request() : null;
        $context = $request ? $request->attributes->get('security_audit_context', []) : [];
        $actor = $actor ?? ($context['actor'] ?? null);
        $requestId = $requestId ?? ($context['request_id'] ?? bin2hex(random_bytes(16)));
        $action = $context['action'] ?? ($request && $request->route() ? $request->route()->getActionName() : '');
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

    public static function run(Request $request, ?array $actor, callable $callback)
    {
        $previous = $request->attributes->get('security_audit_context');
        $action = $request->route() ? $request->route()->getActionName() : $request->path();
        $context = ['actor' => $actor, 'request_id' => bin2hex(random_bytes(16)), 'action' => $action, 'changes' => [], 'statements' => []];
        $request->attributes->set('security_audit_context', $context);
        try {
            $before = SecurityAuditSnapshot::capture($request);
            // This intent commits before any filesystem, Redis or remote side effect.
            self::append('request.begin', 'pending', ['action' => $action, 'method' => $request->method(), 'input' => $request->except(['user', 'auth_data']), 'before' => $before]);
            $execute = function () use ($request, $callback, $action, $before) {
                $response = $callback();
                $context = $request->attributes->get('security_audit_context');
                $status = is_object($response) && method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
                $after = SecurityAuditSnapshot::capture($request, $response);
                // Store every batch item across bounded records, without silently
                // losing items to the general-purpose redaction size limit.
                foreach (array_chunk($context['changes'] ?? [], 100) as $index => $changes) {
                    self::append('business.changes', $status < 400 ? 'success' : 'failure', ['batch' => $index, 'changes' => $changes]);
                }
                $batchResult = null;
                if (strpos($action, 'Server\\ManageController@') !== false && !$request->isMethod('GET') && is_object($response) && method_exists($response, 'getContent')) {
                    $batchResult = json_decode($response->getContent(), true);
                }
                self::append('request.finish', $status < 400 ? 'success' : ($status === 403 ? 'denied' : 'failure'), [
                    'action' => $action, 'target_id' => $request->input('id'), 'status' => $status, 'change_count' => count($context['changes'] ?? []),
                    'after' => $after !== $before ? $after : null, 'batch_result' => $batchResult,
                    'statements' => $context['statements'] ?? [],
                ]);
                if (isset($response->headers)) $response->headers->set('X-Audit-Request-ID', $context['request_id']);
                return $response;
            };
            return $request->isMethod('GET') || $request->isMethod('HEAD') ? $execute() : DB::transaction($execute);
        } catch (\Throwable $error) {
            // Intent remains durable even when the business transaction rolls back.
            $status = method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 500;
            self::append('request.finish', $status === 403 ? 'denied' : 'failure', ['action' => $action,
                'target_id' => $request->input('id'),
                'status' => $status,
                'exception_type' => get_class($error)]);
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
        $context['changes'][] = self::redact([
            'table' => $model->getTable(), 'id' => $model->getKey(), 'operation' => $event,
            'before' => $event === 'created' ? null : $model->getRawOriginal(),
            'after' => $event === 'deleted' ? null : $model->getAttributes(),
        ]);
        $request->attributes->set('security_audit_context', $context);
    }
}
