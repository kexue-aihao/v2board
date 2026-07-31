<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @php
        // 后台的 theme_color 是 4 个枚举键（与 default/ez/modern 一致），而前端消费的
        // 是任意 hex，所以在这里做映射并注入 hex。
        // 刻意不把 config.json 改成自由输入：ThemeController::saveThemeConfig 完全不校验
        // select_options、也不校验格式，原样 var_export 写进 config/theme/signature.php ——
        // 自由输入等于把一个未校验的任意字符串接到 baseConfig.js 的模块求值期上（见那边的
        // hexToRgb 兜底注释）。枚举是天然白名单。
        //
        // signature 皮肤值：四色为 signature 自己的枚举→hex 映射
        // （值源 EZ-COGNITION/work/signature-identity.md §5.2「signature 四主色」，
        // 已替换冲压阶段的 modern 占位值）。default/black 同为墨色 #171717 是 identity
        // 文档原样映射（signature 的品牌身份即墨色 ink，石墨枚举与之同值）。
        $signatureThemeColors = [
            'default' => '#171717',   // signature 默认墨色（sig-ink）
            'green' => '#287d73',     // 青绿色
            'black' => '#171717',     // 石墨色（identity 原样与 default 同值）
            'darkblue' => '#243b68',  // 深蓝色
        ];
        $signatureThemeColorKey = $theme_config['theme_color'] ?? 'default';
        if (!array_key_exists($signatureThemeColorKey, $signatureThemeColors)) {
            $signatureThemeColorKey = 'default';
        }
        $signatureThemeColor = $signatureThemeColors[$signatureThemeColorKey];
        [$signatureR, $signatureG, $signatureB] = sscanf($signatureThemeColor, '#%02x%02x%02x');

        $signatureAssetRoot = public_path("theme/{$theme}/assets");
        $signatureManifestPath = $signatureAssetRoot . '/manifest.json';
        $signatureManifest = is_file($signatureManifestPath)
            ? json_decode(file_get_contents($signatureManifestPath), true)
            : ['styles' => [], 'scripts' => []];
        $signatureStyles = is_array($signatureManifest['styles'] ?? null) ? $signatureManifest['styles'] : [];
        $signatureScripts = is_array($signatureManifest['scripts'] ?? null) ? $signatureManifest['scripts'] : [];
        $signatureFiles = array_merge($signatureStyles, $signatureScripts);
        $signatureVersions = array_filter(array_map(function ($file) use ($signatureAssetRoot) {
            $path = $signatureAssetRoot . '/' . ltrim($file, '/');
            return is_file($path) ? filemtime($path) : null;
        }, $signatureFiles));
        $signatureAssetVersion = $signatureVersions ? max($signatureVersions) . '-' . substr(hash('sha256', implode('|', $signatureFiles)), 0, 12) : $version;
        $signatureAssetUrl = function ($file) use ($theme, $signatureAssetVersion) {
            return '/theme/' . $theme . '/assets/' . ltrim($file, '/') . '?v=' . $signatureAssetVersion;
        };
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="{{ $signatureThemeColor }}">
    <title>{{ $title }}</title>
    <link rel="icon" href="{{ $logo ?: '/theme/' . $theme . '/assets/images/logo.png' }}">
    <style>
        /* 只放两类东西：来自数据库的值（只能由 blade 生成），以及必须在 CSS 到位前就生效的
           首屏底色。其余布局规则都在 src/assets/styles/ 里，那样对 blade 页面和构建产出的
           index.html 同时生效。
           这里写的 --theme-color 只是首屏预热：useTheme 的 applyTheme() 会在 onMounted
           时用 documentElement 的 inline style 覆盖它，优先级更高，不冲突。
           首屏底色为 signature 自己的预热底色（signature 皮肤值：亮 #f8f7f2 = sig-ivory /
           暗 #111110 = sig-ivory 暗色，值源 signature-identity.md 1.2，已替换冲压阶段的
           modern 占位值）。 */
        :root { --theme-color: {{ $signatureThemeColor }}; --theme-color-rgb: {{ $signatureR }}, {{ $signatureG }}, {{ $signatureB }}; }
        html { background-color: #f8f7f2; }
        @media (prefers-color-scheme: dark) { html { background-color: #111110; } }
    </style>
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: @json($title),
            description: @json($description),
            logo: @json($logo),
            assets_path: @json('/theme/' . $theme . '/assets'),
            version: @json($version),
            theme: { color: @json($signatureThemeColor) },
            i18n: ['zh-CN', 'en-US', 'ja-JP', 'vi-VN', 'ko-KR', 'zh-TW', 'fa-IR']
        };
    </script>
    @foreach ($signatureStyles as $signatureStyle)
        <link rel="stylesheet" href="{{ $signatureAssetUrl($signatureStyle) }}">
    @endforeach
    {{-- manifest 只允许列出 initial chunk。异步 chunk 由 webpack 运行时自己按需加载
         （publicPath 已内联进 runtime）；一旦把异步 CSS 列到这里，上面那个 ?v= 会让
         mini-css-extract 的 findStylesheet 判定失配，导致每个路由的 <link> 被重复插入。
         放在 head 且带 defer，与产物里 index.html 的加载方式完全一致：解析早期就发起下载，
         执行时机仍在 DOMContentLoaded 之前、按文档顺序，而 custom_html 的相对顺序不变。 --}}
    @foreach ($signatureScripts as $signatureScript)
        <script defer src="{{ $signatureAssetUrl($signatureScript) }}"></script>
    @endforeach
</head>
<body>
<div id="app"></div>
{!! $theme_config['custom_html'] ?? '' !!}
</body>
</html>
