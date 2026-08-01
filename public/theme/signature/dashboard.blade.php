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
        // 2026-08 主题色切换诊断修复：上一行 identity 原样映射被推翻——black 与 default
        // 同值 #171717 使「石墨色」档位切了等于没切（后台四选一里有一档是死的）。black
        // 改为石墨灰 #44403c（stone-700 系暖灰，与 signature 皮肤的暖石中性色
        // secondaryText #57534e / border #d6d3d1 同族）：白字对它 10.27:1、作前景落
        // 象牙底 #f8f7f2 9.58:1，均过 4.5:1 正文线；与墨色 default 1.75:1，明度差可感知。
        // 暗色映射同步见下方 $signatureThemeColorsDark 与 baseConfig.js
        // SIGNATURE_DARK_ACCENT_MAP（'#44403c' => '#a8a29e'，三处必须同表）。
        $signatureThemeColors = [
            'default' => '#171717',   // signature 默认墨色（sig-ink）
            'green' => '#287d73',     // 青绿色
            'black' => '#44403c',     // 石墨色（2026-08 修复：原 #171717 与 default 同值，档位无效）
            'darkblue' => '#243b68',  // 深蓝色
        ];
        $signatureThemeColorKey = $theme_config['theme_color'] ?? 'default';
        if (!array_key_exists($signatureThemeColorKey, $signatureThemeColors)) {
            $signatureThemeColorKey = 'default';
        }
        $signatureThemeColor = $signatureThemeColors[$signatureThemeColorKey];
        [$signatureR, $signatureG, $signatureB] = sscanf($signatureThemeColor, '#%02x%02x%02x');

        // 配色修复（补暗色覆盖）：暗色 accent 预热映射，与 baseConfig.js 的
        // SIGNATURE_DARK_ACCENT_MAP 同表——暗底 #111110 上 #171717 仅 1.05:1、
        // #243b68 1.71:1、#287d73 作文字 3.84:1，暗色分别反转/提亮为
        // #f5f5f4（17.32:1）/ #5b81c8（4.88:1）/ #2f9287（5.02:1），色相不动。
        // 配色复扫微调（2026-08）：green/darkblue 首版 #2d8c81/#567cc5 作按钮底时
        // 墨字仅 4.42/4.34:1 <4.5:1，提亮 1~2% 明度与 baseConfig.js
        // SIGNATURE_DARK_ACCENT_MAP 同步为 #2f9287/#5b81c8（墨字 4.77/4.63:1）。
        // 仅作首屏预热：useTheme.applyTheme 挂载后按 localStorage 实际主题以
        // inline style 覆盖，优先级更高，不冲突。
        // 2026-08 主题色切换诊断修复：black 暗色随亮色新值 #44403c 独立映射为 #a8a29e
        //（stone-400 系暖灰，作文字落暗底 #111110 7.49:1、上墨字 #171717 7.11:1，
        // 与 default 暗色 #f5f5f4 拉开 2.31:1 明度差），与 baseConfig.js
        // SIGNATURE_DARK_ACCENT_MAP 同表更新，原 black=>'#f5f5f4' 与 default 同值作废。
        $signatureThemeColorsDark = [
            'default' => '#f5f5f4',
            'green' => '#2f9287',
            'black' => '#a8a29e',
            'darkblue' => '#5b81c8',
        ];
        $signatureThemeColorDark = $signatureThemeColorsDark[$signatureThemeColorKey];
        [$signatureDarkR, $signatureDarkG, $signatureDarkB] = sscanf($signatureThemeColorDark, '#%02x%02x%02x');

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

        // 2026-08-01 问题四：imgbb 图床 key 后台配置化。config.json configs[] 新增 imgbb_api_key
        // （后台主题配置表单 → ThemeController::saveThemeConfig 白名单写入 config/theme/signature.php），
        // 此处照 theme_color 的注入通道透传给前端 window.settings.theme；旧配置文件无此键时
        // ?? '' 兜底，前端留空回退主题内置 key。
        $signatureImgbbApiKey = trim((string)($theme_config['imgbb_api_key'] ?? ''));
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
        /* --on-theme-color：accent 上的文字色预热（亮 accent 四色皆深、取白；暗 accent
           映射后皆偏浅、取墨），运行时由 useTheme.applyTheme 按实际 accent 亮度重算覆盖 */
        :root { --theme-color: {{ $signatureThemeColor }}; --theme-color-rgb: {{ $signatureR }}, {{ $signatureG }}, {{ $signatureB }}; --on-theme-color: #fff; }
        html { background-color: #f8f7f2; }
        @media (prefers-color-scheme: dark) {
            /* 配色修复：暗色首屏预热同步吃暗色 accent 映射（见上方 PHP 注释） */
            :root { --theme-color: {{ $signatureThemeColorDark }}; --theme-color-rgb: {{ $signatureDarkR }}, {{ $signatureDarkG }}, {{ $signatureDarkB }}; --on-theme-color: #171717; }
            html { background-color: #111110; }
        }
    </style>
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: @json($title),
            description: @json($description),
            logo: @json($logo),
            assets_path: @json('/theme/' . $theme . '/assets'),
            version: @json($version),
            theme: { color: @json($signatureThemeColor), imgbb_api_key: @json($signatureImgbbApiKey) },
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
