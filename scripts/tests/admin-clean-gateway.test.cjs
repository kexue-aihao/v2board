const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

// 直接跑打进产物里的「订阅清洗网关」模块，而不是断言源码字符串：列、筛选器、导出链路
// 任何一处被打回或补丁失配，这里都会红。同时断言两个旧页面确实从产物里消失了 ——
// 「合并成一个栏目」这件事在产物层面就是：/risk/rule 路由、菜单项、模块三者都不在了。
//
// 读进来先归一化成 LF：仓库里存的是 LF，但 core.autocrlf=true 的 Windows 工作副本会被
// 检出成 CRLF，而下面的模块边界扫描与菜单断言都按 \n 锚定 —— 不归一化的话，同一份产物
// 在这台机器上会假报失败。
const bundle = fs
    .readFileSync(path.join(__dirname, '../../public/assets/admin/umi.js'), 'utf8')
    .split('\r\n')
    .join('\n');

function moduleSource(key) {
    const marker = '    ' + key + ': function(e, t, n) {';
    const start = bundle.indexOf(marker);
    assert.ok(start >= 0, key + ' module must exist');
    const next = /\n    [A-Za-z_$][\w$]*: function\(e, t, n\) \{/.exec(bundle.slice(start + marker.length));
    assert.ok(next, 'module boundary must exist after ' + key);
    return bundle.slice(start, start + marker.length + next.index).trim();
}

test('旧的两个页面已经从产物里彻底消失', () => {
    assert.equal(bundle.includes('riskrulepage'), false, 'riskrulepage must be gone');
    assert.equal(bundle.includes('href: "/risk/rule"'), false, '/risk/rule menu entry must be gone');
    assert.equal(bundle.includes('path: "/risk/rule"'), false, '/risk/rule route must be gone');
    assert.equal(bundle.includes('/risk/rule/'), false, '/risk/rule API calls must be gone');
    assert.equal(bundle.includes('title: "订阅风控网关"'), false, 'gateway page title must be gone');
    assert.equal(bundle.includes('dataIndex: "risk"'), false, 'user list risk column must be gone');
    assert.equal(bundle.includes('key: "risk_score"'), false, 'risk score column must be gone');
    assert.equal(bundle.includes('applyRiskFilter'), false, 'risk filter must be gone');
    assert.equal(bundle.includes('/user/risk'), false, 'risk endpoint reference must be gone');
});

test('侧栏只剩一个清洗网关入口，指向 /risk/gateway', () => {
    const menu = bundle.match(/title: "订阅清洗网关",\n {24}type: "item",\n {24}href: "([^"]+)"/g) || [];
    assert.equal(menu.length, 1, 'exactly one 订阅清洗网关 menu entry');
    assert.ok(menu[0].includes('href: "/risk/gateway"'), 'menu must point at /risk/gateway');
    assert.ok(bundle.includes('component: n("riskgatewaypage").default'), 'gateway route must stay');
});

function harness(respond) {
    const requests = [];
    const notices = [];
    class Component {
        constructor(props) {
            this.props = props;
            this.state = {};
        }
        setState(patch, callback) {
            Object.assign(this.state, typeof patch === 'function' ? patch(this.state) : patch);
            if (callback) callback();
        }
    }
    const react = {
        Component,
        createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
        Fragment: 'Fragment',
    };
    const modules = {
        q1tI: react,
        jehZ: Object.assign,
        wCAj: { a: 'Table' },
        '2/Rp': { a: 'Button' },
        '5rEg': { a: Object.assign(function Input() {}, { Group: 'Group', TextArea: 'TextArea' }) },
        CtXQ: { a: 'Icon' },
        kLXV: {
            a: Object.assign(function Modal() {}, {
                warning: options => notices.push(['warning', options]),
                confirm: options => notices.push(['confirm', options]),
                success: options => notices.push(['success', options]),
                error: options => notices.push(['error', options]),
            }),
        },
        '2fM7': { a: Object.assign(function Select() {}, { Option: 'Option' }) },
        mr32: { a: 'Tag' },
        Bl7J: { a: 'Page' },
        // v32e 是包了 antd Spin 的容器（spinning 取自 props.loading），不是 Card。
        v32e: { a: 'Spin' },
        'wd/R': () => ({ format: () => '20261002-120000' }),
        t3Un: {
            a: async (url, params) => {
                requests.push({ method: 'get', url: endpoint(url), params });
                return respond(endpoint(url), params);
            },
            b: async (url, params) => {
                requests.push({ method: 'post', url: endpoint(url), params });
                return respond(endpoint(url), params);
            },
        },
    };
    const requireModule = id => modules[id] || { a: () => {} };
    requireModule.r = () => {};
    // webpack 的 n.n：返回一个「取值函数」，函数自身又挂在 .a 上 —— 模块里既用它直接
    // 取默认导出（i()(...)），也用它当命名空间（p.a.Component）。
    requireModule.n = value => {
        const getter = () => value;
        getter.a = value;
        return getter;
    };
    // secure_path 不带前导斜杠（配置里的真实形态），模块自己拼 "/" + secure_path + path。
    global.window = { settings: { secure_path: 'test-admin' }, URL: { createObjectURL: () => 'blob:x', revokeObjectURL: () => {} } };
    global.document = {
        createElement: () => ({ click: () => {} }),
        body: { appendChild: () => {}, removeChild: () => {} },
    };
    const source = moduleSource('riskgatewaypage')
        .replace(/^riskgatewaypage:\s*/, '')
        .replace(/,$/, '');
    const factory = vm.runInThisContext('(' + source + ')');
    const exported = {};
    factory({}, exported, requireModule);
    return { Page: exported.default, requests, notices, modules };
}

// 请求助手会把 secure_path 前缀拼进 URL，断言只关心业务路径。
function endpoint(url) {
    return String(url).replace('/test-admin', '') || url;
}

function sampleRow(overrides) {
    return Object.assign({
        id: 7,
        user_id: 3,
        user_email: 'a@example.com',
        subscription_id: 12,
        subscriptions: [
            { id: 12, plan_id: 2, plan_name: '标准', status: 'active', expired_at: 0, expired_at_text: '' },
            { id: 15, plan_id: 3, plan_name: '高级', status: 'active', expired_at: 0, expired_at_text: '' },
        ],
        request_ip: '203.0.113.9',
        isp: '中国电信',
        organization: 'Chinanet',
        asn: 4134,
        country_name: '中国',
        region: '广东省',
        city: '深圳市',
        location_status: 'resolved',
        user_agent: 'Clash/1.0',
        ua_hash: 'f'.repeat(64),
        hit_count: 42,
        first_seen_at: 1,
        first_seen_text: '2026-09-01 08:00:00',
        last_seen_at: 2,
        last_seen_text: '2026-10-02 09:15:00',
        block: null,
    }, overrides || {});
}

function respondFor(rows) {
    return (url) => {
        if (url.endsWith('/risk/gateway/fetch')) {
            return { code: 200, data: rows, total: rows.length, available: true, page: 1, pageSize: 20 };
        }
        if (url.endsWith('/risk/gateway/options')) {
            return { code: 200, data: { plans: [{ id: 2, name: '标准' }], scopes: [], retention_days: 180, retention_min: 1, retention_default: 180 } };
        }
        if (url.endsWith('/risk/gateway/config')) {
            return { code: 200, data: { retention_days: 180, records: 3, raw_logs: 9, earliest_text: '2026-01-01 00:00:00', last_cleaned_text: '' } };
        }
        if (url.endsWith('/risk/gateway/rules') || url.endsWith('/risk/gateway/history')) {
            return { code: 200, data: [], total: 0, available: true };
        }
        if (url.endsWith('/risk/gateway/export')) {
            return { code: 200, buffer: new ArrayBuffer(8) };
        }
        return { code: 200, data: {} };
    };
}

// 把 createElement 树摊平成可检索的文本，用来断言列头/单元格确实渲染出来了。
function flatten(node) {
    if (node === null || node === undefined || node === false) return [];
    if (typeof node === 'string' || typeof node === 'number') return [String(node)];
    if (Array.isArray(node)) return node.flatMap(flatten);
    if (node.children) return flatten(node.children);
    return [];
}

// 遍历整棵元素树（flatten 只取文本，这个取元素本身）。
function nodes(node) {
    if (Array.isArray(node)) return node.flatMap(nodes);
    if (!node || typeof node !== 'object') return [];
    return [node, ...(node.children || []).flatMap(nodes)];
}

function columnTitles(page) {
    const tables = [];
    (function walk(node) {
        if (!node || typeof node !== 'object') return;
        if (node.type === 'Table') tables.push(node);
        if (Array.isArray(node)) return node.forEach(walk);
        if (node.children) walk(node.children);
    })(page);
    return tables.length ? tables[0].props.columns.map(column => column.title) : [];
}

test('列表按需求渲染出账号/订阅/IP/UA/次数/时间/阻断七列', async () => {
    const { Page, requests } = harness(respondFor([sampleRow()]));
    const page = new Page({});
    await page.componentDidMount();
    const titles = columnTitles(page.render());
    for (const expected of ['账号', '订阅', 'IP 记录', 'User-Agent', '次数', '时间（UTC+8）', '阻断状态', '操作']) {
        assert.ok(titles.includes(expected), 'missing column ' + expected);
    }
    assert.ok(requests.some(r => endpoint(r.url) === '/risk/gateway/fetch'), 'list request must hit /risk/gateway/fetch');
});

test('每列都带筛选条件，且筛选参数按 UTC+8 原文回传', async () => {
    const { Page, requests } = harness(respondFor([sampleRow()]));
    const page = new Page({});
    await page.componentDidMount();
    page.setFilter('email', 'a@example.com');
    page.setFilter('ip', '203.0.113.9');
    page.setFilter('carrier', '电信');
    page.setFilter('asn', 'AS4134');
    page.setFilter('user_agent', 'Clash');
    page.setFilter('hit_condition', '>');
    page.setFilter('hit_count', '10');
    page.setFilter('blocked', 'no');
    page.setFilter('start_time', '2026-10-01 00:00');
    page.setFilter('end_time', '2026-10-02 23:59');
    page.setFilter('plan_id', '2');
    page.setFilter('subscription_id', '12');
    requests.length = 0;
    await page.fetch(1);
    const call = requests.find(r => endpoint(r.url) === '/risk/gateway/fetch');
    assert.ok(call, 'fetch must be called');
    for (const [key, value] of Object.entries({
        email: 'a@example.com',
        ip: '203.0.113.9',
        carrier: '电信',
        asn: 'AS4134',
        user_agent: 'Clash',
        hit_condition: '>',
        hit_count: '10',
        blocked: 'no',
        start_time: '2026-10-01 00:00',
        end_time: '2026-10-02 23:59',
        plan_id: '2',
        subscription_id: '12',
    })) {
        assert.equal(call.params[key], value, 'filter ' + key + ' must be sent verbatim');
    }
});

test('导出走 POST /risk/gateway/export 并带上当前筛选条件', async () => {
    const { Page, requests } = harness(respondFor([sampleRow()]));
    const page = new Page({});
    await page.componentDidMount();
    page.setFilter('ip', '203.0.113.9');
    requests.length = 0;
    await page.exportCsv();
    const call = requests.find(r => endpoint(r.url) === '/risk/gateway/export');
    assert.ok(call, 'export must be called');
    assert.equal(call.method, 'post');
    assert.equal(call.params.ip, '203.0.113.9');
    // 导出不该带排序 —— 后端按固定顺序出，跟屏幕上的排序解耦。
    assert.equal('sort' in call.params, false);
});

test('阻断按行提交 summary_id + scope + 原因', async () => {
    const { Page, requests } = harness(respondFor([sampleRow()]));
    const page = new Page({});
    await page.componentDidMount();
    page.openBlock(sampleRow());
    page.setState({ blockReason: '同一 UA 高频拉取' });
    requests.length = 0;
    await page.submitBlock();
    const call = requests.find(r => endpoint(r.url) === '/risk/gateway/block');
    assert.ok(call, 'block must be called');
    assert.equal(call.params.summary_id, 7);
    assert.equal(call.params.scope, 'ip');
    assert.equal(call.params.reason, '同一 UA 高频拉取');
    assert.equal('expires_at' in call.params, false);
});

test('阻断缺少原因时只提示，不发请求', async () => {
    const { Page, requests, notices } = harness(respondFor([sampleRow()]));
    const page = new Page({});
    await page.componentDidMount();
    page.openBlock(sampleRow());
    requests.length = 0;
    await page.submitBlock();
    assert.equal(requests.length, 0);
    assert.ok(notices.some(([type]) => type === 'warning'));
});

test('已阻断的行给的是解除入口而不是再阻断', () => {
    const { Page } = harness(respondFor([]));
    const page = new Page({});
    const blocked = sampleRow({ block: { id: 5, scope: 'ip', reason: 'x', expires_at: null, expires_at_text: '' } });
    const columns = page.renderTable().props.columns;
    const action = columns.find(column => column.key === 'action');
    const element = action.render(null, blocked);
    assert.equal(flatten(element).join(''), '解除阻断');
});

test('表头排序按 dataIndex 回传，时间列以 last_seen_at 参与排序', async () => {
    const { Page, requests } = harness(respondFor([sampleRow()]));
    const page = new Page({});
    await page.componentDidMount();

    const columns = page.renderTable().props.columns;
    const timeColumn = columns.find(column => column.key === 'last_seen_at');
    // antd 把 sorter.field 设成 dataIndex；写 last_seen_text 后端不认识，会永远退到默认排序。
    assert.equal(timeColumn.dataIndex, 'last_seen_at');

    requests.length = 0;
    page.tableChange({ current: 1, pageSize: 20 }, {}, { field: 'last_seen_at', order: 'ascend' });
    await new Promise(resolve => setImmediate(resolve));
    const call = requests.find(r => endpoint(r.url) === '/risk/gateway/fetch');
    assert.ok(call, 'fetch must be called after sorting');
    assert.equal(call.params.sort, 'last_seen_at');
    assert.equal(call.params.sort_dir, 'asc');
});

test('行数触顶时补「+」，没触顶就显示精确值', () => {
    const { Page } = harness(respondFor([]));
    const page = new Page({});
    page.setState({
        available: true,
        stats: { retention_days: 180, records: 3570, raw_logs: 8546, earliest_text: '', last_cleaned_text: '' },
    });
    let text = flatten(page.render()).join('|');
    assert.ok(text.includes('3570'), 'exact count must be rendered as-is');
    assert.ok(text.includes('8546'), 'exact raw log count must be rendered as-is');
    assert.equal(text.includes('3570+'), false, 'exact count must not carry a + suffix');

    page.setState({
        stats: { retention_days: 180, records: 20000, raw_logs: 20000, counts_capped: true },
    });
    text = flatten(page.render()).join('|');
    assert.ok(text.includes('20000+'), 'capped counts must carry a + suffix');
});

test('请求被拒时表格的 loading 必须清掉，否则整块会盖上不可点的模糊层', async () => {
    // antd 的 Table 在 loading 时会给容器加 ant-spin-blur（opacity .5 + pointer-events:none），
    // 请求被拒却不清 loading 的话，那张表就永远「看得见、点不动」。
    const boom = () => { throw new Error('network down'); };
    const { Page } = harness(boom);
    const page = new Page({});
    assert.equal(page.state.loading, true, 'starts spinning');
    assert.equal(page.state.rulesLoading, true, 'starts spinning');
    assert.equal(page.state.historyLoading, true, 'starts spinning');
    page.componentDidMount();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(page.state.loading, false, 'main table must stop spinning');
    assert.equal(page.state.rulesLoading, false, 'rules table must stop spinning');
    assert.equal(page.state.historyLoading, false, 'history table must stop spinning');
});

test('外层 Spin 容器必须显式关掉，否则整页发灰且点不动', () => {
    // v32e 不是 Card，是包了 antd Spin 的容器，spinning 取自 props.loading。
    // 不传这个 prop 时 spinning 是 undefined，而 antd 的 Spin 默认就是转 —— 整块内容
    // 会被 .ant-spin-blur（opacity .5 + pointer-events: none）盖住：看得见、点不动。
    const { Page } = harness(respondFor([]));
    const page = new Page({});
    const spinner = nodes(page.render()).find(node => node.type === 'Spin');
    assert.ok(spinner, '外层容器必须渲染');
    assert.equal(spinner.props.loading, false, '外层容器必须被显式告知不要转');

    // 表未安装那条分支同样走这个容器，别漏。
    page.setState({ available: false });
    const bare = nodes(page.render()).find(node => node.type === 'Spin');
    assert.ok(bare, '未安装分支也要渲染容器');
    assert.equal(bare.props.loading, false, '未安装分支同样不能转');
});

test('风险程度列：有值显示百分比，没算过显示「—」', () => {
    const { Page } = harness(respondFor([]));
    const page = new Page({});
    page.setState({ riskThreshold: 60 });
    const column = page.renderTable().props.columns.find(c => c.key === 'risk_percent');
    assert.ok(column, '必须有一列风险程度');

    const over = flatten(column.render(87.5, sampleRow({ risk_percent: 87.5, risk_blocked_count: 35, risk_total_count: 40 }))).join('|');
    assert.ok(over.includes('87.5%'), '百分比要显示出来');
    assert.ok(over.includes('35'), '要带阻断次数');
    assert.ok(over.includes('40'), '要带总次数');

    // 台账里没有这个账号 → null，不能当成 0%
    const unknown = flatten(column.render(null, sampleRow({ risk_percent: null }))).join('|');
    assert.equal(unknown.trim(), '—');

    // 已处理的账号在列里标出来
    const handled = flatten(column.render(70, sampleRow({ risk_percent: 70, risk_handled_at: 1 }))).join('|');
    assert.ok(handled.includes('已处理'));
});

test('风险程度筛选按条件 + 数值回传', async () => {
    const { Page, requests } = harness(respondFor([sampleRow()]));
    const page = new Page({});
    await page.componentDidMount();
    page.setFilter('risk_condition', '>');
    page.setFilter('risk_percent', '60');
    requests.length = 0;
    await page.fetch(1);
    const call = requests.find(r => endpoint(r.url) === '/risk/gateway/fetch');
    assert.ok(call, 'fetch must be called');
    assert.equal(call.params.risk_condition, '>');
    assert.equal(call.params.risk_percent, '60');
});

test('待处理风险账号区块：列出账号并带「标记已处理」', () => {
    const pending = [{
        user_id: 9, user_email: 'bad@example.com', risk_percent: 87.5,
        blocked_count: 35, total_count: 40, last_seen_text: '2026-10-02 09:15:00',
        notify_count: 3, notified_at_text: '2026-10-02 09:30:00',
    }];
    const { Page } = harness(respondFor([]));
    const page = new Page({});
    page.setState({ risk: pending, riskTotal: 1, riskLoading: false });
    const table = page.renderRiskPending();
    assert.equal(table.props.dataSource, pending);
    const action = table.props.columns.find(c => c.key === 'action');
    assert.equal(flatten(action.render(null, pending[0])).join(''), '标记已处理');
    // 列的 render 不会被 flatten 走到（Table 的列是 props 不是 children），逐列渲染来断言
    const percent = table.props.columns.find(c => c.key === 'risk_percent');
    assert.ok(flatten(percent.render(pending[0].risk_percent, pending[0])).join('').includes('87.5%'), '风险程度要显示');
    const ratio = table.props.columns.find(c => c.key === 'ratio');
    assert.ok(flatten(ratio.render(null, pending[0])).join('').includes('35'), '要显示阻断次数');
});

test('标记已处理走 POST /risk/gateway/risk/handle', async () => {
    const { Page, requests, notices } = harness(respondFor([]));
    const page = new Page({});
    await page.componentDidMount();
    requests.length = 0;
    page.handleRisk({ user_id: 9, user_email: 'bad@example.com' });
    // confirm 是桩，取出它记下的 onOk 手动执行
    const confirm = notices.find(([type]) => type === 'confirm');
    assert.ok(confirm, '必须先弹确认');
    await confirm[1].onOk();
    const call = requests.find(r => endpoint(r.url) === '/risk/gateway/risk/handle');
    assert.ok(call, 'handle must be called');
    assert.equal(call.method, 'post');
    assert.equal(call.params.user_id, 9);
});

test('留存设置保存到 /risk/gateway/config/save', async () => {
    const { Page, requests } = harness(respondFor([]));
    const page = new Page({});
    await page.componentDidMount();
    page.setState({ retentionInput: '90' });
    requests.length = 0;
    await page.saveRetention();
    const call = requests.find(r => endpoint(r.url) === '/risk/gateway/config/save');
    assert.ok(call, 'config save must be called');
    assert.equal(call.params.retention_days, 90);
});

test('留存天数填了非数字就只提示，不发请求', async () => {
    const { Page, requests, notices } = harness(respondFor([]));
    const page = new Page({});
    await page.componentDidMount();
    page.setState({ retentionInput: 'abc' });
    requests.length = 0;
    await page.saveRetention();
    assert.equal(requests.length, 0);
    assert.ok(notices.some(([type]) => type === 'warning'));
});

test('表未安装时页面给出升级提示而不是空表', () => {
    const { Page } = harness(respondFor([]));
    const page = new Page({});
    page.setState({ available: false });
    const text = flatten(page.render()).join('');
    assert.ok(text.includes('v2board:update'), 'must tell the operator to run the upgrade');
});

test('归属地三种状态分别展示，未解析不冒充未知', () => {
    const { Page } = harness(respondFor([]));
    const page = new Page({});
    const columns = page.renderTable().props.columns;
    const ipColumn = columns.find(column => column.key === 'request_ip');
    // 有运营商时只写运营商，地理不抢位。
    assert.equal(flatten(ipColumn.render('1.1.1.1', sampleRow())).join('|'), '1.1.1.1|中国电信 · Chinanet · AS4134');
    // 三档都没有时才落回状态。
    const bare = { isp: '', organization: '', asn: null, country_name: '', region: '', city: '' };
    assert.ok(flatten(ipColumn.render('1.1.1.1', sampleRow(Object.assign({ location_status: 'pending' }, bare)))).join('|').includes('解析中'));
    assert.ok(flatten(ipColumn.render('1.1.1.1', sampleRow(Object.assign({ location_status: 'unknown' }, bare)))).join('|').includes('未知'));
});

test('运营商查不到时退到国家/省/市，而不是一句「未知」', () => {
    const { Page } = harness(respondFor([]));
    const page = new Page({});
    const ipColumn = page.renderTable().props.columns.find(column => column.key === 'request_ip');
    const abroad = sampleRow({ isp: '', organization: '', asn: null, country_name: '德国', region: '', city: '法兰克福' });
    assert.equal(flatten(ipColumn.render('5.34.219.253', abroad)).join('|'), '5.34.219.253|德国 · 法兰克福');

    // 只有国家也不能重复写两遍
    const onlyCountry = sampleRow({ isp: '', organization: '', asn: null, country_name: '美国', region: '美国', city: '' });
    assert.equal(flatten(ipColumn.render('1.2.3.4', onlyCountry)).join('|'), '1.2.3.4|美国');

    // 地理也没有，才落回状态
    const nothing = sampleRow({ isp: '', organization: '', asn: null, country_name: '', region: '', city: '', location_status: 'unknown' });
    assert.ok(flatten(ipColumn.render('1.2.3.4', nothing)).join('|').includes('未知'));
});

test('订阅列把该账号的全部订阅都列出来，本行那条排最前', () => {
    const { Page } = harness(respondFor([]));
    const page = new Page({});
    const columns = page.renderTable().props.columns;
    const subscriptionColumn = columns.find(column => column.key === 'subscriptions');
    const text = flatten(subscriptionColumn.render(null, sampleRow())).join('|');
    assert.ok(text.indexOf('#12 标准') < text.indexOf('#15 高级'), 'current subscription must come first');
    assert.ok(text.includes('#15 高级'), 'every subscription of the account must be listed');
});
