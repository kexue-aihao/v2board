<!DOCTYPE html>
<html>

<head>
    @php
        $siteStatusAssetPaths = [
            public_path('theme/default/assets/site-status.css'),
            public_path('theme/default/assets/site-status.js'),
        ];
        $siteStatusAssets = array_filter(array_map(function ($path) {
            return is_file($path) ? filemtime($path) : null;
        }, $siteStatusAssetPaths));
        $siteStatusAssetFingerprint = array_filter(array_map(function ($path) {
            return is_file($path) ? hash_file('sha256', $path) : null;
        }, $siteStatusAssetPaths));
        $siteStatusAssetVersion = $siteStatusAssets
            ? max($siteStatusAssets) . '-' . substr(hash('sha256', implode('|', $siteStatusAssetFingerprint)), 0, 12)
            : $version;
    @endphp
    <link rel="stylesheet" href="/theme/default/assets/site-status.css?v={{$siteStatusAssetVersion}}">
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/components.chunk.css?v={{$version}}">
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/umi.css?v={{$version}}">
    @if (file_exists(public_path("/theme/{$theme}/assets/custom.css")))
        <link rel="stylesheet" href="/theme/{{$theme}}/assets/custom.css?v={{$version}}">
    @endif
    <meta charset="utf-8">
    {{-- 与管理端同步放开捏合缩放，理由见 resources/views/admin.blade.php。
         同样不加 viewport-fit=cover：本主题的 CSS 也没有处理安全区。 --}}
    <meta name="viewport" content="width=device-width,initial-scale=1">
    @php ($colors = [
        'darkblue' => '#3b5998',
        'black' => '#343a40',
        'default' => '#0665d0',
        'green' => '#319795'
    ])
    <meta name="theme-color" content="{{$colors[$theme_config['theme_color']]}}">

    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: '{{$title}}',
            assets_path: '/theme/{{$theme}}/assets',
            theme: {
                sidebar: '{{$theme_config['theme_sidebar']}}',
                header: '{{$theme_config['theme_header']}}',
                color: '{{$theme_config['theme_color']}}',
            },
            version: '{{$version}}',
            background_url: '{{$theme_config['background_url']}}',
            description: '{{$description}}',
            i18n: [
                'zh-CN',
                'en-US',
                'ja-JP',
                'vi-VN',
                'ko-KR',
                'zh-TW',
                'fa-IR'
            ],
            logo: '{{$logo}}'
        }
        window.__v2board2faInline = true;
    </script>
    <script src="/theme/{{$theme}}/assets/i18n/zh-CN.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/zh-TW.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/en-US.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/ja-JP.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/vi-VN.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/ko-KR.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/fa-IR.js?v={{$version}}"></script>
</head>

<body>
<div id="root"></div>
{!! $theme_config['custom_html'] !!}
<script>
window.__v2boardSiteStatusScripts = [
    "/theme/{{$theme}}/assets/vendors.async.js?v={{$version}}",
    "/theme/{{$theme}}/assets/components.async.js?v={{$version}}",
    "/theme/{{$theme}}/assets/umi.js?v={{$version}}",
    "/assets/two-factor-widget.js?v={{$version}}",
    "/assets/password-policy-widget.js?v={{$version}}"
    @if (file_exists(public_path("/theme/{$theme}/assets/custom.js")))
        , "/theme/{{$theme}}/assets/custom.js?v={{$version}}"
    @endif
];
</script>
<script src="/theme/default/assets/site-status.js?v={{$siteStatusAssetVersion}}"></script>
</body>

</html>
