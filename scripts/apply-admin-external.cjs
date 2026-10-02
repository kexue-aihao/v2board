'use strict';
// scripts/patch-admin-external.php 的逐字复刻，供本机（没有 PHP）应用与校验用。
//
// 两边必须保持一致：改了一处就要改另一处，否则本地永远绿、线上永远红
// （scripts/patch-admin-clean-gateway.php 上真出过这个事故）。
const fs = require('fs');
const path = require('path');

const bundlePath = path.join(__dirname, '../public/assets/admin/umi.js');
const modulePath = path.join(__dirname, 'admin-external-module.js');
let bundle = fs.readFileSync(bundlePath, 'utf8');
const changed = [];

// 1) 菜单
const menuAnchor = '                    }, {\n                        title: "动态倍率",';
if (bundle.indexOf('href: "/external"') === -1) {
  const menuItem = [
    '                    }, {',
    '                        title: "外部订阅源",',
    '                        type: "item",',
    '                        href: "/external",',
    '                        icon: o.a.createElement("i", {',
    '                            className: "nav-main-link-icon si si-link"',
    '                        })'
  ].join('\n');
  if (bundle.indexOf(menuAnchor) === -1) throw new Error('Admin external menu anchor not found.');
  bundle = bundle.replace(menuAnchor, menuItem + '\n' + menuAnchor);
  changed.push('菜单项');
}

// 2) 路由
const routeAnchor = '        }, {\n            path: "/rate",';
if (bundle.indexOf('path: "/external"') === -1) {
  const routeItem = [
    '        }, {',
    '            path: "/external",',
    '            exact: !0,',
    '            component: n("externalpage").default'
  ].join('\n');
  if (bundle.indexOf(routeAnchor) === -1) throw new Error('Admin external route anchor not found.');
  bundle = bundle.replace(routeAnchor, routeItem + '\n' + routeAnchor);
  changed.push('路由');
}

// 3) 模块本体
let moduleSource = fs.readFileSync(modulePath, 'utf8');
if (moduleSource.indexOf('externalpage: function(e, t, n) {') === -1
  || /}\)\s*$/.test(moduleSource.trim())) {
  throw new Error('Admin external module source is invalid.');
}
moduleSource = moduleSource.trim();
const moduleStart = bundle.indexOf('    externalpage: function(e, t, n) {');
const moduleEndMarker = '\n});\n\n(function () {\n';
const moduleEnd = bundle.indexOf(moduleEndMarker);
if (moduleEnd === -1) throw new Error('Admin module boundary not found.');
const before = bundle;
if (moduleStart !== -1 && moduleStart < moduleEnd) {
  bundle = bundle.slice(0, moduleStart) + '    ' + moduleSource + bundle.slice(moduleEnd);
  changed.push('模块（替换）');
} else {
  bundle = bundle.slice(0, moduleEnd) + ',\n    ' + moduleSource + bundle.slice(moduleEnd);
  changed.push('模块（新增）');
}

if (bundle === before) {
  console.log('Admin external subscription page already patched.');
  process.exit(0);
}
fs.writeFileSync(bundlePath, bundle);
console.log('Admin external subscription page patched: ' + changed.join('、') + '。');
