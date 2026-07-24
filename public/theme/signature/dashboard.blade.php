<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @php
        $signatureAssetPath = public_path("theme/{$theme}/assets/index.js");
        $signatureThemeColors = [
            'default' => '#171717',
            'green' => '#287d73',
            'black' => '#171717',
            'darkblue' => '#243b68'
        ];
        $signatureThemeColor = $theme_config['theme_color'] ?? 'default';
        if (!array_key_exists($signatureThemeColor, $signatureThemeColors)) {
            $signatureThemeColor = 'default';
        }
        $signaturePalettePath = public_path("theme/{$theme}/assets/theme/{$signatureThemeColor}.css");
        $signatureAssetVersions = array_filter([
            is_file($signatureAssetPath) ? filemtime($signatureAssetPath) : null,
            is_file($signaturePalettePath) ? filemtime($signaturePalettePath) : null
        ]);
        $signatureAssetVersion = $signatureAssetVersions ? max($signatureAssetVersions) : $version;
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="{{$signatureThemeColors[$signatureThemeColor]}}">
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/index.css?v={{$signatureAssetVersion}}">
    @if (is_file($signaturePalettePath))
        <link rel="stylesheet" href="/theme/{{$theme}}/assets/theme/{{$signatureThemeColor}}.css?v={{$signatureAssetVersion}}">
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
<script type="module" src="/theme/{{$theme}}/assets/index.js?v={{$signatureAssetVersion}}"></script>
</body>
</html>
