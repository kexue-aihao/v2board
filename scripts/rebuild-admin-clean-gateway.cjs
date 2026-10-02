/**
 * 开发工具：把 scripts/admin-clean-gateway-module.js 的当前内容重新写进
 * public/assets/admin/umi.js。
 *
 * 为什么需要它：patch-admin-clean-gateway.php 是幂等的 —— 产物里已经是它打过的样子
 * 时，它会直接跳过模块替换（这是重复部署必须的行为）。所以改完模块源码后单跑补丁是
 * **不会**更新产物的，得先用本工具换掉模块，再把补丁跑一遍确认没有别的差异。
 *
 * 用法：node scripts/rebuild-admin-clean-gateway.cjs
 *
 * 内部做两件事，第二件是安全网：
 *   1. 以 git HEAD 的产物为基准，应用补丁里的每一处文字替换，再整块换成当前模块源码；
 *   2. 把整条补丁重新跑在**补丁之前**的原始产物（84082850）上，结果必须与刚写出的
 *      产物逐字节一致 —— 这保证「服务器上干净检出 + 跑补丁」得到的就是仓库里这份。
 *      若日后 rebase 掉了那个提交，改 BASE_COMMIT 即可。
 *
 * PHP 的补丁脚本是唯一事实源：本工具从它里面解析锚点，不另存一份，两者不会漂移。
 */
const { execSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..');
const BASE_COMMIT = '84082850';
const BS = String.fromCharCode(92);

let php = fs.readFileSync(path.join(ROOT, 'scripts/patch-admin-clean-gateway.php'), 'utf8');
// 补丁脚本里的锚点是 PHP 单引号串里**真实的换行**：文件本身变成 CRLF，锚点也跟着变，
// 于是每一处都匹配不到。同样就地归一化（仓库里存的就是 LF）。
if (php.includes('\r\n')) {
    php = php.split('\r\n').join('\n');
    fs.writeFileSync(path.join(ROOT, 'scripts/patch-admin-clean-gateway.php'), php);
    console.log('补丁脚本原本是 CRLF，已就地归一化成 LF');
}
const modulePath = path.join(ROOT, 'scripts/admin-clean-gateway-module.js');
let moduleSource = fs.readFileSync(modulePath, 'utf8');

// core.autocrlf=true 的 Windows 工作副本会被检出成 CRLF，而产物是 LF。这里直接就地
// 归一化而不是报错：本工具存在的意义就是「改完模块源码重新生成产物」，在这台机器上
// 拒绝运行等于每次都得多跑一条 sed。补丁脚本那边仍然保留硬拒绝（它跑在服务器上）。
if (moduleSource.includes('\r\n')) {
    moduleSource = moduleSource.split('\r\n').join('\n');
    fs.writeFileSync(modulePath, moduleSource);
    console.log('模块源码原本是 CRLF，已就地归一化成 LF');
}

if (!/^    riskgatewaypage: function\(e, t, n\) \{/.test(moduleSource)) {
    throw new Error('模块源码开头不对');
}
if (!/,\n$/.test(moduleSource)) {
    throw new Error('模块源码结尾必须是 ",\\n"');
}

// PHP 单引号串：只有 \\ 与 \' 是转义，其余原样；串里可以直接含换行。
function readPhpString(src, at) {
    let i = at + 1, out = '';
    while (i < src.length) {
        const c = src[i];
        if (c === BS) {
            const n = src[i + 1];
            if (n === BS || n === "'") { out += n; i += 2; continue; }
            out += c; i++; continue;
        }
        if (c === "'") return { value: out, end: i + 1 };
        out += c; i++;
    }
    throw new Error('PHP 字符串没有收尾');
}

const pairs = [];
{
    const start = php.indexOf('$replacements = [');
    const end = php.indexOf('\n];', start);
    if (start < 0 || end < 0) throw new Error('找不到 $replacements');
    let cursor = start;
    while (true) {
        const idAt = php.indexOf('=> [', cursor);
        if (idAt < 0 || idAt > end) break;
        let p = idAt + 4;
        while (/\s/.test(php[p])) p++;
        const from = readPhpString(php, p);
        p = from.end;
        while (php[p] !== "'") p++;
        const to = readPhpString(php, p);
        pairs.push({ from: from.value, to: to.value });
        cursor = to.end;
    }
}
if (!pairs.length) throw new Error('锚点数组是空的');
if (pairs.some(p => p.from.includes('\r\n'))) {
    throw new Error('补丁脚本里的锚点是 CRLF；先把脚本本身归一化成 LF');
}

function moduleRange(text, marker) {
    const s = text.indexOf(marker);
    if (s < 0) return null;
    const rest = text.slice(s + marker.length);
    const m = /\n    [A-Za-z_$][\w$]*: function\(e, t, n\) \{/.exec(rest);
    if (!m) throw new Error('找不到 ' + marker + ' 之后的模块边界');
    return [s, s + marker.length + m.index + 1];
}

function build(pristine) {
    let b = pristine;
    for (const { from, to } of pairs) {
        const at = b.indexOf(from);
        if (at >= 0) b = b.slice(0, at) + to + b.slice(at + from.length);
    }
    const gateway = moduleRange(b, '    riskgatewaypage: function(e, t, n) {');
    if (!gateway) throw new Error('产物里没有 riskgatewaypage 模块');
    b = b.slice(0, gateway[0]) + moduleSource + b.slice(gateway[1]);
    const rule = moduleRange(b, '    riskrulepage: function(e, t, n) {');
    if (rule) b = b.slice(0, rule[0]) + b.slice(rule[1]);
    return b;
}

function fromGit(commit) {
    return execSync(`git show ${commit}:public/assets/admin/umi.js`, { cwd: ROOT, maxBuffer: 1 << 28 })
        .toString('utf8').split('\r\n').join('\n');
}

const bundlePath = path.join(ROOT, 'public/assets/admin/umi.js');
const rebuilt = build(fromGit('HEAD'));

for (const needle of ['href: "/risk/rule"', 'riskrulepage', 'title: "订阅风控网关"', '/risk/rule/recompute',
    '手工补丁：风险值列', 'dataIndex: "risk"', 'key: "risk_score"', 'SubscriptionRiskService', 'applyRiskFilter']) {
    if (rebuilt.includes(needle)) throw new Error('该消失的还在：' + needle);
}
for (const needle of ['title: "订阅清洗网关"', 'href: "/risk/gateway"', 'SubscribeCleanGatewayPage',
    '/risk/gateway/export', 'risktracepage', 'risksharedippage', 'resellerpage', 'rewardpage',
    'component: n("d1ca").default']) {
    if (!rebuilt.includes(needle)) throw new Error('该在的没了：' + needle);
}

fs.writeFileSync(bundlePath, rebuilt);
console.log('产物已重建：' + rebuilt.length + ' 字符');

const simulated = build(fromGit(BASE_COMMIT));
if (simulated !== rebuilt) {
    console.error('不变量校验失败：原始产物 + 补丁 ≠ 刚写出的产物');
    for (let i = 0; i < Math.max(simulated.length, rebuilt.length); i++) {
        if (simulated[i] !== rebuilt[i]) {
            console.error('首个差异 @' + i);
            console.error('  模拟:', JSON.stringify(simulated.slice(i - 100, i + 100)));
            console.error('  产物:', JSON.stringify(rebuilt.slice(i - 100, i + 100)));
            break;
        }
    }
    process.exit(1);
}
console.log('不变量校验通过：' + BASE_COMMIT + ' 原始产物 + 补丁 == 当前产物（逐字节一致）');
