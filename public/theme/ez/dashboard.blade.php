<!DOCTYPE html>
<html lang="zh-CN">
<head>
    @php
        $ezAssetRoot = public_path("theme/{$theme}/assets");
        $ezManifestPath = $ezAssetRoot . '/manifest.json';
        $ezManifest = is_file($ezManifestPath)
            ? json_decode(file_get_contents($ezManifestPath), true)
            : ['styles' => [], 'scripts' => []];
        $ezStyles = is_array($ezManifest['styles'] ?? null) ? $ezManifest['styles'] : [];
        $ezScripts = is_array($ezManifest['scripts'] ?? null) ? $ezManifest['scripts'] : [];
        $ezFiles = array_merge($ezStyles, $ezScripts);
        $ezVersions = array_filter(array_map(function ($file) use ($ezAssetRoot) {
            $path = $ezAssetRoot . '/' . ltrim($file, '/');
            return is_file($path) ? filemtime($path) : null;
        }, $ezFiles));
        $ezAssetVersion = $ezVersions ? max($ezVersions) . '-' . substr(hash('sha256', implode('|', $ezFiles)), 0, 12) : $version;
        $ezAssetUrl = function ($file) use ($theme, $ezAssetVersion) {
            return '/theme/' . $theme . '/assets/' . ltrim($file, '/') . '?v=' . $ezAssetVersion;
        };
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#18212f">
    <title>{{ $title }}</title>
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: @json($title),
            description: @json($description),
            logo: @json($logo),
            assets_path: "/theme/ez/assets",
            version: @json($version),
            theme: { color: @json($theme_config['theme_color'] ?? 'default') },
            background_url: @json($theme_config['background_url'] ?? ''),
            i18n: ['zh-CN', 'en-US', 'ja-JP', 'vi-VN', 'ko-KR', 'zh-TW', 'fa-IR']
        };
    </script>
    @foreach ($ezStyles as $ezStyle)
        <link rel="stylesheet" href="{{ $ezAssetUrl($ezStyle) }}">
    @endforeach
</head>
<body>
<div id="app"></div>
{!! $theme_config['custom_html'] ?? '' !!}
@foreach ($ezScripts as $ezScript)
    <script src="{{ $ezAssetUrl($ezScript) }}"></script>
@endforeach
</body>
</html>
