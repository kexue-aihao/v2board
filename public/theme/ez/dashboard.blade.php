<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @php
        // 后台的 theme_color 是 4 个枚举键（与 default/modern/signature 一致），而 EZ 的
        // primaryColor 是任意 hex，所以在这里做映射并注入 hex。
        // 刻意不把 config.json 改成自由输入：ThemeController::saveThemeConfig 完全不校验
        // select_options、也不校验格式，原样 var_export 写进 config/theme/ez.php ——
        // 自由输入等于把一个未校验的任意字符串接到 baseConfig.js 的模块求值期上（见那边的
        // hexToRgb 兜底注释）。枚举是天然白名单。
        $ezThemeColors = [
            'default' => '#355cc2',   // EZ 上游默认，保证开箱即原版观感
            'green' => '#00947c',     // EZ 自己的备用值
            'black' => '#6b7280',     // 刻意不用 modern 的 #343a40：EZ 明暗共用同一个
                                      // primaryColor，而它在 #171A1D 上只有约 1.52:1
            'darkblue' => '#4668b0',  // 同理，modern 的 #3b5998 暗色下仅约 2.55:1
        ];
        $ezThemeColorKey = $theme_config['theme_color'] ?? 'default';
        if (!array_key_exists($ezThemeColorKey, $ezThemeColors)) {
            $ezThemeColorKey = 'default';
        }
        $ezThemeColor = $ezThemeColors[$ezThemeColorKey];
        [$ezR, $ezG, $ezB] = sscanf($ezThemeColor, '#%02x%02x%02x');

        $ezAssetRoot = public_path("theme/{$theme}/assets");
        $ezManifestPath = $ezAssetRoot . '/manifest.json';
        $ezManifest = is_file($ezManifestPath)
            ? json_decode(file_get_contents($ezManifestPath), true)
            : ['styles' => [], 'scripts' => []];
        $ezStyles = is_array($ezManifest['styles'] ?? null) ? $ezManifest['styles'] : [];
        $ezScripts = is_array($ezManifest['scripts'] ?? null) ? $ezManifest['scripts'] : [];
        $ezFiles = array_merge($ezStyles, $ezScripts);
        $ezVersions = array_filter(array_map(function ($file) use ($ezAssetRoot) {
            $path = $ezAssetRoot . '/' . ltrim($file, '/');
            return is_file($path) ? filemtime($path) : null;
        }, $ezFiles));
        $ezAssetVersion = $ezVersions ? max($ezVersions) . '-' . substr(hash('sha256', implode('|', $ezFiles)), 0, 12) : $version;
        $ezAssetUrl = function ($file) use ($theme, $ezAssetVersion) {
            return '/theme/' . $theme . '/assets/' . ltrim($file, '/') . '?v=' . $ezAssetVersion;
        };

        // 钱包充值入口开关。EZ 主题把整个 /wallet/deposit 功能锁在 baseConfig 的
        // PANEL_TYPE 上：isXiaoV2board() 判定 PANEL_TYPE === 'Xiao-V2board'，为假时
        // ①用户下拉菜单不渲染充值项 ②路由 beforeEnter 把 /wallet/deposit 弹回 /dashboard
        // ③「更多」页的充值卡片不渲染 ④充值页 setup 里再兜一次跳转。而 PANEL_TYPE 的编译
        // 默认值是 'V2board'，且主题从未注入 window.EZ_CONFIG —— 于是充值入口在本项目里
        // 一直是关着的，尽管后端功能完整（plan_id=0 → period=deposit → type=9 → addBalance，
        // 带充值赠送阶梯与 v2_balance_log 流水）。
        //
        // 这里按主题配置注入该值。缺键时默认开启：老配置文件（config/theme/ez.php）里没有
        // 这个字段，而 ThemeService 只在配置文件不存在时才写默认值，所以默认必须在这里兜。
        // 关闭时写回 'V2board' 而非留空，与编译默认值保持一致。
        // 附带影响：同一标志还参与仪表盘「在线设备数」的显示条件
        // （isXiaoV2board() && DASHBOARD_CONFIG.showOnlineDevicesLimit，后者编译默认为
        // true）。后端 UserController 已下发 alive_ip 与 device_limit，数据齐备。
        $ezWalletDeposit = (string)($theme_config['wallet_deposit_enable'] ?? '1') === '1';
        $ezPanelType = $ezWalletDeposit ? 'Xiao-V2board' : 'V2board';
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="{{ $ezThemeColor }}">
    <title>{{ $title }}</title>
    <link rel="icon" href="{{ $logo ?: '/theme/' . $theme . '/assets/images/logo.png' }}">
    <style>
        /* 只放两类东西：来自数据库的值（只能由 blade 生成），以及必须在 CSS 到位前就生效的
           首屏底色。其余布局规则都在 src/assets/styles/base/reset.scss 里，那样对 blade
           页面和构建产出的 index.html 同时生效。
           这里写的 --theme-color 只是首屏预热：useTheme 的 applyTheme() 会在 onMounted
           时用 documentElement 的 inline style 覆盖它，优先级更高，不冲突。 */
        :root { --theme-color: {{ $ezThemeColor }}; --theme-color-rgb: {{ $ezR }}, {{ $ezG }}, {{ $ezB }}; }
        html { background-color: #f5f7fa; }
        @media (prefers-color-scheme: dark) { html { background-color: #171A1D; } }
    </style>
    <script>window.routerBase = "/";</script>
    {{-- 用访问器而不是普通赋值，因为 bundle 自己会把这个对象整体换掉。
         index.js 的启动逻辑是：先动态载入内置默认配置模块（其中硬写 PANEL_TYPE:"V2board"）
         并执行 window.EZ_CONFIG = 那份配置，随后才载入读取模块 baseConfig。也就是说单纯
         在这里赋值必然被冲掉 —— 内联脚本跑得早，但覆盖发生在它之后、读取之前，最终仍读回
         "V2board"，充值入口照旧不出现（这正是上一版改动上线后无效的原因）。
         这里改为把 window.EZ_CONFIG 定义成 getter/setter：bundle 的整体赋值照常生效，
         但每次赋值后都把强制项重新合并回去，故 PANEL_TYPE 始终是本主题配置决定的值，
         而内置配置的其余键（API_CONFIG / SITE_CONFIG 等整棵树）原样保留。
         configurable 留 true，运维仍可在 custom_html 里用 defineProperty 完全接管。
         必须在 bundle 之前执行：下面的 script 都带 defer，本内联脚本先跑。 --}}
    <script>
        (function () {
            var forced = { PANEL_TYPE: @json($ezPanelType) };
            var value = Object.assign({}, window.EZ_CONFIG || {}, forced);
            try {
                Object.defineProperty(window, 'EZ_CONFIG', {
                    configurable: true,
                    get: function () { return value; },
                    set: function (next) {
                        value = Object.assign({}, next || {}, forced);
                    }
                });
            } catch (e) {
                window.EZ_CONFIG = value;
            }
        })();
    </script>
    <script>
        window.settings = {
            title: @json($title),
            description: @json($description),
            logo: @json($logo),
            assets_path: @json('/theme/' . $theme . '/assets'),
            version: @json($version),
            theme: { color: @json($ezThemeColor) },
            i18n: ['zh-CN', 'en-US', 'ja-JP', 'vi-VN', 'ko-KR', 'zh-TW', 'fa-IR']
        };
    </script>
    @foreach ($ezStyles as $ezStyle)
        <link rel="stylesheet" href="{{ $ezAssetUrl($ezStyle) }}">
    @endforeach
    {{-- manifest 只允许列出 initial chunk。异步 chunk 由 webpack 运行时自己按需加载
         （publicPath 已内联进 runtime）；一旦把异步 CSS 列到这里，上面那个 ?v= 会让
         mini-css-extract 的 findStylesheet 判定失配，导致每个路由的 <link> 被重复插入。
         放在 head 且带 defer，与产物里 index.html 的加载方式完全一致：解析早期就发起下载，
         执行时机仍在 DOMContentLoaded 之前、按文档顺序，而 custom_html 的相对顺序不变。 --}}
    @foreach ($ezScripts as $ezScript)
        <script defer src="{{ $ezAssetUrl($ezScript) }}"></script>
    @endforeach
</head>
<body>
<div id="app"></div>
{!! $theme_config['custom_html'] ?? '' !!}
</body>
</html>
