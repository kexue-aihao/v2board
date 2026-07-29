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

$settingMarker = 'onChange: e=>this.set("safe", "reseller_enable", e ? 1 : 0)';
if (strpos($bundle, $settingMarker) === false) {
    $settingAnchor = <<<'JS'
                    onChange: e=>this.set("safe", "arithmetic_verification_enable", e ? 1 : 0)
                })), _.recaptcha_enable ?
JS;
    $setting = <<<'JS'
                    onChange: e=>this.set("safe", "arithmetic_verification_enable", e ? 1 : 0)
                })), f.a.createElement(m, {
                    title: "\u5012\u5356\u5546\u670d\u52a1",
                    description: "\u5f00\u542f\u540e\u5141\u8bb8\u5916\u90e8\u7533\u8bf7\u5012\u5356\u5546\u8d26\u53f7\uff0c\u5e76\u5f00\u653e\u5df2\u5ba1\u6838\u5e97\u94fa\u7684\u9500\u552e\u9875\u3002"
                }, f.a.createElement(l["a"], {
                    checked: parseInt(_.reseller_enable),
                    onChange: e=>this.set("safe", "reseller_enable", e ? 1 : 0)
                })), _.recaptcha_enable ?
JS;
    if (strpos($bundle, $settingAnchor) === false) {
        fwrite(STDERR, "Admin reseller setting anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($settingAnchor, $setting, $bundle);
}

$modulePath = __DIR__ . '/admin-reseller-module.js';
$moduleSource = file_get_contents($modulePath);
if ($moduleSource === false || strpos($moduleSource, 'resellerpage: function') === false) {
    fwrite(STDERR, "Admin reseller module source is invalid.\n");
    exit(1);
}
$moduleSource = trim($moduleSource);

$moduleStart = strpos($bundle, "    resellerpage: function(e, t, n) {");
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

fwrite(STDOUT, "Admin reseller route patched.\n");
