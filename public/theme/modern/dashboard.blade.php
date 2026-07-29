<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @php
        $modernAssetPath = public_path("theme/{$theme}/assets/index.js");
        $modernThemeColors = [
            'default' => '#0665d0',
            'green' => '#319795',
            'black' => '#343a40',
            'darkblue' => '#3b5998'
        ];
        $modernThemeColor = $theme_config['theme_color'] ?? 'default';
        if (!array_key_exists($modernThemeColor, $modernThemeColors)) {
            $modernThemeColor = 'default';
        }
        $modernPalettePath = public_path("theme/{$theme}/assets/theme/{$modernThemeColor}.css");
        $modernAssetPaths = [$modernAssetPath, $modernPalettePath];
        $modernAssetVersions = array_filter(array_map(function ($path) {
            return is_file($path) ? filemtime($path) : null;
        }, $modernAssetPaths));
        $modernAssetFingerprints = array_filter(array_map(function ($path) {
            return is_file($path) ? hash_file('sha256', $path) : null;
        }, $modernAssetPaths));
        $modernAssetVersion = $modernAssetVersions
            ? max($modernAssetVersions) . '-' . substr(hash('sha256', implode('|', $modernAssetFingerprints)), 0, 12)
            : $version;
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="{{$modernThemeColors[$modernThemeColor]}}">
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/index.css?v={{$modernAssetVersion}}">
    @if (is_file($modernPalettePath))
        <link rel="stylesheet" href="/theme/{{$theme}}/assets/theme/{{$modernThemeColor}}.css?v={{$modernAssetVersion}}">
    @endif
    <title>{{$title}}</title>
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: @json($title),
            assets_path: '/theme/{{$theme}}/assets',
            theme: {
                sidebar: @json($theme_config['theme_sidebar'] ?? 'light'),
                header: @json($theme_config['theme_header'] ?? 'light'),
                color: @json($theme_config['theme_color'] ?? 'default')
            },
            version: @json($version),
            background_url: @json($theme_config['background_url'] ?? ''),
            description: @json($description),
            i18n: ['zh-CN', 'en-US', 'ja-JP', 'vi-VN', 'ko-KR', 'zh-TW', 'fa-IR'],
            logo: @json($logo)
        };
    </script>
</head>
<body>
<div id="app"></div>
{!! $theme_config['custom_html'] ?? '' !!}
<script type="module" src="/theme/{{$theme}}/assets/index.js?v={{$modernAssetVersion}}"></script>
</body>
</html>
