'use strict';
// scripts/patch-admin-rate.php 的逐字复刻，供本机（没有 PHP）应用与校验用。
//
// 两边必须保持一致：改了一处就要改另一处，否则本地永远绿、线上永远红
// （scripts/patch-admin-clean-gateway.php 上真出过这个事故）。
// 对应关系：菜单锚点 / 路由锚点 / 模块插入点三处 str_replace 与 substr_replace 一一照搬。
const fs = require('fs');
const path = require('path');

const bundlePath = path.join(__dirname, '../public/assets/admin/umi.js');
const modulePath = path.join(__dirname, 'admin-rate-module.js');
let bundle = fs.readFileSync(bundlePath, 'utf8');
const changed = [];

// 1) 菜单
const menuAnchor = '                    }, {\n                        title: "签到与娱乐",';
if (bundle.indexOf('href: "/rate"') === -1) {
  const menuItem = [
    '                    }, {',
    '                        title: "动态倍率",',
    '                        type: "item",',
    '                        href: "/rate",',
    '                        icon: o.a.createElement("i", {',
    '                            className: "nav-main-link-icon si si-speedometer"',
    '                        })'
  ].join('\n');
  if (bundle.indexOf(menuAnchor) === -1) throw new Error('Admin rate menu anchor not found.');
  bundle = bundle.replace(menuAnchor, menuItem + '\n' + menuAnchor);
  changed.push('菜单项');
}

// 2) 路由
const routeAnchor = '        }, {\n            path: "/reward",';
if (bundle.indexOf('path: "/rate"') === -1) {
  const routeItem = [
    '        }, {',
    '            path: "/rate",',
    '            exact: !0,',
    '            component: n("ratepage").default'
  ].join('\n');
  if (bundle.indexOf(routeAnchor) === -1) throw new Error('Admin rate route anchor not found.');
  bundle = bundle.replace(routeAnchor, routeItem + '\n' + routeAnchor);
  changed.push('路由');
}

// 3) 模块本体
let moduleSource = fs.readFileSync(modulePath, 'utf8');
if (moduleSource.indexOf('ratepage: function(e, t, n) {') === -1
  || /}\)\s*$/.test(moduleSource.trim())) {
  throw new Error('Admin rate module source is invalid.');
}
moduleSource = moduleSource.trim();
const moduleStart = bundle.indexOf('    ratepage: function(e, t, n) {');
const moduleEndMarker = '\n});\n\n(function () {\n';
let moduleEnd = bundle.indexOf(moduleEndMarker);
if (moduleEnd === -1) throw new Error('Admin module boundary not found.');
if (moduleStart !== -1 && moduleStart < moduleEnd) {
  const next = bundle.slice(moduleStart + 1).search(/\n    [A-Za-z_$][\w$]*: function\(e, t, n\) \{/);
  if (next >= 0) moduleEnd = moduleStart + 1 + next;
  const comma = bundle.slice(moduleStart, moduleEnd).trim().endsWith(',') ? ',' : '';
  bundle = bundle.slice(0, moduleStart) + '    ' + moduleSource + comma + bundle.slice(moduleEnd);
  changed.push('模块（替换）');
} else {
  bundle = bundle.slice(0, moduleEnd) + ',\n    ' + moduleSource + bundle.slice(moduleEnd);
  changed.push('模块（新增）');
}

if (!changed.length) {
  console.log('Admin dynamic rate page already patched.');
  process.exit(0);
}
fs.writeFileSync(bundlePath, bundle);
console.log('Admin dynamic rate page patched: ' + changed.join('、') + '。');
