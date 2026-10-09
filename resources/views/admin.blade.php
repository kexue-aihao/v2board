<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    @php
        // app.version 是固定值，管理端编译产物是就地打补丁的，版本号不会跟着变。
        // 不能只依赖修改时间：Git 检出和就地补丁可能复用相同秒级时间戳，
        // 浏览器或 CDN 就会继续使用旧的 umi.js。使用资源内容指纹确保 URL 随内容变化。
        // i18n.*.js 是各语种字典文件（引擎按 localStorage 语言就地加载），用 glob
        // 一并纳入版本计算：任一字典更新都要让全套 ?v= 换新。
        $adminAssetFiles = array_merge(
            ['entry.js', 'security-loader.js', 'login.js', 'components.chunk.css', 'umi.css', 'custom.css', 'i18n.js'],
            array_map('basename', glob(public_path('assets/admin/i18n.*.js')) ?: [])
        );
        $adminAssetFingerprints = array_filter(array_map(function ($file) {
            $path = public_path("assets/admin/{$file}");
            return is_file($path) ? $file . ':' . hash_file('sha256', $path) : null;
        }, $adminAssetFiles));
        $adminAssetVersion = $adminAssetFingerprints
            ? substr(hash('sha256', implode('|', $adminAssetFingerprints)), 0, 16)
            : $version;
    @endphp
    {{-- 放开捏合缩放：原值 maximum-scale=1 + user-scalable=no 是 V2Board 上游 2020 年首个
         commit 自带的，五年多没人动过也没留下理由。它让 WCAG 1.4.4 不合格，而管理端有 12 张
         表格靠横向滚动（最宽 1500px），手机上不能缩小就等于没有「看全景」这条退路。
         minimum-scale=1 一并删掉：它管的是缩小下限，留着等于只修了一半。
         刻意不加 viewport-fit=cover —— modern/signature 敢写是因为它们的 CSS 自己处理了
         env(safe-area-inset-*)，而管理端三份样式表里一次都没有，加了会让内容钻到刘海下面。 --}}
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: @json($title),
            theme: {
                sidebar: @json($theme_sidebar),
                header: @json($theme_header),
                color: @json($theme_color),
            },
            version: @json($version),
            background_url: @json($background_url),
            logo: @json($logo),
            secure_path: @json($secure_path)
            ,admin_asset_version: '{{$adminAssetVersion}}'
        }
    </script>
    <script>window.adminEntry = @json($admin_entry ?? null);</script>
    {{-- Deferred scripts fetch in parallel. Translation and access guards execute
         before the real application, and no intermediate page replaces it. --}}
    <script defer src="/assets/admin/i18n.js?v={{$adminAssetVersion}}"></script>
    <script defer src="/assets/admin/security-loader.js?v={{$adminAssetVersion}}"></script>
    <script>{!! file_get_contents(public_path('assets/admin/entry.js')) !!}</script>
    {{-- Discover scripts first: inline scripts after a stylesheet otherwise wait
         for it to download. Deferred application execution still waits for CSS. --}}
    <link data-admin-style rel="stylesheet" onerror="this.dataset.failed='true'" href="/assets/admin/components.chunk.css?v={{$adminAssetVersion}}">
    <link data-admin-style rel="stylesheet" onerror="this.dataset.failed='true'" href="/assets/admin/umi.css?v={{$adminAssetVersion}}">
    <link data-admin-style rel="stylesheet" onerror="this.dataset.failed='true'" href="/assets/admin/custom.css?v={{$adminAssetVersion}}">
</head>

<body>
<div id="root" aria-busy="true"></div>
</body>

</html>
