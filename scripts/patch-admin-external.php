<?php

/**
 * 给管理端加「外部订阅源」页（/external）。
 *
 * 与 patch-admin-rate.php 完全同构（那边的注释更详细）：
 *   1. 把 scripts/admin-external-module.js 作为新模块追加进产物（已存在就整块替换，幂等）；
 *   2. 菜单加一项（插在「签到与娱乐」前面，同属运营配置分组）；
 *   3. 路由加一条。
 */

$bundlePath = __DIR__ . '/../resources/admin/legacy/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

$menuAnchor = "                    }, {\n                        title: \"签到与娱乐\",";
if (strpos($bundle, 'href: "/external"') === false) {
    $menuItem = <<<'JS'
                    }, {
                        title: "外部订阅源",
                        type: "item",
                        href: "/external",
                        icon: o.a.createElement("i", {
                            className: "nav-main-link-icon si si-link"
                        })
JS;
    if (strpos($bundle, $menuAnchor) === false) {
        fwrite(STDERR, "Admin external menu anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($menuAnchor, $menuItem . "\n" . $menuAnchor, $bundle);
}

$routeAnchor = "        }, {\n            path: \"/rate\",";
if (strpos($bundle, 'path: "/external"') === false) {
    $routeItem = <<<'JS'
        }, {
            path: "/external",
            exact: !0,
            component: n("externalpage").default
JS;
    if (strpos($bundle, $routeAnchor) === false) {
        fwrite(STDERR, "Admin external route anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($routeAnchor, $routeItem . "\n" . $routeAnchor, $bundle);
}

$moduleSource = file_get_contents(__DIR__ . '/admin-external-module.js');
if ($moduleSource === false
    || strpos($moduleSource, 'externalpage: function(e, t, n) {') === false
    || preg_match('/\}\)\s*$/', trim($moduleSource))) {
    fwrite(STDERR, "Admin external module source is invalid.\n");
    exit(1);
}
$moduleSource = trim($moduleSource);
$moduleStart = strpos($bundle, "    externalpage: function(e, t, n) {");
$moduleEndMarker = "\n});\n\n(function () {\n";
$moduleEnd = strpos($bundle, $moduleEndMarker);
if ($moduleEnd === false) {
    fwrite(STDERR, "Admin module boundary not found.\n");
    exit(1);
}
if ($moduleStart !== false && $moduleStart < $moduleEnd) {
    $bundle = substr_replace($bundle, "    " . $moduleSource, $moduleStart, $moduleEnd - $moduleStart);
} else {
    $bundle = substr_replace($bundle, ",\n    " . $moduleSource, $moduleEnd, 0);
}

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Admin external subscription page patched.\n");
