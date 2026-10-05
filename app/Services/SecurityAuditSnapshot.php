<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Configuration writes and query-builder updates have no Eloquent events. */
class SecurityAuditSnapshot
{
    public static function safe(array $snapshot): array
    {
        $out = [];
        foreach ($snapshot as $table => $rows) {
            if ($table === 'theme') $out[$table] = SecurityAuditBusiness::text($rows);
            elseif ($table === 'settings' || $table === 'values') $out[$table] = SecurityAuditBusiness::snapshot($table === 'values' ? 'theme' : 'settings', $rows);
            else foreach ($rows as $id => $row) $out[$table][$id] = SecurityAuditBusiness::snapshot($table, $row);
        }
        return $out;
    }

    public static function capture(Request $request, $response = null, bool $redact = true): array
    {
        if ($request->isMethod('GET') || !$request->route()) return [];
        $action = $request->route()->getActionName();
        if (preg_match('/Admin\\\\(?:ConfigController@(?:save|setTelegramWebhook)|RewardController@save|ResellerController@savePaymentDrivers|SubscribeCleanGatewayController@saveConfig)$/', $action)) {
            $path = base_path('config/v2board.php');
            $values = is_file($path) ? (array) require $path : (array)config('v2board', []);
            $keys = array_keys($request->except(['user', 'auth_data']));
            if (strpos($action, 'ResellerController@') !== false) $keys = ['reseller_allowed_payment_drivers'];
            if (strpos($action, 'SubscribeCleanGatewayController@') !== false) $keys = ['subscribe_audit_retention_days'];
            $settings = array_intersect_key($values, array_flip($keys));
            return ['settings' => $redact ? SecurityAuditBusiness::snapshot('settings', $settings) : $settings];
        }
        if (substr($action, -strlen('ThemeController@saveThemeConfig')) === 'ThemeController@saveThemeConfig') {
            $name = $request->input('name');
            if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) return [];
            $path = base_path('config/theme/' . $name . '.php');
            $values = is_file($path) ? (array) require $path : [];
            return ['theme' => $name, 'values' => $redact ? SecurityAuditBusiness::snapshot('theme', $values) : $values];
        }
        if (preg_match('/Admin\\\\RateController@(saveRule|dropRule|saveSettings|savePolicy|dropPolicy|applyBinding)$/', $action, $match)) {
            $method = $match[1];
            $tables = ['v2_rate_setting'];
            if (in_array($method, ['saveRule', 'dropRule'], true)) $tables[] = 'v2_rate_rule';
            if (in_array($method, ['savePolicy', 'dropPolicy'], true)) $tables[] = 'v2_rate_policy';
            if ($method === 'applyBinding') $tables[] = 'v2_rate_node_policy';
            $snapshot = [];
            foreach ($tables as $table) {
                if (!Schema::hasTable($table)) continue;
                $query = DB::table($table);
                if (in_array($table, ['v2_rate_rule', 'v2_rate_policy'], true)) {
                    if ($request->input('id')) $query->where('id', $request->input('id'));
                    else {
                        // Use the controller's new ID, never a max-ID range:
                        // another administrator may create a row concurrently.
                        $body = is_object($response) && method_exists($response, 'getContent') ? json_decode($response->getContent(), true) : [];
                        $query->where('id', (int)($body['data']['id'] ?? 0));
                    }
                }
                if ($table === 'v2_rate_node_policy' && (!is_array($request->input('nodes')) || count($request->input('nodes')) > 200)) continue;
                if ($table === 'v2_rate_node_policy' && is_array($request->input('nodes'))) {
                    $query->where(function ($q) use ($request) {
                        $q->whereRaw('1 = 0');
                        foreach ($request->input('nodes') as $node) {
                            if (!is_array($node)) continue;
                            $q->orWhere(function ($q) use ($node) { $q->where('node_type', $node['type'] ?? '')->where('node_id', $node['id'] ?? 0); });
                        }
                    });
                }
                // These are configuration tables, never the per-account traffic ledger.
                $rows = $query->get();
                foreach ($rows as $row) {
                    $item = (array)$row;
                    $key = $item['id'] ?? $item['setting_key'] ?? (($item['node_type'] ?? '') . ':' . ($item['node_id'] ?? ''));
                    $snapshot[$table][(string)$key] = $redact ? SecurityAuditBusiness::snapshot($table, $item) : $item;
                }
            }
            return $snapshot;
        }
        return [];
    }
}
