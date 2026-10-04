const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

// 「动态倍率」页跑在产物里的整段模块上（不是源码文件），所以这里从 umi.js 里抠出来跑 ——
// 模块里任何一个拼错的 n(...) 模块 id、或 render 里引用了不存在的字段，都会在这里炸。
const bundle = fs.readFileSync(path.join(__dirname, '../../public/assets/admin/umi.js'), 'utf8');
/**
 * 从产物里抠出某个模块的源码。边界不写死下一个模块名 —— 后面的补丁还会往产物尾部追加模块，
 * 写死「到模块对象结尾」的话，下次追加就会把两个模块一起抠出来（clean-gateway 上踩过这个坑）。
 */
function moduleSource(key) {
    const start = bundle.indexOf('    ' + key + ': function(e, t, n) {');
    assert.ok(start >= 0, key + ' 模块必须存在于产物里');
    const rest = bundle.slice(start + 1);
    const next = rest.search(/\n    [A-Za-z_$][\w$]*: function\(e, t, n\) \{/);
    const end = next >= 0 ? start + 1 + next : bundle.indexOf('\n});\n\n(function () {', start);
    assert.ok(end > start, key + ' 的模块边界找不到');
    // 抠出来的是对象字面量的一项（"xxxpage: function …"），去掉键名才是可求值的表达式
    return bundle.slice(start, end).trim().slice((key + ': ').length).replace(/,$/, '');
}

const FETCH_PAYLOAD = {
    data: {
        rules: [
            { id: 3, scope: 'global', node_type: '', node_id: 0, weekdays: '1,2,3,4,5', start_minute: 1200, end_minute: 1380, multiplier: '1.500', enabled: 1, remark: '晚高峰' },
            { id: 4, scope: 'node', node_type: 'vmess', node_id: 7, weekdays: '', start_minute: 0, end_minute: 1440, multiplier: '2.000', enabled: 0, remark: '' }
        ],
        settings: { enabled: 1, instant_mbps: 50, sustained_mbps: 10, burst_exempt_minutes: 3, stack_minutes: 5, stack_multiplier: 1.5, decay_step: 1 },
        policies_ready: true,
        policies: [{ id: 3, name: '专线', revision: 2, node_count: 0, enabled: 1, instant_mbps: 50, sustained_mbps: 10, burst_exempt_minutes: 3, stack_minutes: 5, stack_multiplier: 1.5, decay_step: 1 }],
        states: { total: 1, rows: [{ user_id: 12, email: 'peak@example.com', multiplier: 1.5, rate_bps: 20000000, high: 5, burst: 0, state: 'stacked', sampled_at: 1700000000, computed_at: 1700000000 }] },
        nodes: [{ type: 'vmess', id: 7, name: 'vmess-a' }]
    }
};

function harness() {
    const requests = [];
    class Component {
        constructor(props) { this.props = props; this.state = {}; }
        setState(update, callback) { this.state = Object.assign({}, this.state, typeof update === 'function' ? update(this.state) : update); if (callback) callback(); }
    }
    const passthrough = name => ({ a: name });
    const modules = {
        q1tI: { Component: Component, createElement: (type, props, ...children) => ({ type, props: props || {}, children }) },
        wCAj: passthrough('Table'),
        '2/Rp': passthrough('Button'),
        Bl7J: passthrough('Page'),
        v32e: passthrough('Spin'),
        Sdc0: passthrough('Switch')
    };
    function requireModule(id) { return modules[id] || { a: () => {} }; }
    requireModule.r = () => {};
    requireModule.n = value => { const getter = () => value; getter.a = value; return getter; };

    const context = vm.createContext({
        window: {
            settings: { secure_path: 'test-admin' },
            localStorage: { getItem: () => 'token' },
            confirm: () => true
        },
        fetch: (url, options) => {
            requests.push({ url, options, body: options && options.body ? JSON.parse(options.body) : undefined });
            return Promise.resolve({
                ok: true,
                text: () => Promise.resolve(JSON.stringify(FETCH_PAYLOAD))
            });
        }
    });
    const exports = {};
    vm.runInContext('(' + moduleSource('ratepage') + ')', context)({}, exports, requireModule);

    return { component: new exports.default({}), requests };
}

/** 把渲染出来的元素树摊平成文本。 */
function flatten(node) {
    if (node === null || node === undefined || node === false || node === true) return '';
    if (typeof node === 'string' || typeof node === 'number') return String(node);
    if (Array.isArray(node)) return node.map(flatten).join('');
    if (node.children) return node.children.map(flatten).join('');
    return '';
}

/** 桩组件不会渲染行，所以按 type 找出元素、直接检查传进去的 props。 */
function findAll(node, predicate, out) {
    out = out || [];
    if (!node || typeof node !== 'object') return out;
    if (Array.isArray(node)) { node.forEach(child => findAll(child, predicate, out)); return out; }
    if (predicate(node)) out.push(node);
    (node.children || []).forEach(child => findAll(child, predicate, out));
    return out;
}

test('the dynamic rate page module is present and renders before any data arrives', () => {
    const h = harness();
    const text = flatten(h.component.render());

    // 页面标题是传给 Page 的 prop，不在子节点里；正文里能抓到的是各区块标题
    assert.match(text, /时段倍率规则/);
    assert.match(text, /正在叠加的用户/);
    // 参数区块要有数据才渲染，所以这里不要求它出现
    assert.equal(h.requests.length, 0, '渲染本身不该发请求');
});

test('policy create and edit post a named independent policy with its revision', async () => {
    const h = harness(); h.component.load(); await new Promise(resolve => setImmediate(resolve));
    h.component.openPolicy(null); h.component.setPolicy('name', '普通线路');
    h.component.setPolicy('sustained_mbps', '20');
    await h.component.savePolicy();
    const created = h.requests.find(request => request.url.endsWith('/policy/save'));
    assert.equal(created.body.name, '普通线路'); assert.equal(created.body.sustained_mbps, 20);
    assert.equal(created.body.id, undefined);
    await new Promise(resolve => setImmediate(resolve));
    h.component.openPolicy(h.component.state.policies[0]); h.component.setPolicy('enabled', 0);
    await h.component.savePolicy();
    const edits = h.requests.filter(request => request.url.endsWith('/policy/save'));
    assert.equal(edits[1].body.id, 3); assert.equal(edits[1].body.revision, 2); assert.equal(edits[1].body.enabled, 0);
});

test('policy form rejects unnamed or invalid entries and bound policies cannot be deleted', async () => {
    const h = harness(); h.component.load(); await new Promise(resolve => setImmediate(resolve));
    h.component.openPolicy(null); await h.component.savePolicy();
    assert.match(h.component.state.error, /名称/);
    h.component.setPolicy('name', '场景'); h.component.setPolicy('stack_minutes', ''); await h.component.savePolicy();
    assert.match(h.component.state.error, /有效的策略参数/);
    h.component.dropPolicy({ id: 1, node_count: 2 });
    assert.match(h.component.state.error, /解除绑定/);
    assert.equal(h.requests.length, 1);
});

test('ledger separates subscriptions and scenes, and explanation sends the subscription identity', async () => {
    const h = harness(); h.component.load(); await new Promise(resolve => setImmediate(resolve));
    const row = { user_id: 12, node_user_id: 2000000012, policy_id: 3, subscription_id: 7, policy_name: '专线', email: 'peak@example.com' };
    h.component.setState({states: {total: 2, rows: [row, { ...row, policy_id: 4 }]}});
    const table = findAll(h.component.render(), node => node.type === 'Table').at(-1);
    assert.notEqual(table.props.rowKey(row), table.props.rowKey({ ...row, policy_id: 4 }));
    table.props.columns.find(column => column.key === 'action').render(null, row).props.onClick();
    assert.deepEqual(h.requests.at(-1).body, { user_id: 12, node_user_id: 2000000012 });
    h.component.setState({breakdown: {user_id: 12, subscription_id: 7, user_multiplier: 9, nodes: []}});
    const breakdown = findAll(h.component.renderBreakdown(), node => node.type === 'Table')[0];
    const multiplier = breakdown.props.columns.find(column => column.key === 'user');
    assert.equal(flatten(multiplier.render(null, {user_multiplier: 1.5})), '1.5 x');
});

test('updating the rate module preserves the external subscription module', () => {
    assert.match(moduleSource('externalpage'), /class External/);
    const source = fs.readFileSync(path.join(__dirname, '../admin-rate-module.js'), 'utf8');
    assert.equal(moduleSource('ratepage').trim(), source.trim().slice('ratepage: '.length));
});

test('load() pulls everything the page needs from one endpoint', async () => {
    const h = harness();
    h.component.load();
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(h.requests.length, 1);
    assert.match(h.requests[0].url, /\/api\/v1\/test-admin\/rate\/fetch\?only_stacked=1&keyword=&page=1&limit=50/);
    assert.equal(h.component.state.rules.length, 2);
    assert.equal(h.component.state.settings.stack_minutes, 5);
});

test('loaded data renders rules, settings and the live ledger', async () => {
    const h = harness();
    h.component.load();
    await new Promise(resolve => setImmediate(resolve));
    const text = flatten(h.component.render());

    assert.match(text, /时段倍率规则/);
    assert.match(text, /峰值判定参数/);
    assert.match(text, /正在叠加的用户/);

    const tables = findAll(h.component.render(), node => node.type === 'Table');
    assert.ok(tables.length >= 2, '至少要有规则表和实时状态表');
    const cellText = (table, record) => table.props.columns.map(column => flatten(column.render(null, record))).join(' | ');

    const rulesTable = tables[0];
    assert.equal(rulesTable.props.dataSource.length, 2);
    assert.match(cellText(rulesTable, rulesTable.props.dataSource[0]), /晚高峰/);
    assert.match(cellText(rulesTable, rulesTable.props.dataSource[0]), /20:00 - 23:00/, '分钟数要显示成时间');
    assert.match(cellText(rulesTable, rulesTable.props.dataSource[0]), /1\.5 x/, '倍率按 x 显示');
    assert.match(cellText(rulesTable, rulesTable.props.dataSource[1]), /vmess #7/, '节点作用域要能看出是哪个节点');
    assert.match(cellText(rulesTable, rulesTable.props.dataSource[1]), /每天/, 'weekdays 为空按每天显示');

    const statesTable = tables[tables.length - 1];
    assert.equal(statesTable.props.dataSource[0].email, 'peak@example.com');
    assert.match(cellText(statesTable, statesTable.props.dataSource[0]), /20\.00 Mbps/);
    assert.match(cellText(statesTable, statesTable.props.dataSource[0]), /已叠加/);
});

test('saving a rule posts the minutes the backend expects', async () => {
    const h = harness();
    h.component.openForm(null);
    h.component.setForm('scope', 'node');
    h.component.setForm('node_type', 'vmess');
    h.component.setForm('node_id', 7);
    h.component.setForm('start', '20:30');
    h.component.setForm('end', '23:30');
    h.component.setForm('multiplier', '1.8');
    h.component.submitForm();
    await new Promise(resolve => setImmediate(resolve));

    // 保存成功后页面会重新拉一次列表，所以是 POST + fetch 两个请求
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[0].url, '/api/v1/test-admin/rate/rule/save');
    assert.deepEqual(h.requests[0].body, {
        scope: 'node', node_type: 'vmess', node_id: 7, weekdays: [1, 2, 3, 4, 5, 6, 7],
        start_minute: 1230, end_minute: 1410, multiplier: 1.8, enabled: 1, remark: ''
    });
});

test('node scope without a node is refused before any request', () => {
    const h = harness();
    h.component.openForm(null);
    h.component.setForm('scope', 'node');
    h.component.submitForm();

    assert.equal(h.requests.length, 0);
    assert.match(h.component.state.error, /必须选一个节点/);
});

test('saving settings posts the six knobs and the switch', async () => {
    const h = harness();
    h.component.load();
    await new Promise(resolve => setImmediate(resolve));
    h.component.setSetting('instant_mbps', '80');
    h.component.setSetting('enabled', 0);
    h.component.saveSettings();
    await new Promise(resolve => setImmediate(resolve));

    const call = h.requests[h.requests.length - 1];
    assert.equal(call.url, '/api/v1/test-admin/rate/settings/save');
    assert.deepEqual(call.body, {
        enabled: 0, instant_mbps: 80, sustained_mbps: 10, burst_exempt_minutes: 3,
        stack_minutes: 5, stack_multiplier: 1.5, decay_step: 1
    });
});
