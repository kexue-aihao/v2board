<?php

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

$menuAnchor = "                    }, {\n                        title: \"风控\",\n                        type: \"heading\"";
if (strpos($bundle, 'href: "/reward"') === false) {
    $menuItem = <<<'JS'
                    }, {
                        title: "运营配置",
                        type: "heading"
                    }, {
                        title: "签到与娱乐",
                        type: "item",
                        href: "/reward",
                        icon: o.a.createElement("i", {
                            className: "nav-main-link-icon si si-game-controller"
                        })
JS;
    if (strpos($bundle, $menuAnchor) === false) {
        fwrite(STDERR, "Admin reward menu anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($menuAnchor, $menuItem . "\n" . $menuAnchor, $bundle);
}

$routeAnchor = "        }, {\n            path: \"/risk/rule\",\n            exact: !0,\n            component: n(\"riskrulepage\").default";
if (strpos($bundle, 'path: "/reward"') === false) {
    $routeItem = <<<'JS'
        }, {
            path: "/reward",
            exact: !0,
            component: n("rewardpage").default
JS;
    if (strpos($bundle, $routeAnchor) === false) {
        fwrite(STDERR, "Admin reward route anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($routeAnchor, $routeItem . "\n" . $routeAnchor, $bundle);
}

$modulePath = __DIR__ . '/admin-reward-module.js';
$moduleSource = file_get_contents($modulePath);
if ($moduleSource === false
    || strpos($moduleSource, 'rewardpage: function') === false
    || preg_match('/\}\)\s*$/', trim($moduleSource))) {
    fwrite(STDERR, "Admin reward module source is invalid.\n");
    exit(1);
}
$moduleSource = trim($moduleSource);
$moduleStart = strpos($bundle, "    rewardpage: function(e, t, n) {");
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
fwrite(STDOUT, "Admin reward route patched.\n");
