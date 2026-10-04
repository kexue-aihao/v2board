<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use App\Services\SecurityAuditService;

class ThemeController extends Controller
{
    private $themes;
    private $path;

    public function __construct()
    {
        $this->path = $path = public_path('theme/');
        $this->themes = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
    }

    public function getThemes()
    {
        $themeConfigs = [];
        foreach ($this->themes as $theme) {
            $themeConfigFile = $this->path . "{$theme}/config.json";
            if (!File::exists($themeConfigFile)) continue;
            $themeConfig = json_decode(File::get($themeConfigFile), true);
            if (!isset($themeConfig['configs']) || !is_array($themeConfig)) continue;
            if ((request()->user['admin_role'] ?? 'super') !== 'super') {
                $themeConfig['configs'] = array_values(array_filter($themeConfig['configs'], function ($field) {
                    return !preg_match('/html|script|custom_code/i', $field['field_name'] ?? '');
                }));
            }
            $themeConfigs[$theme] = $themeConfig;
            if (config("theme.{$theme}")) continue;
            $themeService = new ThemeService($theme);
            $themeService->init();
        }
        return response([
            'data' => [
                'themes' => $themeConfigs,
                'active' => config('v2board.frontend_theme', 'v2board')
            ]
        ]);
    }

    public function getThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => 'required|in:' . join(',', $this->themes)
        ]);
        $data = (array)config("theme.{$payload['name']}");
        if (($request->user['admin_role'] ?? 'super') !== 'super') {
            foreach (array_keys($data) as $field) if (preg_match('/html|script|custom_code/i', $field)) unset($data[$field]);
        }
        return response(['data' => $data]);
    }

    public function saveThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => 'required|in:' . join(',', $this->themes),
            'config' => 'required'
        ]);
        $payload['config'] = json_decode(base64_decode($payload['config']), true);
        if (!$payload['config'] || !is_array($payload['config'])) abort(500, __('参数有误'));
        $themeConfigFile = public_path("theme/{$payload['name']}/config.json");
        if (!File::exists($themeConfigFile)) abort(500, __('主题不存在'));
        $themeConfig = json_decode(File::get($themeConfigFile), true);
        if (!isset($themeConfig['configs']) || !is_array($themeConfig)) abort(500, __('主题配置文件有误'));
        $validateFields = array_column($themeConfig['configs'], 'field_name');
        $previousFile = base_path() . "/config/theme/{$payload['name']}.php";
        $previousConfig = File::exists($previousFile) ? (array) require $previousFile : (array)config("theme.{$payload['name']}");
        $config = [];
        foreach ($validateFields as $validateField) {
            if (($request->user['admin_role'] ?? 'super') !== 'super' && preg_match('/html|script|custom_code/i', $validateField)) {
                abort_if(array_key_exists($validateField, $payload['config']), 403, '只有超级管理员可以修改可执行的主题注入内容');
                $config[$validateField] = $previousConfig[$validateField] ?? '';
                continue;
            }
            $config[$validateField] = isset($payload['config'][$validateField]) ? $payload['config'][$validateField] : '';
        }

        if ($request->attributes->get('security_audit_context')) SecurityAuditService::append('theme.change', 'pending', [
            'theme' => $payload['name'], 'before' => $previousConfig, 'after' => $config,
        ]);

        File::ensureDirectoryExists(base_path() . '/config/theme/');

        $data = var_export($config, 1);
        if (!File::put(base_path() . "/config/theme/{$payload['name']}.php", "<?php\n return $data ;")) {
            abort(500, __('修改失败'));
        }

        try {
            Artisan::call('config:cache');
//            sleep(2);
        } catch (\Exception $e) {
            abort(500, __('保存失败'));
        }

        if (($request->user['admin_role'] ?? 'super') !== 'super') {
            foreach (array_keys($config) as $field) if (preg_match('/html|script|custom_code/i', $field)) unset($config[$field]);
        }
        return response(['data' => $config]);
    }
}
