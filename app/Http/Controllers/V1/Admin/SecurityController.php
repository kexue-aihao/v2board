<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminAccessService;
use App\Services\AuthService;
use App\Services\SecurityAuditService;
use App\Services\SecurityAuditDescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SecurityController extends Controller
{
    public function bootstrap(Request $request)
    {
        $role = $request->user['admin_role'];
        return response(['data' => [
            'role' => $role, 'role_label' => config('admin_security.roles')[$role],
            'version' => $request->user['admin_version'], 'user_id' => $request->user['id'],
            'menus' => AdminAccessService::menus($role), 'landing' => AdminAccessService::landing($role),
            'debug_exempt' => app()->environment(['local', 'testing']) || (bool)config('admin_security.debug_exempt'),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function asset(Request $request)
    {
        $role = $request->user['admin_role'];
        $path = resource_path('admin/build/' . $role . '.js');
        abort_unless(is_file($path), 503, '管理员资源尚未构建，请运行 node scripts/build-admin-security.cjs');
        // Admin middleware has already checked the current session and role.
        // An unchanged bundle can reuse the browser's copy after that check;
        // shared caches must never store it or serve it without validation.
        $script = file_get_contents($path);
        $response = response($script, 200, ['Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'private, no-cache, max-age=0, must-revalidate', 'Vary' => 'Authorization', 'X-Content-Type-Options' => 'nosniff']);
        $response->setEtag(hash('sha256', $role . ':' . $script));
        $response->isNotModified($request);
        return $response;
    }

    public function administrators(Request $request)
    {
        $query = User::where(function ($q) { $q->where('is_admin', 1)->orWhere('is_staff', 1)->orWhereNotNull('admin_role'); });
        if ($request->filled('email')) $query->where('email', 'like', '%' . $request->input('email') . '%');
        $rows = $query->orderBy('id')->paginate(min(100, max(1, (int)$request->input('page_size', 25))), ['id', 'email', 'is_admin', 'is_staff', 'admin_role', 'admin_version', 'banned']);
        $items = $rows->getCollection()->map(function ($user) {
            return ['id' => $user->id, 'email' => $user->email, 'role' => AdminAccessService::role($user),
                'banned' => (bool)$user->banned, 'protected' => (int)$user->id === 1];
        });
        return response(['data' => $items, 'total' => $rows->total(), 'roles' => array_intersect_key(config('admin_security.roles'), array_flip(AdminAccessService::ASSIGNABLE))]);
    }

    public function assignRole(Request $request)
    {
        $data = $request->validate(['user_id' => 'required|integer|min:2', 'role' => 'nullable|in:operations,finance,support,marketing']);
        return DB::transaction(function () use ($data) {
            $user = User::where('id', $data['user_id'])->lockForUpdate()->firstOrFail();
            AdminAccessService::changeRole($user, $data['role'] ?? null);
            return response(['data' => true]);
        });
    }

    public function planOptions()
    {
        return response(['data' => DB::table('v2_plan')->orderBy('id')->get([
            'id', 'name', 'month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price', 'three_year_price', 'onetime_price', 'reset_price',
        ])]);
    }

    public function groupOptions()
    {
        return response(['data' => DB::table('v2_server_group')->orderBy('id')->get(['id', 'name'])]);
    }

    public function planConfig()
    {
        return response(['data' => ['site' => [
            'currency' => config('v2board.currency', 'CNY'),
            'currency_symbol' => config('v2board.currency_symbol', '¥'),
        ]]]);
    }

    private function auditQuery(Request $request)
    {
        $data = $request->validate([
            'actor_id' => 'nullable|integer|min:1', 'event' => 'nullable|string|max:100',
            'result' => 'nullable|in:success,failure,pending,denied', 'request_id' => 'nullable|regex:/^[a-f0-9]{32}$/',
            'from' => 'nullable|integer|min:0', 'to' => 'nullable|integer|min:0',
            'keyword' => 'nullable|string|max:100',
            'module' => 'nullable|string|max:30', 'action' => 'nullable|string|max:200',
            'object' => 'nullable|string|max:160', 'field' => 'nullable|string|max:100',
            'state' => 'nullable|in:success,failure,pending,denied,partial,no_change,queued',
            'view' => 'nullable|in:records,operations',
            'batch_id' => 'nullable|regex:/^[a-f0-9]{32}$/', 'job_ref' => 'nullable|string|max:100',
        ]);
        $query = DB::table('v2_admin_audit');
        foreach (['actor_id', 'event', 'result', 'request_id'] as $key) if (isset($data[$key])) $query->where($key, $data[$key]);
        if (!empty($data['keyword'])) {
            $query->where(function ($query) use ($data) {
                $sqlite = DB::connection()->getDriverName() === 'sqlite';
                $description = "JSON_EXTRACT(payload, '$.description')";
                $action = "JSON_EXTRACT(payload, '$.details.action')";
                if (!$sqlite) { $description = 'JSON_UNQUOTE(' . $description . ')'; $action = 'JSON_UNQUOTE(' . $action . ')'; }
                $query->whereRaw($description . " LIKE ? ESCAPE '!'", [SecurityAuditDescription::searchPattern($data['keyword'])]);
                foreach (SecurityAuditDescription::legacySearchTerms($data['keyword']) as $term) {
                    $query->orWhereRaw($action . " LIKE ? ESCAPE '!'", [SecurityAuditDescription::searchPattern($term)]);
                    if (strpos($term, '\\') === false && strpos($term, '@') === false) $query->orWhere('event', $term);
                }
            });
        }
        if (isset($data['from'])) $query->where('created_at', '>=', $data['from']);
        if (isset($data['to'])) $query->where('created_at', '<=', $data['to']);
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $extract = function ($path) use ($sqlite) {
            $sql = "JSON_EXTRACT(payload, '" . $path . "')";
            return $sqlite ? $sql : 'JSON_UNQUOTE(' . $sql . ')';
        };
        foreach (['module', 'action', 'state'] as $key) if (!empty($data[$key])) $query->whereRaw($extract('$.details.business.' . $key) . ' = ?', [$data[$key]]);
        foreach (['batch_id', 'job_ref'] as $key) if (!empty($data[$key])) $query->whereRaw($extract('$.details.business.' . $key) . ' = ?', [$data[$key]]);
        if (!empty($data['object'])) {
            $query->where(function ($q) use ($sqlite, $data) {
                if ($sqlite) $q->whereRaw("EXISTS (SELECT 1 FROM json_each(payload, '$.details.business.items') item WHERE CAST(json_extract(item.value, '$.object.identity') AS TEXT) = ?)", [$data['object']])
                    ->orWhereRaw("EXISTS (SELECT 1 FROM json_each(payload, '$.details.business.targets') target WHERE CAST(json_extract(target.value, '$.identity') AS TEXT) = ?)", [$data['object']]);
                else $q->whereRaw("JSON_SEARCH(payload, 'one', ?, '!', '$.details.business.items[*].object.identity', '$.details.business.targets[*].identity') IS NOT NULL", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['object'])]);
            });
        }
        if (!empty($data['field'])) {
            if ($sqlite) $query->whereRaw("EXISTS (SELECT 1 FROM json_each(payload, '$.details.business.items') item, json_each(item.value, '$.fields') field WHERE json_extract(field.value, '$.field') = ?)", [$data['field']]);
            else $query->whereRaw("JSON_SEARCH(payload, 'one', ?, '!', '$.details.business.items[*].fields[*].field') IS NOT NULL", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $data['field'])]);
        }
        if (($data['view'] ?? '') === 'operations') {
            // Match the full evidence set first, then return its request's main
            // result. A field/object can appear only in a later change segment.
            $matches = clone $query;
            $query = DB::table('v2_admin_audit')->whereIn('request_id', $matches->select('request_id'));
            $query->where(function ($q) {
                $q->whereIn('event', ['request.finish', 'job.finish'])->orWhere(function ($q) {
                    $q->where('event', 'request.begin')->whereNotExists(function ($sub) {
                        $sub->selectRaw('1')->from('v2_admin_audit as completed')->whereColumn('completed.request_id', 'v2_admin_audit.request_id')->where('completed.event', 'request.finish');
                    });
                })->orWhere(function ($q) {
                    $q->whereNotIn('event', ['business.changes', 'business.batch', 'business.effect', 'request.begin', 'job.begin', 'job.changes', 'job.queued', 'job.denied'])
                        ->whereNotExists(function ($sub) { $sub->selectRaw('1')->from('v2_admin_audit as started')->whereColumn('started.request_id', 'v2_admin_audit.request_id')->whereIn('started.event', ['request.begin', 'request.finish']); });
                });
            });
            if (!empty($data['state'])) $query->whereRaw($extract('$.details.business.state') . ' = ?', [$data['state']]);
            if (!empty($data['result'])) $query->where('result', $data['result']);
            if (!empty($data['job_ref'])) $query->whereRaw($extract('$.details.business.job_ref') . ' = ?', [$data['job_ref']]);
        }
        return $query;
    }

    public function audit(Request $request)
    {
        $rows = $this->auditQuery($request)->orderByDesc('id')->paginate(min(100, max(1, (int)$request->input('page_size', 25))));
        return response(['data' => array_map([SecurityAuditDescription::class, 'row'], $rows->items()), 'total' => $rows->total()])->header('Cache-Control', 'private, no-store');
    }

    public function auditDetail(Request $request)
    {
        $data = $request->validate(['id' => 'required|integer|min:1', 'after' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:100', 'scope' => 'nullable|in:request,batch']);
        $record = DB::table('v2_admin_audit')->where('id', $data['id'])->first();
        abort_unless($record, 404, '审计记录不存在');
        // Freeze the upper bound before this read request creates its own finish.
        $related = DB::table('v2_admin_audit')->where('request_id', $record->request_id);
        $business = json_decode($record->payload, true)['details']['business'] ?? [];
        $batch = $business['batch_id'] ?? null;
        if (($data['scope'] ?? '') === 'batch' && $batch) {
            $extract = "JSON_EXTRACT(payload, '$.details.business.batch_id')";
            if (DB::connection()->getDriverName() !== 'sqlite') $extract = 'JSON_UNQUOTE(' . $extract . ')';
            $related = DB::table('v2_admin_audit')->whereRaw($extract . ' = ?', [$batch]);
        }
        $upper = (int)(clone $related)->max('id');
        $limit = (int)($data['limit'] ?? 100);
        $rows = $related->where('id', '>', (int)($data['after'] ?? 0))
            ->where('id', '<=', $upper)->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        return response(['data' => array_map([SecurityAuditDescription::class, 'row'], $rows->all()), 'request_id' => $record->request_id,
            'batch_id' => $batch, 'next_after' => $rows->last()->id ?? (int)($data['after'] ?? 0), 'has_more' => $hasMore])->header('Cache-Control', 'private, no-store');
    }

    public function verifyAudit()
    {
        $result = SecurityAuditService::verify();
        SecurityAuditService::result(['ok' => $result['valid'], 'checked_count' => $result['checked']]);
        SecurityAuditService::append('audit.verify', $result['valid'] ? 'success' : 'failure', $result);
        return response(['data' => $result]);
    }

    public function exportAudit(Request $request)
    {
        $request->validate(['after' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:10000']);
        $rows = $this->auditQuery($request)->where('id', '>', (int)$request->input('after', 0))->orderBy('id')->limit((int)$request->input('limit', 1000))->get();
        $lines = [];
        foreach ($rows as $row) $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        SecurityAuditService::result(['exported_count' => $rows->count(), 'first_record' => $rows->first()->id ?? null, 'last_record' => $rows->last()->id ?? null]);
        SecurityAuditService::append('audit.export', 'success', ['count' => $rows->count(), 'first' => $rows->first()->id ?? null, 'last' => $rows->last()->id ?? null]);
        return response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="security-audit.jsonl"', 'Cache-Control' => 'private, no-store']);
    }

    public function logout(Request $request)
    {
        (new AuthService(User::findOrFail($request->user['id'])))->removeAllSession();
        return response(['data' => true]);
    }
}
