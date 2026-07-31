<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @php
        // 后台的 theme_color 是 4 个枚举键（与 default/ez/signature 一致），而前端消费的
        // 是任意 hex，所以在这里做映射并注入 hex。
        // 刻意不把 config.json 改成自由输入：ThemeController::saveThemeConfig 完全不校验
        // select_options、也不校验格式，原样 var_export 写进 config/theme/modern.php ——
        // 自由输入等于把一个未校验的任意字符串接到 baseConfig.js 的模块求值期上（见那边的
        // hexToRgb 兜底注释）。枚举是天然白名单。
        //
        // 四色取 modern 自己的皮肤主色（M0 决议 1，值源 EZ-COGNITION/work/modern-identity.md）。
        // 存疑保留：EZ 的 blade 曾把 black/darkblue 换成对比度修正值（#6b7280/#4668b0），
        // 因为 EZ 明暗共用同一个 primaryColor —— modern 的 #343a40 在暗底上只有约 1.52:1、
        // #3b5998 暗色下仅约 2.55:1。modern 的皮肤体系在暗色下另有一套 accent
        // （black 暗色 #8c969f、darkblue 暗色 #7393cf，见 modern-identity.md 1.3），
        // 该暗色覆盖属 M6 样式里程碑；M6 落地前，暗色模式下这两色的对比度问题原样存在。
        $modernThemeColors = [
            'default' => '#0665d0',   // modern 默认蓝
            'green' => '#319795',     // 青绿色
            'black' => '#343a40',     // 石墨色（modern 自有值，见上方存疑保留注释）
            'darkblue' => '#3b5998',  // 深蓝色（modern 自有值，见上方存疑保留注释）
        ];
        $modernThemeColorKey = $theme_config['theme_color'] ?? 'default';
        if (!array_key_exists($modernThemeColorKey, $modernThemeColors)) {
            $modernThemeColorKey = 'default';
        }
        $modernThemeColor = $modernThemeColors[$modernThemeColorKey];
        [$modernR, $modernG, $modernB] = sscanf($modernThemeColor, '#%02x%02x%02x');

        $modernAssetRoot = public_path("theme/{$theme}/assets");
        $modernManifestPath = $modernAssetRoot . '/manifest.json';
        $modernManifest = is_file($modernManifestPath)
            ? json_decode(file_get_contents($modernManifestPath), true)
            : ['styles' => [], 'scripts' => []];
        $modernStyles = is_array($modernManifest['styles'] ?? null) ? $modernManifest['styles'] : [];
        $modernScripts = is_array($modernManifest['scripts'] ?? null) ? $modernManifest['scripts'] : [];
        $modernFiles = array_merge($modernStyles, $modernScripts);
        $modernVersions = array_filter(array_map(function ($file) use ($modernAssetRoot) {
            $path = $modernAssetRoot . '/' . ltrim($file, '/');
            return is_file($path) ? filemtime($path) : null;
        }, $modernFiles));
        $modernAssetVersion = $modernVersions ? max($modernVersions) . '-' . substr(hash('sha256', implode('|', $modernFiles)), 0, 12) : $version;
        $modernAssetUrl = function ($file) use ($theme, $modernAssetVersion) {
            return '/theme/' . $theme . '/assets/' . ltrim($file, '/') . '?v=' . $modernAssetVersion;
        };
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="{{ $modernThemeColor }}">
    <title>{{ $title }}</title>
    <link rel="icon" href="{{ $logo ?: '/theme/' . $theme . '/assets/images/logo.png' }}">
    <style>
        /* 只放两类东西：来自数据库的值（只能由 blade 生成），以及必须在 CSS 到位前就生效的
           首屏底色。其余布局规则都在 src/assets/styles/ 里，那样对 blade 页面和构建产出的
           index.html 同时生效。
           这里写的 --theme-color 只是首屏预热：useTheme 的 applyTheme() 会在 onMounted
           时用 documentElement 的 inline style 覆盖它，优先级更高，不冲突。
           首屏底色用 modern 自己的皮肤值（亮 #f4f7f5 / 暗 #111714，M0 决议 1）。 */
        :root { --theme-color: {{ $modernThemeColor }}; --theme-color-rgb: {{ $modernR }}, {{ $modernG }}, {{ $modernB }}; }
        html { background-color: #f4f7f5; }
        @media (prefers-color-scheme: dark) { html { background-color: #111714; } }
    </style>
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: @json($title),
            description: @json($description),
            logo: @json($logo),
            assets_path: @json('/theme/' . $theme . '/assets'),
            version: @json($version),
            theme: { color: @json($modernThemeColor) },
            i18n: ['zh-CN', 'en-US', 'ja-JP', 'vi-VN', 'ko-KR', 'zh-TW', 'fa-IR']
        };
    </script>
    @foreach ($modernStyles as $modernStyle)
        <link rel="stylesheet" href="{{ $modernAssetUrl($modernStyle) }}">
    @endforeach
    {{-- manifest 只允许列出 initial chunk。异步 chunk 由 webpack 运行时自己按需加载
         （publicPath 已内联进 runtime）；一旦把异步 CSS 列到这里，上面那个 ?v= 会让
         mini-css-extract 的 findStylesheet 判定失配，导致每个路由的 <link> 被重复插入。
         放在 head 且带 defer，与产物里 index.html 的加载方式完全一致：解析早期就发起下载，
         执行时机仍在 DOMContentLoaded 之前、按文档顺序，而 custom_html 的相对顺序不变。 --}}
    @foreach ($modernScripts as $modernScript)
        <script defer src="{{ $modernAssetUrl($modernScript) }}"></script>
    @endforeach
</head>
<body>
<div id="app"></div>
{!! $theme_config['custom_html'] ?? '' !!}
</body>
</html>
