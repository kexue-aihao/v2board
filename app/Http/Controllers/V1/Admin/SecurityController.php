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
        return response(file_get_contents($path), 200, ['Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'private, no-store, max-age=0', 'Vary' => 'Authorization', 'X-Content-Type-Options' => 'nosniff']);
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
        return $query;
    }

    public function audit(Request $request)
    {
        $rows = $this->auditQuery($request)->orderByDesc('id')->paginate(min(100, max(1, (int)$request->input('page_size', 25))));
        return response(['data' => array_map([SecurityAuditDescription::class, 'row'], $rows->items()), 'total' => $rows->total()])->header('Cache-Control', 'private, no-store');
    }

    public function verifyAudit()
    {
        $result = SecurityAuditService::verify();
        SecurityAuditService::append('audit.verify', $result['valid'] ? 'success' : 'failure', $result);
        return response(['data' => $result]);
    }

    public function exportAudit(Request $request)
    {
        $request->validate(['after' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:10000']);
        $rows = $this->auditQuery($request)->where('id', '>', (int)$request->input('after', 0))->orderBy('id')->limit((int)$request->input('limit', 1000))->get();
        $lines = [];
        foreach ($rows as $row) $lines[] = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
