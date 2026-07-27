<!DOCTYPE html>
<html>

<head>
    @php
        // app.version 是固定值，管理端编译产物是就地打补丁的，版本号不会跟着变，
        // 浏览器和 CDN 会一直复用旧的 umi.js。改用实际文件时间戳，与 modern 主题一致。
        $adminAssetVersions = array_filter(array_map(function ($file) {
            $path = public_path("assets/admin/{$file}");
            return is_file($path) ? filemtime($path) : null;
        }, ['umi.js', 'umi.css', 'custom.css', 'vendors.async.js', 'components.async.js']));
        $adminAssetVersion = $adminAssetVersions ? max($adminAssetVersions) : $version;
    @endphp
    <link rel="stylesheet" href="/assets/admin/components.chunk.css?v={{$adminAssetVersion}}">
    <link rel="stylesheet" href="/assets/admin/umi.css?v={{$adminAssetVersion}}">
    <link rel="stylesheet" href="/assets/admin/custom.css?v={{$adminAssetVersion}}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$version}}',
            background_url: '{{$background_url}}',
            logo: '{{$logo}}',
            secure_path: '{{$secure_path}}'
        }
    </script>
</head>

<body>
<div id="root"></div>
<script src="/assets/admin/vendors.async.js?v={{$adminAssetVersion}}"></script>
<script src="/assets/admin/components.async.js?v={{$adminAssetVersion}}"></script>
<script src="/assets/admin/umi.js?v={{$adminAssetVersion}}"></script>
</body>

</html>
