<?php

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

$menuAnchor = "                    }, {\n                        title: \"风控\",\n                        type: \"heading\"";
if (strpos($bundle, 'href: "/reseller"') === false) {
    $menuItem = <<<'JS'
                    }, {
                        title: "\u6e20\u9053\u7ba1\u7406",
                        type: "heading"
                    }, {
                        title: "\u5012\u5356\u5546\u7ba1\u7406",
                        type: "item",
                        href: "/reseller",
                        icon: o.a.createElement("i", {
                            className: "nav-main-link-icon si si-layers"
                        })
JS;
    if (strpos($bundle, $menuAnchor) === false) {
        fwrite(STDERR, "Admin menu anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($menuAnchor, $menuItem . "\n" . $menuAnchor, $bundle);
}

$routeAnchor = "        }, {\n            path: \"/risk/rule\",\n            exact: !0,\n            component: n(\"riskrulepage\").default";
if (strpos($bundle, 'path: "/reseller"') === false) {
    $routeItem = <<<'JS'
        }, {
            path: "/reseller",
            exact: !0,
            component: n("resellerpage").default
JS;
    if (strpos($bundle, $routeAnchor) === false) {
        fwrite(STDERR, "Admin route anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($routeAnchor, $routeItem . "\n" . $routeAnchor, $bundle);
}

if (strpos($bundle, '    resellerpage: function(e, t, n) {') === false) {
    $moduleBoundary = "\n});\n\n(function () {\n";
    $modulePosition = strpos($bundle, $moduleBoundary);
    if ($modulePosition === false) {
        fwrite(STDERR, "Admin module boundary not found.\n");
        exit(1);
    }
    $module = <<<'JS'
,
    resellerpage: function(e, t, n) {
        "use strict";
        n.r(t);
        var r = n("q1tI")
          , i = n.n(r);
        function ResellerPage() {
            return i.a.createElement("div", {
                id: "reseller-admin-module",
                className: "content"
            });
        }
        t.default = ResellerPage;
    }
JS;
    $bundle = substr_replace($bundle, $module, $modulePosition, 0);
}

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}

fwrite(STDOUT, "Admin reseller route patched.\n");
