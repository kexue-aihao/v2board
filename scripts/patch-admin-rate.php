<?php

/**
 * 给管理端加「动态倍率」页（/rate）。
 *
 * 与 patch-admin-reward.php 同一套做法：
 *   1. 把 scripts/admin-rate-module.js 作为一个新模块追加进产物（已存在就整块替换，幂等）；
 *   2. 菜单加一项（挂在「签到与娱乐」前面，同属运营配置分组）；
 *   3. 路由加一条。
 *
 * 三处都先判存在再写，所以部署时反复跑不会重复插入。
 */

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

// 1) 菜单项：插在「签到与娱乐」之前，复用同一分组下的图标写法
$menuAnchor = "                    }, {\n                        title: \"签到与娱乐\",";
if (strpos($bundle, 'href: "/rate"') === false) {
    $menuItem = <<<'JS'
                    }, {
                        title: "动态倍率",
                        type: "item",
                        href: "/rate",
                        icon: o.a.createElement("i", {
                            className: "nav-main-link-icon si si-speedometer"
                        })
JS;
    if (strpos($bundle, $menuAnchor) === false) {
        fwrite(STDERR, "Admin rate menu anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($menuAnchor, $menuItem . "\n" . $menuAnchor, $bundle);
}

// 2) 路由
$routeAnchor = "        }, {\n            path: \"/reward\",";
if (strpos($bundle, 'path: "/rate"') === false) {
    $routeItem = <<<'JS'
        }, {
            path: "/rate",
            exact: !0,
            component: n("ratepage").default
JS;
    if (strpos($bundle, $routeAnchor) === false) {
        fwrite(STDERR, "Admin rate route anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($routeAnchor, $routeItem . "\n" . $routeAnchor, $bundle);
}

// 3) 模块本体
$moduleSource = file_get_contents(__DIR__ . '/admin-rate-module.js');
if ($moduleSource === false
    || strpos($moduleSource, 'ratepage: function(e, t, n) {') === false
    || preg_match('/\}\)\s*$/', trim($moduleSource))) {
    fwrite(STDERR, "Admin rate module source is invalid.\n");
    exit(1);
}
$moduleSource = trim($moduleSource);
$moduleStart = strpos($bundle, "    ratepage: function(e, t, n) {");
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
fwrite(STDOUT, "Admin dynamic rate page patched.\n");
