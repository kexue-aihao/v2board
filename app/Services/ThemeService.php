<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ThemeService
{
    // 等待 config:cache 后新主题配置在当前进程可见的上限：最多 3 秒 / 150 次，每次 20ms。
    // 原实现是 while (true) 无限自旋，见 init() 里的说明。
    private const INIT_WAIT_TIMEOUT = 3.0;
    private const INIT_WAIT_MAX_ATTEMPTS = 150;
    private const INIT_WAIT_INTERVAL_US = 20000;

    private $path;
    private $theme;

    public function __construct($theme)
    {
        $this->theme = $theme;
        $this->path = $path = public_path('theme/');
    }

    public function init()
    {
        $themeConfigFile = $this->path . "{$this->theme}/config.json";
        if (!File::exists($themeConfigFile)) abort(500, "{$this->theme}主题不存在");
        $themeConfig = json_decode(File::get($themeConfigFile), true);
        if (!isset($themeConfig['configs']) || !is_array($themeConfig)) abort(500, "{$this->theme}主题配置文件有误");
        $configs = $themeConfig['configs'];
        $data = [];
        foreach ($configs as $config) {
            $data[$config['field_name']] = isset($config['default_value']) ? $config['default_value'] : '';
        }

        $export = var_export($data, 1);
        try {
            File::ensureDirectoryExists(base_path() . '/config/theme/');
            if (!File::put(base_path() . "/config/theme/{$this->theme}.php", "<?php\n return $export ;")) {
                abort(500, "{$this->theme}初始化失败");
            }
        } catch (\Exception $e) {
            abort(500, '请检查V2Board目录权限');
        }

        try {
            // config:cache 只重写 bootstrap/cache/config.php，不会回灌当前进程的 config 仓库，
            // 所以必须自己把刚写出的值放进内存 —— 否则下面那个等待条件永远不成立。原来的
            // while(true) 在 PHP-FPM 下靠 max_execution_time 被动结束（页面超时、下一次请求
            // 才正常），在 Webman/AdapterMan 常驻进程里没有时限，一个请求就永久挂死一个
            // worker。这里保留等待循环只作为兜底，并给它一个上限。
            config(["theme.{$this->theme}" => $data]);
            Artisan::call('config:cache');
            $ready = false;
            $deadline = microtime(true) + self::INIT_WAIT_TIMEOUT;
            for ($attempt = 0; $attempt < self::INIT_WAIT_MAX_ATTEMPTS; $attempt++) {
                if (config("theme.{$this->theme}")) {
                    $ready = true;
                    break;
                }
                if (microtime(true) >= $deadline) {
                    break;
                }
                usleep(self::INIT_WAIT_INTERVAL_US);
            }
            if (!$ready) {
                // 走到这里说明磁盘已写好但当前进程始终读不到（典型原因：bootstrap/cache/config.php
                // 权限不对导致 config:clear 静默失败、新起的 app 仍读到旧缓存；或主题 configs 为空
                // 数组使该键恒为假值）。宁可 500 也不再等下去，日志留足可诊断信息。
                Log::error("主题 [{$this->theme}] 初始化后等待配置生效超时（config/theme/{$this->theme}.php 已写入，"
                    . '请检查 bootstrap/cache 与 config/theme 目录权限，并重启 webman/AdapterMan 服务后重试）。');
                abort(500, "{$this->theme}初始化失败");
            }
        } catch (\Exception $e) {
            abort(500, "{$this->theme}初始化失败");
        }
    }
}
