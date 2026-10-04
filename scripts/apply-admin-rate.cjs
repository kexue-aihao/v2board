'use strict';
// scripts/patch-admin-rate.php 的逐字复刻，供本机（没有 PHP）应用与校验用。
//
// 两边必须保持一致：改了一处就要改另一处，否则本地永远绿、线上永远红
// （scripts/patch-admin-clean-gateway.php 上真出过这个事故）。
// 动态倍率嵌入节点管理；保留旧地址重定向，部署时不再添加独立菜单。
const fs = require('fs');
const path = require('path');

const bundlePath = path.join(__dirname, '../resources/admin/legacy/umi.js');
const modulePath = path.join(__dirname, 'admin-rate-module.js');
let bundle = fs.readFileSync(bundlePath, 'utf8').replace(/\r\n/g, '\n');
const changed = [];

// 1) 移除旧版独立侧栏入口。
const menuItem = [
    '                    }, {',
    '                        title: "动态倍率",',
    '                        type: "item",',
    '                        href: "/rate",',
    '                        icon: o.a.createElement("i", {',
    '                            className: "nav-main-link-icon si si-speedometer"',
    '                        })'
  ].join('\n') + '\n';
if (bundle.includes(menuItem)) {
  bundle = bundle.replace(menuItem, '');
  changed.push('移除独立菜单');
}
if (bundle.includes('href: "/rate"')) throw new Error('Unrecognized legacy rate menu.');

// 2) 旧书签回到节点管理并展开内嵌配置。
const routeAnchor = '        }, {\n            path: "/reward",';
const oldRoute = [
    '        }, {',
    '            path: "/rate",',
    '            exact: !0,',
    '            component: n("ratepage").default'
  ].join('\n');
const routeItem = oldRoute.replace('component: n("ratepage").default', 'redirect: "/server/manage?rate=1"');
bundle = bundle.replace(oldRoute, routeItem);
if (bundle.indexOf('path: "/rate"') === -1) {
  if (bundle.indexOf(routeAnchor) === -1) throw new Error('Admin rate route anchor not found.');
  bundle = bundle.replace(routeAnchor, routeItem + '\n' + routeAnchor);
  changed.push('路由');
}
if (!bundle.includes(routeItem)) throw new Error('Unrecognized legacy rate route.');
if (!bundle.includes('this.renderRateSettings()')) throw new Error('Node management rate settings integration is missing.');

// 3) 模块本体
let moduleSource = fs.readFileSync(modulePath, 'utf8').replace(/\r\n/g, '\n');
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
  console.log('Node dynamic rate settings already patched.');
  process.exit(0);
}
fs.writeFileSync(bundlePath, bundle);
console.log('Node dynamic rate settings patched: ' + changed.join('、') + '。');
