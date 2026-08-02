<?php

use App\Services\ThemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function (Request $request) {
    if (config('v2board.app_url') && config('v2board.safe_mode_enable', 0)) {
        if ($request->server('HTTP_HOST') !== parse_url(config('v2board.app_url'))['host']) {
            abort(403);
        }
    }
    $theme = config('v2board.frontend_theme', 'default');

    // 后台配着的主题若产物已不存在（例如主题被删除、目录被误清），绝不能进 ThemeService::init()：
    // init() 在 config:cache 之后要等 config("theme.{$theme}") 出现，而 webman/AdapterMan 是
    // 常驻 CLI 进程（max_execution_time=0，没有 php-fpm 那道超时兜底），一旦等不到就是一个 worker
    // 被 100% CPU 永久占住。这里先确认主题目录与 config.json 都在，缺失就回落到目录必然存在的
    // default 主题，并留一条带「配置的主题名 + 回落目标」的 error 日志便于运维定位。
    // 注意：目录存在、只是还没生成 config/theme/{theme}.php 的**合法新主题**不会走进这个分支，
    // 首次访问照旧调用 init() 完成初始化，行为与加固前逐字一致。
    $fallbackTheme = 'default';
    $themeConfigFile = public_path("theme/{$theme}/config.json");
    if (!is_dir(public_path("theme/{$theme}")) || !is_file($themeConfigFile)) {
        if (is_file(public_path("theme/{$fallbackTheme}/config.json"))) {
            Log::error("前台主题 [{$theme}] 不可用（缺少 {$themeConfigFile}），已回落到 [{$fallbackTheme}] 主题渲染；请到管理后台重新选择一个已安装的主题。");
            $theme = $fallbackTheme;
        } else {
            // 连回落主题都缺失时不改变原有行为，让后续既有报错原样暴露出来。
            Log::error("前台主题 [{$theme}] 与回落主题 [{$fallbackTheme}] 的 config.json 均不存在，前台无法渲染，请检查 public/theme 目录是否完整。");
        }
    }

    $renderParams = [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme' => $theme,
        'version' => config('app.version'),
        'description' => config('v2board.app_description', 'V2Board is best'),
        'logo' => config('v2board.logo')
    ];

    if (!config("theme.{$theme}")) {
        $themeService = new ThemeService($theme);
        $themeService->init();
    }

    $renderParams['theme_config'] = config("theme.{$theme}");
    return view("theme::{$theme}.dashboard", $renderParams);
});

//TODO:: 兼容
Route::get('/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))), function () {
    return view('admin', [
        'title' => config('v2board.app_name', 'V2Board'),
        'theme_sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
        'theme_header' => config('v2board.frontend_theme_header', 'dark'),
        'theme_color' => config('v2board.frontend_theme_color', 'default'),
        'background_url' => config('v2board.frontend_background_url'),
        'version' => config('app.version'),
        'logo' => config('v2board.logo'),
        'secure_path' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))))
    ]);
});

Route::get('/store/{slug}', function ($slug) {
    return response()->view('storefront', [
        'slug' => $slug,
        'reseller_enabled' => (int)config('v2board.reseller_enable', 0),
    ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
});

Route::get('/store', function () {
    return view('store-index', [
        'reseller_enabled' => (int)config('v2board.reseller_enable', 0),
    ]);
});

Route::get('/reseller', function () {
    return view('reseller', [
        'title' => config('v2board.app_name', 'V2Board') . ' Reseller',
        'reseller_enabled' => (int)config('v2board.reseller_enable', 0),
    ]);
});

if (!empty(config('v2board.subscribe_path'))) {
    Route::get(config('v2board.subscribe_path'), 'V1\\Client\\ClientController@subscribe')->middleware(['site.status', 'client']);
}
