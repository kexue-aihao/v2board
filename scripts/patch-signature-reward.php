<?php

$root = dirname(__DIR__);
$navPath = $root . '/public/theme/signature/assets/static/js/7461.ee1d38eb.js';
$entryPath = $root . '/public/theme/signature/assets/static/js/index.82dc6e81.js';
$modulePath = __DIR__ . '/signature-reward-module.js';

foreach ([$navPath, $entryPath, $modulePath] as $path) {
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "Signature reward asset is unavailable: {$path}\n");
        exit(1);
    }
}

$nav = file_get_contents($navPath);
if ($nav === false) {
    fwrite(STDERR, "Unable to read Signature navigation bundle.\n");
    exit(1);
}

$navAnchor = 'i&&i.i18nKey!==(null===o||void 0===o?void 0:o.i18nKey)&&e.push(i),e.push({title:"More",path:"/more",name:"More",icon:"IconMore",i18nKey:"more"}),e';
$navReplacement = 'i&&i.i18nKey!==(null===o||void 0===o?void 0:o.i18nKey)&&e.push(i),e.push({title:"Reward",path:"/reward",name:"Reward",icon:"IconGift",i18nKey:"reward"}),e.push({title:"More",path:"/more",name:"More",icon:"IconMore",i18nKey:"more"}),e';
if (strpos($nav, 'path:"/reward"') === false) {
    if (strpos($nav, $navAnchor) === false) {
        fwrite(STDERR, "Signature reward navigation anchor not found.\n");
        exit(1);
    }
    $nav = str_replace($navAnchor, $navReplacement, $nav);
}
if (strpos($nav, 'case"IconGift":return y.A;') === false) {
    $iconAnchor = 'case"IconMore":return Le;case"IconFileText"';
    if (strpos($nav, $iconAnchor) === false) {
        fwrite(STDERR, "Signature reward icon anchor not found.\n");
        exit(1);
    }
    $nav = str_replace($iconAnchor, 'case"IconMore":return Le;case"IconGift":return y.A;case"IconFileText"', $nav);
}
$routeAnchor = '{path:"more",name:"More",component:function(){return n.e(2142).then(n.bind(n,2142))},meta:{titleKey:"menu.more",requiresAuth:!0}}';
$routeReplacement = '{path:"reward",name:"Reward",component:function(){return n.e(2142).then(n.bind(n,2142))},meta:{titleKey:"menu.reward",requiresAuth:!0,activeNav:"Reward"}},{path:"more",name:"More",component:function(){return n.e(2142).then(n.bind(n,2142))},meta:{titleKey:"menu.more",requiresAuth:!0}}';
if (strpos($nav, '{path:"reward",name:"Reward"') === false) {
    if (strpos($nav, $routeAnchor) === false) {
        fwrite(STDERR, "Signature reward route anchor not found.\n");
        exit(1);
    }
    $nav = str_replace($routeAnchor, $routeReplacement, $nav);
}
$labelAnchor = '(0,s.v_)(e.$t("menu.".concat(t.i18nKey))),1)';
if (strpos($nav, '"Reward"===t.name?"签到娱乐"') === false && strpos($nav, $labelAnchor) !== false) {
    $nav = str_replace($labelAnchor, '(0,s.v_)("Reward"===t.name?"签到娱乐":e.$t("menu.".concat(t.i18nKey))),1)', $nav);
}
if (file_put_contents($navPath, $nav, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write Signature navigation bundle.\n");
    exit(1);
}

$entry = file_get_contents($entryPath);
$module = file_get_contents($modulePath);
if ($entry === false || $module === false) {
    fwrite(STDERR, "Unable to read Signature reward module.\n");
    exit(1);
}
// 2026-08-21 修复黑屏：不要用脆弱正则剥离旧模块——旧模块内部有嵌套的 })();，
// 非贪婪匹配会过早停下，残留破损代码导致 index.js SyntaxError，浏览器直接黑屏。
// 改为定位 webpack entry 的特征前缀，丢弃其之前的全部内容（兼容任何脏状态）。
$webpackMarker = '(()=>{var e={51406';
$webpackPos = strpos($entry, $webpackMarker);
if ($webpackPos === false) {
    fwrite(STDERR, "Unable to locate webpack entry marker in Signature entry bundle.\n");
    exit(1);
}
$entry = $module . substr($entry, $webpackPos);
if (file_put_contents($entryPath, $entry, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write Signature entry bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Signature reward navigation and module patched.\n");
