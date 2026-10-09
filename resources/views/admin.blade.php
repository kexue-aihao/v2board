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
            ['security-loader.js', 'login.js', 'components.chunk.css', 'umi.css', 'custom.css', 'startup.css', 'i18n.js'],
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
    {{-- The small initial layout paints while the full application styles load. --}}
    <style>{!! file_get_contents(public_path('assets/admin/startup.css')) !!}</style>
    <link data-admin-style rel="stylesheet" media="print" onload="this.media='all'" onerror="this.dataset.failed='true'" href="/assets/admin/components.chunk.css?v={{$adminAssetVersion}}">
    <link data-admin-style rel="stylesheet" media="print" onload="this.media='all'" onerror="this.dataset.failed='true'" href="/assets/admin/umi.css?v={{$adminAssetVersion}}">
    <link data-admin-style rel="stylesheet" media="print" onload="this.media='all'" onerror="this.dataset.failed='true'" href="/assets/admin/custom.css?v={{$adminAssetVersion}}">
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
            ,admin_asset_version: '{{$adminAssetVersion}}'
        }
    </script>
    <script>
        // Select a visible initial layout before the body paints.
        try {
            if (localStorage.getItem('authorization')) document.documentElement.classList.add('admin-startup-authenticated');
        } catch (ignore) {}
        if (window.settings.theme.sidebar === 'light') document.documentElement.classList.add('admin-startup-sidebar-light');
        if (window.settings.theme.header === 'dark') document.documentElement.classList.add('admin-startup-header-dark');
    </script>
</head>

<body>
<div id="admin-startup" class="admin-startup" role="status" aria-live="polite" aria-atomic="true">
    <div class="admin-startup__workspace" aria-hidden="true">
        <aside class="admin-startup__sidebar">
            <div class="admin-startup__brand">{{$title}}</div>
            <div id="admin-startup-menu" class="admin-startup__menu">
                <span class="admin-startup__nav-placeholder"></span>
                <span class="admin-startup__nav-placeholder"></span>
                <span class="admin-startup__nav-placeholder"></span>
                <span class="admin-startup__nav-placeholder"></span>
                <span class="admin-startup__nav-placeholder"></span>
                <span class="admin-startup__nav-placeholder"></span>
            </div>
        </aside>
        <div class="admin-startup__body">
            <div class="admin-startup__header"><span>管理后台</span><span class="admin-startup__avatar"></span></div>
            <div class="admin-startup__main">
                <h1 id="admin-startup-page-title">管理后台</h1>
                <div class="admin-startup__panel">
                    <div class="admin-startup__toolbar"><span></span><span></span></div>
                    <div class="admin-startup__row"></div>
                    <div class="admin-startup__row"></div>
                    <div class="admin-startup__row"></div>
                    <div class="admin-startup__row"></div>
                    <div class="admin-startup__row"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="admin-startup__login" aria-hidden="true">
        <div class="admin-startup__login-panel">
            <div class="admin-startup__brand">{{$title}}</div>
            <p>登录管理后台</p>
            <span class="admin-startup__input"></span>
            <span class="admin-startup__input"></span>
            <span class="admin-startup__button"></span>
        </div>
    </div>
    <span id="admin-startup-title" class="admin-startup__status">正在打开管理后台</span>
    <span id="admin-startup-message" class="admin-startup__status">页面即将就绪</span>
</div>
<div id="root" aria-busy="true"></div>
{{-- 覆盖翻译层必须是 body 内第一个脚本：fetch/XHR 的 Content-Language 补丁
     要抢在应用（含 2FA 覆盖层的裸 fetch）发出首个请求之前装好。 --}}
<script src="/assets/admin/i18n.js?v={{$adminAssetVersion}}"></script>
<script src="/assets/admin/security-loader.js?v={{$adminAssetVersion}}"></script>
</body>

</html>
