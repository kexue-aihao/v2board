<?php

/**
 * 更新节点管理内嵌的动态倍率设置。
 *
 * 与 patch-admin-reward.php 同一套做法：
 *   1. 把 scripts/admin-rate-module.js 作为一个新模块追加进产物（已存在就整块替换，幂等）；
 *   2. 移除旧版独立侧栏入口；
 *   3. 旧 /rate 地址重定向到节点管理内嵌配置。
 *
 * 重复执行不再新增菜单，不覆盖节点管理或其他模块。
 */

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}
$bundle = str_replace("\r\n", "\n", $bundle);

// 1) 移除旧版独立侧栏入口。
$menuItem = <<<'JS'
                    }, {
                        title: "动态倍率",
                        type: "item",
                        href: "/rate",
                        icon: o.a.createElement("i", {
                            className: "nav-main-link-icon si si-speedometer"
                        })
JS;
$bundle = str_replace($menuItem . "\n", '', $bundle);
if (strpos($bundle, 'href: "/rate"') !== false) {
    fwrite(STDERR, "Unrecognized legacy rate menu.\n");
    exit(1);
}

// 2) 旧书签回到节点管理并展开内嵌配置。
$routeAnchor = "        }, {\n            path: \"/reward\",";
$oldRoute = <<<'JS'
        }, {
            path: "/rate",
            exact: !0,
            component: n("ratepage").default
JS;
$routeItem = str_replace('component: n("ratepage").default', 'redirect: "/server/manage?rate=1"', $oldRoute);
$bundle = str_replace($oldRoute, $routeItem, $bundle);
if (strpos($bundle, 'path: "/rate"') === false) {
    if (strpos($bundle, $routeAnchor) === false) {
        fwrite(STDERR, "Admin rate route anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($routeAnchor, $routeItem . "\n" . $routeAnchor, $bundle);
}
if (strpos($bundle, $routeItem) === false || strpos($bundle, 'this.renderRateSettings()') === false) {
    fwrite(STDERR, "Node management rate settings integration or redirect is missing.\n");
    exit(1);
}

// 3) 模块本体
$moduleSource = file_get_contents(__DIR__ . '/admin-rate-module.js');
if ($moduleSource === false
    || strpos($moduleSource, 'ratepage: function(e, t, n) {') === false
    || preg_match('/\}\)\s*$/', trim($moduleSource))) {
    fwrite(STDERR, "Admin rate module source is invalid.\n");
    exit(1);
}
$moduleSource = trim(str_replace("\r\n", "\n", $moduleSource));
$moduleStart = strpos($bundle, "    ratepage: function(e, t, n) {");
$moduleEndMarker = "\n});\n\n(function () {\n";
$moduleEnd = strpos($bundle, $moduleEndMarker);
if ($moduleEnd === false) {
    fwrite(STDERR, "Admin module boundary not found.\n");
    exit(1);
}
if ($moduleStart !== false && $moduleStart < $moduleEnd) {
    if (preg_match('/\n    [A-Za-z_$][\w$]*: function\(e, t, n\) \{/', $bundle, $next, PREG_OFFSET_CAPTURE, $moduleStart + 1)) {
        $moduleEnd = $next[0][1];
    }
    $comma = substr(rtrim(substr($bundle, $moduleStart, $moduleEnd - $moduleStart)), -1) === ',' ? ',' : '';
    $bundle = substr_replace($bundle, "    " . $moduleSource . $comma, $moduleStart, $moduleEnd - $moduleStart);
} else {
    $bundle = substr_replace($bundle, ",\n    " . $moduleSource, $moduleEnd, 0);
}

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Node dynamic rate settings patched.\n");
