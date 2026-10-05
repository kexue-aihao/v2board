<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAccessService
{
    const ASSIGNABLE = ['operations', 'finance', 'support', 'marketing'];
    const SECURITY_FIELDS = ['admin_2fa_force_enable', 'admin_role', 'admin_version', 'is_admin', 'is_staff'];

    public static function role($user): ?string
    {
        if (!$user || !empty($user['banned']) || empty($user['is_admin'])) return null;
        if ((int)$user['id'] === 1) return 'super';
        $role = $user['admin_role'] ?? null;
        return in_array($role, self::ASSIGNABLE, true) ? $role : null;
    }

    public static function actor(User $user): array
    {
        return [
            'id' => (int)$user->id,
            'email' => $user->email,
            'is_admin' => self::role($user) !== null,
            'is_staff' => self::role($user) === 'support',
            'admin_role' => self::role($user),
            'admin_version' => (int)$user->admin_version,
        ];
    }

    public static function landing(string $role): string
    {
        return ['super' => '/dashboard', 'operations' => '/server/manage', 'finance' => '/order',
            'support' => '/ticket', 'marketing' => '/plan'][$role] ?? '/login';
    }

    public static function menus(string $role): array
    {
        $menus = [];
        $group = null;
        foreach (config('admin_security.pages', []) as $page) {
            if (!in_array($role, $page[3], true)) continue;
            if ($group !== $page[2]) {
                $group = $page[2];
                $menus[] = ['type' => 'heading', 'title' => $group];
            }
            $menus[] = ['type' => 'item', 'title' => $page[0], 'href' => $page[1]];
        }
        return $menus;
    }

    public static function changeRole(User $user, ?string $role): bool
    {
        abort_if((int)$user->id === 1, 403, '不能修改唯一超级管理员的身份');
        abort_if($role !== null && !in_array($role, self::ASSIGNABLE, true), 422, '管理员身份无效');
        return DB::transaction(function () use ($user, $role) {
            $user = User::where('id', $user->id)->lockForUpdate()->firstOrFail();
            abort_if($role !== null && $user->banned, 422, '请先解除该账号的停用状态');
            if ($user->admin_role === $role && (int)$user->is_admin === ($role ? 1 : 0) && !(int)$user->is_staff) return false;
            $before = ['role' => $user->admin_role, 'version' => (int)$user->admin_version];
            $user->admin_role = $role;
            $user->admin_version = (int)$user->admin_version + 1;
            $user->is_admin = $role ? 1 : 0;
            $user->is_staff = 0;
            if (!$user->save()) abort(500, '管理员身份保存失败');
            SecurityAuditService::append('administrator.role', 'success', ['target_id' => $user->id,
                'before' => $before, 'after' => ['role' => $role, 'version' => (int)$user->admin_version]]);
            // Revoke Redis/cache sessions only once the entire user edit commits.
            DB::afterCommit(function () use ($user) { (new AuthService($user))->removeAllSession(); });
            return true;
        });
    }

    public static function allows(string $role, string $action): bool
    {
        // An explicit action registry: a new controller method never inherits access.
        $action = str_replace('App\\Http\\Controllers\\', '', ltrim($action, '\\'));
        $common = [
            'V1\\User\\UserController' => ['checkLogin', 'info', 'changePassword', 'getActiveSession', 'removeActiveSession'],
            'V1\\User\\TwoFactorController' => ['status', 'setup', 'confirm', 'disable', 'regenerateRecoveryCodes'],
            'V1\\Admin\\SecurityController' => ['bootstrap', 'asset', 'logout'],
        ];
        if (!in_array($role, array_merge(['super'], self::ASSIGNABLE), true)) return false;
        if ($role === 'super') return true;
        $registry = $common;
        $admin = 'V1\\Admin\\';
        if ($role === 'operations') {
            $registry += [
                $admin . 'ConfigController' => ['fetch', 'save', 'getEmailTemplate', 'getThemeTemplate', 'setTelegramWebhook', 'testSendMail'],
                $admin . 'PaymentController' => ['fetch', 'getPaymentMethods', 'getPaymentForm', 'save', 'drop', 'show', 'sort'],
                $admin . 'ThemeController' => ['getThemes', 'getThemeConfig', 'saveThemeConfig'],
                $admin . 'Server\\GroupController' => ['fetch', 'save', 'drop'],
                $admin . 'Server\\RouteController' => ['fetch', 'save', 'drop'],
                $admin . 'Server\\ManageController' => ['getNodes', 'sort', 'previewHostReplacement', 'replaceHost', 'copyNodes',
                    'previewRename', 'applyRename', 'previewRate', 'applyRate', 'previewServerPort', 'applyServerPort',
                    'previewPorts', 'applyPorts', 'inspectTlsFields', 'previewTlsFields', 'applyTlsFields', 'deleteNodes',
                    'previewProtocolSettings', 'applyProtocolSettings'],
                $admin . 'RateController' => ['fetch', 'saveRule', 'dropRule', 'saveSettings', 'savePolicy', 'policyOptions',
                    'dropPolicy', 'previewBinding', 'applyBinding', 'explain'],
                $admin . 'RiskTraceController' => ['fetch', 'history', 'lookup', 'reveal'],
                $admin . 'RiskSharedIpController' => ['fetch', 'detail'],
                $admin . 'SubscribeCleanGatewayController' => ['fetch', 'options', 'export', 'config', 'saveConfig', 'rules',
                    'history', 'riskPending', 'handleRisk', 'block', 'release'],
            ];
            foreach (['Trojan', 'Vmess', 'Shadowsocks', 'Tuic', 'Hysteria', 'Vless', 'AnyTLS', 'V2node'] as $protocol) {
                $registry[$admin . 'Server\\' . $protocol . 'Controller'] = ['save', 'drop', 'update', 'copy'];
            }
        } elseif ($role === 'finance') {
            $registry[$admin . 'OrderController'] = ['fetch', 'update', 'assign', 'paid', 'reconcile', 'cancel', 'detail'];
            $registry[$admin . 'SecurityController'][] = 'planOptions';
        } elseif ($role === 'support') {
            $registry[$admin . 'TicketController'] = ['fetch', 'reply'];
            $registry['V1\\Staff\\TicketController'] = ['fetch', 'reply'];
        } elseif ($role === 'marketing') {
            $registry[$admin . 'PlanController'] = ['fetch', 'save', 'drop', 'update', 'sort'];
            $registry[$admin . 'CouponController'] = ['fetch', 'generate', 'drop', 'show'];
            $registry[$admin . 'GiftcardController'] = ['fetch', 'generate', 'drop'];
            $registry[$admin . 'RewardController'] = ['fetch', 'save'];
            $registry[$admin . 'SecurityController'][] = 'groupOptions';
            $registry[$admin . 'SecurityController'][] = 'planConfig';
        }
        $parts = explode('@', $action, 2);
        return count($parts) === 2 && in_array($parts[1], $registry[$parts[0]] ?? [], true);
    }

    public static function protectRequest(Request $request, array $actor): void
    {
        if ($actor['admin_role'] !== 'super') {
            foreach (self::SECURITY_FIELDS as $field) {
                if ($request->exists($field)) abort(403, '只有超级管理员可以管理身份与安全策略');
            }
        }
        $action = $request->route()->getActionName();
        if ($actor['admin_role'] === 'operations' && strpos($action, 'Admin\\ConfigController@save') !== false) {
            foreach (array_keys($request->all()) as $field) {
                if (strpos($field, 'reward_') === 0) abort(403, '签到与娱乐配置仅限运营管理员或超级管理员修改');
            }
        }
        if (strpos($action, 'Admin\\UserController@') !== false && $request->isMethod('POST')) {
            foreach (['admin_role', 'admin_version', 'is_admin', 'is_staff'] as $field) {
                if (!$request->exists($field)) continue;
                if ($field === 'admin_role' && substr($action, -strlen('Admin\\UserController@update')) === 'Admin\\UserController@update') {
                    abort_if((int)$request->input('id') === 1, 403, '不能修改唯一超级管理员的身份');
                    continue;
                }
                $target = User::find($request->input('id'));
                if (!$target || (string)$request->input($field) !== (string)$target->$field) {
                    abort(422, '请在用户编辑窗口选择管理员身份');
                }
                $request->request->remove($field);
            }
            if ((int)$request->input('id') === 1 &&
                (preg_match('/@(delUser|allDel|ban)$/', $action) || $request->boolean('banned'))) {
                abort(403, '不能删除、停用或降级唯一超级管理员');
            }
        }
    }
}
