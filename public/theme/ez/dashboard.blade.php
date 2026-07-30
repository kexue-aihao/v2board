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
