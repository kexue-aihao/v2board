<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @php
        $modernAssetPath = public_path("theme/{$theme}/assets/index.js");
        $modernAssetVersion = is_file($modernAssetPath) ? filemtime($modernAssetPath) : $version;
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#f4f7f5">
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/index.css?v={{$modernAssetVersion}}">
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
