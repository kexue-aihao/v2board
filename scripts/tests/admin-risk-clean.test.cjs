const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

// 直接跑打进产物里的「订阅清洗网关」模块，而不是断言源码字符串：改名、权重列、待处理区块
// 任何一处被打回或补丁失配，这里都会红。
const bundle = fs.readFileSync(path.join(__dirname, '../../public/assets/admin/umi.js'), 'utf8');
const start = bundle.indexOf('    riskrulepage: function(e, t, n) {');
const end = bundle.indexOf('    risktracepage: function(e, t, n) {', start);
assert.ok(start >= 0 && end > start, 'risk rule module must exist');
const source = bundle.slice(start, end).trim().slice('riskrulepage: '.length).replace(/,$/, '');

// 请求助手会把 secure_path 前缀拼进 URL，断言只关心业务路径。
function endpoint(url) {
    return String(url).replace('^/test-admin'.replace('^', ''), '') || url;
}

function harness(respond) {
    const requests = [], warnings = [];
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
    const passthrough = name => ({ [name]: () => ({}) });
    const modules = {
        q1tI: {
            Component,
            createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
            Fragment: 'Fragment',
        },
        jehZ: Object.assign,
        p0pE: Object.assign,
        g9YV: {},
        wCAj: { a: 'Table' },
        '+L6B': {},
        '2/Rp': { a: 'Button' },
        '5NDa': {},
        '5rEg': { a: 'Input' },
        Pwec: {},
        CtXQ: { a: 'Icon' },
        '2qtc': {},
        kLXV: { a: { warning: options => warnings.push(options), info: () => {} } },
        OaEy: {},
        '2fM7': { a: Object.assign(function Select() {}, { Option: 'Option' }) },
        BoS7: {},
        Sdc0: { a: 'Switch' },
        '/zsF': {},
        PArb: { a: 'Divider' },
        Bl7J: { a: 'Page' },
        v32e: { a: 'Card' },
        ...passthrough('unused'),
        t3Un: {
            a: async (url, params) => {
                requests.push({ method: 'GET', url: endpoint(url), params });
                return respond(endpoint(url), params);
            },
            b: async (url, params) => {
                requests.push({ method: 'POST', url: endpoint(url), params });
                return respond(endpoint(url), params);
            },
        },
    };
    function requireModule(id) {
        return modules[id] || { a: () => {} };
    }
    requireModule.r = () => {};
    requireModule.n = value => {
        const getter = () => value;
        getter.a = value;
        return getter;
    };
    const context = vm.createContext({ window: { settings: { secure_path: 'test-admin' } } });
    const exports = {};
    vm.runInContext('(' + source + ')', context)({}, exports, requireModule);
    return { Page: exports.default, requests, warnings };
}

function nodes(tree) {
    if (Array.isArray(tree)) return tree.flatMap(nodes);
    if (!tree || typeof tree !== 'object') return [];
    return [tree, ...(tree.children || []).flatMap(nodes)];
}
function text(tree) {
    if (Array.isArray(tree)) return tree.map(text).join('');
    if (typeof tree === 'string' || typeof tree === 'number') return String(tree);
    return (tree && (tree.children || []).map(text).join('')) || '';
}
function tables(tree) {
    return nodes(tree).filter(node => Array.isArray(node.props.columns) && Array.isArray(node.props.dataSource));
}

const rules = [
    { id: 1, label: '订阅 UA 种类过多', dimension: 'user_agent_count', operator: '>', threshold: '3.00000000', weight: 30, enabled: 1, sort: 1 },
];
const pendingFixture = [{
    id: 9, user_id: 17, email: 'member@example.test', subscription_id: 5,
    risk_score: 75, source: 'cycle', window_start: 1780000000, window_end: 1782500000,
    reasons: ['命中清洗策略「订阅 UA 种类过多」：订阅 UA 种类数 4 大于 3'], sent_at: 1780003600, handled_at: null,
}];

function boot(overrides = {}) {
    const h = harness(url => {
        if (url === '/risk/rule/fetch') {
            return { code: 200, data: rules, dimensions: { user_agent_count: { label: '订阅 UA 种类数', unit: '种' } }, operators: { '>': '大于' }, available: true };
        }
        if (url === '/risk/rule/high-risk') {
            return { code: 200, data: pendingFixture, total: 1, available: true, threshold: 60, ...overrides };
        }
        return { code: 200, data: true };
    });
    const page = new h.Page({ dispatch: () => {} });
    return { page, harness: h };
}

test('页面改名为订阅清洗网关，规则表带权重列', () => {
    const { page } = boot();
    page.state.rules = rules;
    page.state.dimensions = { user_agent_count: { label: '订阅 UA 种类数', unit: '种' } };
    page.state.operators = { '>': '大于' };
    page.state.available = true;
    page.state.fetchLoading = false;
    page.state.pendingLoading = false;

    const tree = page.render();
    const pageNode = nodes(tree).find(node => node.type === 'Page');
    assert.ok(pageNode, 'page wrapper must render');
    assert.equal(pageNode.props.title, '订阅清洗网关');

    const ruleTable = tables(tree).find(table => table.props.dataSource === page.state.rules);
    assert.ok(ruleTable, 'rule table must render');
    const weightColumn = ruleTable.props.columns.find(column => column.key === 'weight');
    assert.ok(weightColumn, 'rule table must expose a weight column');
    assert.equal(text(weightColumn.render(30)), '30%');
    assert.equal(text(weightColumn.render(null)), '20%', '缺列（未升级的库）按默认 20% 展示');
});

test('待处理区块读取提醒台账，标记已处理会回写并刷新', async () => {
    const { page, harness } = boot();
    await page.fetchPending();
    assert.equal(harness.requests[0].url, '/risk/rule/high-risk');
    assert.equal(page.state.pendingTotal, 1);
    assert.equal(page.state.pendingThreshold, 60);
    assert.equal(page.state.pending[0].risk_score, 75);

    page.state.pendingLoading = false;
    const tree = page.render();
    const pendingTable = tables(tree).find(table => table.props.dataSource === page.state.pending);
    assert.ok(pendingTable, 'pending block must render its own table');
    const scoreCell = pendingTable.props.columns.find(column => column.key === 'risk_score');
    assert.equal(text(scoreCell.render(null, pendingFixture[0])), '75%');
    const actions = pendingTable.props.columns.find(column => column.key === 'action');

    const before = harness.requests.length;
    actions.render(null, pendingFixture[0]).props.onClick();
    await new Promise(resolve => setImmediate(resolve));
    const posted = harness.requests[before];
    assert.equal(posted.method, 'POST');
    assert.equal(posted.url, '/risk/rule/high-risk/handle');
    assert.equal(posted.params.id, 9, '跨 vm 边界的对象不能做 deepStrictEqual（原型不同）');
    assert.equal(harness.requests[harness.requests.length - 1].url, '/risk/rule/high-risk', '处理后要刷新待办');
});

test('没有管理员绑定 Telegram 时页面给出可见提示', async () => {
    const { page } = boot({ notifiable_admins: 0 });
    await page.fetchPending();
    page.state.pendingLoading = false;
    assert.ok(text(page.render()).includes('没有管理员绑定 Telegram'), '待处理区块必须提示提醒发不出去');

    const covered = boot({ notifiable_admins: 2 });
    await covered.page.fetchPending();
    covered.page.state.pendingLoading = false;
    assert.equal(text(covered.page.render()).includes('没有管理员绑定 Telegram'), false);
});

test('保存规则会带上权重，非法权重被拦下', async () => {
    const { page, harness } = boot();
    page.state.submit = { label: '跨省请求过多', dimension: 'region_count', operator: '>=', threshold: '3', weight: '35', enabled: true };
    page.save();
    await new Promise(resolve => setImmediate(resolve));
    const saved = harness.requests.find(request => request.url === '/risk/rule/save');
    assert.ok(saved, 'save must call the rule endpoint');
    assert.equal(saved.params.weight, 35);

    const invalid = boot();
    invalid.page.state.submit = { label: 'x', dimension: 'city_count', operator: '>=', threshold: '3', weight: '101', enabled: true };
    invalid.page.save();
    assert.equal(invalid.harness.requests.some(request => request.url === '/risk/rule/save'), false);
    assert.ok(invalid.harness.warnings.some(warning => String(warning.content).includes('权重')));
});
