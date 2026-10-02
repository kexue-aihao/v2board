const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

// 「外部订阅源」页跑在产物里的整段模块上，所以从 umi.js 里抠出来跑：模块里任何一个拼错的
// 模块 id、或 render 里引用了不存在的字段，都会在这里炸，而不是等运维点开菜单才发现。
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

const SOURCES = [
    { id: 1, name: '机场 1', url: 'https://sub.example/a', group_id: 2, enabled: 1, remark: '主要备用', last_fetch_at: 1700000000, last_status: 'ok', last_error: null, node_count: 12 },
    { id: 2, name: '机场 2', url: 'https://sub.example/b', group_id: 3, enabled: 0, remark: '', last_fetch_at: 1700000000, last_status: 'error', last_error: '源返回 HTTP 500', node_count: 0 }
];
const GROUPS = [{ id: 2, name: '过渡节点' }, { id: 3, name: '高级套餐' }];

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
            return Promise.resolve({ ok: true, text: () => Promise.resolve(JSON.stringify({ data: { sources: SOURCES, groups: GROUPS, nodes: [] } })) });
        }
    });
    const exports = {};
    vm.runInContext('(' + moduleSource('externalpage') + ')', context)({}, exports, requireModule);

    return { component: new exports.default({}), requests };
}

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

test('the external source page renders before any data arrives', () => {
    const h = harness();
    const text = flatten(h.component.render());

    assert.match(text, /外部订阅源/);
    assert.match(text, /节点预览/);
    assert.equal(h.requests.length, 0, '渲染本身不该发请求');
});

test('load pulls sources and the group list', async () => {
    const h = harness();
    h.component.load();
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(h.requests.length, 1);
    assert.match(h.requests[0].url, /\/api\/v1\/test-admin\/external\/fetch\?source_id=0/);
    assert.equal(h.component.state.sources.length, 2);
    assert.equal(h.component.state.groups.length, 2);
});

test('the source table shows the group, the node count and the last error', () => {
    const h = harness();
    h.component.state.sources = SOURCES;
    h.component.state.groups = GROUPS;
    const tables = findAll(h.component.render(), node => node.type === 'Table');
    const columns = tables[0].props.columns;
    const cellText = record => columns.map(column => flatten(column.render(null, record))).join(' | ');

    assert.match(cellText(SOURCES[0]), /机场 1/);
    assert.match(cellText(SOURCES[0]), /过渡节点/, '归属权限组要显示名字而不是 id');
    assert.match(cellText(SOURCES[0]), /12/);
    assert.match(cellText(SOURCES[0]), /正常/);

    assert.match(cellText(SOURCES[1]), /抓取失败/);
    assert.match(cellText(SOURCES[1]), /高级套餐/);
});

test('saving a source posts the trimmed url and the group', async () => {
    const h = harness();
    h.component.openForm(null);
    h.component.setForm('name', '新机场');
    h.component.setForm('url', '  https://sub.example/c  ');
    h.component.setForm('group_id', 2);
    h.component.submitForm();
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(h.requests[0].url, '/api/v1/test-admin/external/source/save');
    assert.deepEqual(h.requests[0].body, { name: '新机场', url: 'https://sub.example/c', group_id: 2, enabled: 1, remark: '' });
});

test('a non-http url is refused before any request', () => {
    const h = harness();
    h.component.openForm(null);
    h.component.setForm('url', 'file:///etc/passwd');
    h.component.setForm('group_id', 2);
    h.component.submitForm();

    assert.equal(h.requests.length, 0);
    assert.match(h.component.state.error, /http/);
});

test('a source without a group is refused before any request', () => {
    const h = harness();
    h.component.openForm(null);
    h.component.setForm('url', 'https://sub.example/c');
    h.component.setForm('group_id', 0);
    h.component.submitForm();

    assert.equal(h.requests.length, 0);
    assert.match(h.component.state.error, /权限组/);
});

test('refreshing one source reports the parsed node count and reloads the list', async () => {
    const h = harness();
    h.component.refreshSource(SOURCES[0]);
    await new Promise(resolve => setImmediate(resolve));

    assert.equal(h.requests[0].url, '/api/v1/test-admin/external/source/refresh');
    assert.deepEqual(h.requests[0].body, { id: 1 });
    // 刷新之后要重新拉列表，否则状态列还是旧的
    assert.match(h.requests[1].url, /\/external\/fetch/);
});

test('previewing a source asks for its nodes', async () => {
    const h = harness();
    h.component.load(SOURCES[1].id);
    await new Promise(resolve => setImmediate(resolve));

    assert.match(h.requests[0].url, /source_id=2/);
    assert.equal(h.component.state.previewSource, 2);
});
