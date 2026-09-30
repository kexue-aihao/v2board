const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

// Exercise the shipped component AND its HTTP client; mocking the client
// would hide serialization and Content-Type parsing regressions.
const bundle = fs.readFileSync(path.join(__dirname, '../../public/assets/admin/umi.js'), 'utf8');
function moduleSource(name, next) {
    const start = bundle.indexOf('    ' + name + ': function(e, t, n) {');
    const end = bundle.indexOf('    ' + next + ': function(e, t, n) {', start);
    assert.ok(start >= 0 && end > start);
    return bundle.slice(start, end).trim().slice(name.length + 2).replace(/,$/, '');
}
function asyncToGenerator(fn) {
    return function (...args) {
        const iterator = fn.apply(this, args);
        return new Promise((resolve, reject) => {
            function step(method, value) {
                let result;
                try { result = iterator[method](value); } catch (error) { reject(error); return; }
                if (result.done) resolve(result.value);
                else Promise.resolve(result.value).then(value => step('next', value), error => step('throw', error));
            }
            step('next');
        });
    };
}

/** 三种节点：两个不同类型的同 id 节点（回归 rowKey 串行）、一个 v2node。 */
const NODES = [
    { type: 'v2node', id: 1, name: 'reality-a', show: 1 },
    { type: 'vmess', id: 1, name: 'vmess-a', show: 1 },
    { type: 'trojan', id: 7, name: 'trojan-a', show: 1 },
];

function harness({ responses = [], servers = NODES, contentType = 'application/json; charset=utf-8' } = {}) {
    const requests = [], messages = [], actions = [];
    class Component {
        constructor(props) { this.props = props; }
        setState(update, callback) { this.state = Object.assign({}, this.state, update); if (callback) callback(); }
    }
    const modules = {
        q1tI: { Component, createElement: (type, props, ...children) => ({ type, props: props || {}, children }) },
        '/MKj': { c: () => ComponentClass => ComponentClass },
        yWgo: { e: () => null, c: () => 'test-auth', g: () => {} },
        p0pE: Object.assign,
        '1l/V': asyncToGenerator,
        '20nU': { a: { serviceHost: '/api/v1' } },
        TeRw: { a: { error: value => messages.push({ kind: 'error', text: value.description }) } },
        Hg0r: { b: async (url, options) => {
            requests.push({ url, options, body: options.body ? JSON.parse(options.body) : undefined });
            const response = responses.shift();
            if (response instanceof Error) throw response;
            assert.ok(response, 'unexpected request: ' + url);
            return new Response(JSON.stringify(response.body), { status: response.status || 200, headers: { 'Content-Type': response.contentType || contentType } });
        } },
        tsqr: { a: Object.fromEntries(['success', 'warning', 'error'].map(kind => [kind, text => messages.push({ kind, text })])) },
    };
    function requireModule(id) { return modules[id] || { a: () => {} }; }
    requireModule.r = () => {};
    requireModule.d = (target, name, get) => Object.defineProperty(target, name, { get });
    requireModule.n = value => { const getter = () => value; getter.a = value; return getter; };
    const context = vm.createContext({ window: {
        settings: { secure_path: 'test-admin' }, location: { origin: '', pathname: '' },
        prompt: () => assert.fail('native prompts must not be used'),
        confirm: () => assert.fail('confirmation must use the explicit panel button'),
    } });
    const httpClient = {};
    vm.runInContext('(' + moduleSource('t3Un', 't9FE') + ')', context)({}, httpClient, requireModule);
    modules.t3Un = httpClient;
    const exports = {};
    vm.runInContext('(' + moduleSource('uzXD', 'v32e') + ')', context)({}, exports, requireModule);
    const component = new exports.default({
        dispatch: action => actions.push(action),
        serverManage: { servers, fetchLoading: false, sortMode: false },
    });
    return { component, requests, messages, actions, servers };
}

function seeded(options, keys = ['v2node:1', 'vmess:1']) {
    const h = harness(options);
    h.component.changeBatchSelection(keys);
    return h;
}

const copied = (nodes = [{ source_id: 1, id: 11, type: 'v2node', name: 'reality-a' }, { source_id: 1, id: 12, type: 'vmess', name: 'vmess-a' }]) =>
    ({ body: { data: { requested_count: nodes.length, created_count: nodes.length, nodes } } });
const listing = nodes => ({ body: { data: nodes } });
const copiedNodes = [
    ...NODES,
    { type: 'v2node', id: 11, name: 'reality-a', show: 0 },
    { type: 'vmess', id: 12, name: 'vmess-a', show: 0 },
];
const tlsPreview = (overrides = {}) => ({ body: { data: Object.assign({
    server_name: 'new-sni.example', dest: 'new-dest.example', matched_count: 2, changed_count: 2,
    nodes: [
        { id: 1, type: 'v2node', name: 'reality-a', server_name: 'old-sni', dest: 'old-dest', new_server_name: 'new-sni.example', new_dest: 'new-dest.example', server_name_applicable: true, dest_applicable: true, changes: ['server_name', 'dest'] },
        { id: 1, type: 'vmess', name: 'vmess-a', server_name: 'old-sni', dest: null, new_server_name: 'new-sni.example', new_dest: null, server_name_applicable: true, dest_applicable: false, changes: ['server_name'] },
    ],
}, overrides) } });
const tlsApplied = () => ({ body: { data: { requested_count: 2, updated_count: 2, nodes: [
    { id: 1, type: 'v2node', name: 'reality-a', server_name: 'new-sni.example', dest: 'new-dest.example', changes: ['server_name', 'dest'] },
    { id: 1, type: 'vmess', name: 'vmess-a', server_name: 'new-sni.example', dest: null, changes: ['server_name'] },
] } } });
const successful = h => h.messages.some(message => message.kind === 'success');
// 组件跑在 vm 沙箱里，它产出的数组/对象与 Node realm 原型不同，deepStrictEqual 会误判。
const plain = value => JSON.parse(JSON.stringify(value));

test('row keys stay unique across node types that share an id', () => {
    const h = harness({});
    assert.notEqual(h.component.batchKey({ type: 'vmess', id: 1 }), h.component.batchKey({ type: 'v2node', id: 1 }));
    assert.equal(h.component.batchKey({ type: 'vmess', id: 1 }), 'vmess:1');
    // 服务端按 type:id 定位节点，重复 id 不能被折叠掉
    h.component.changeBatchSelection(['vmess:1', 'v2node:1']);
    assert.deepEqual(plain(h.component.selectedBatchNodes()).map(node => node.type + ':' + node.id), ['v2node:1', 'vmess:1']);
});

test('batch copy sends the selection, defaults to regenerating REALITY keys', async () => {
    const h = seeded({ responses: [copied(), listing(copiedNodes)] });
    await h.component.runBatchCopy();
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[0].url, '/api/v1/test-admin/server/manage/nodes/copy');
    assert.equal(h.requests[0].options.headers.authorization, 'test-auth');
    assert.equal(h.requests[0].options.headers['Content-Type'], 'application/json');
    assert.deepEqual(h.requests[0].body, {
        nodes: [{ type: 'v2node', id: 1, name: 'reality-a' }, { type: 'vmess', id: 1, name: 'vmess-a' }],
        regenerate_reality_keys: true,
        confirm: true,
    });
    assert.match(h.requests[1].url, /\/server\/manage\/getNodes\?_batch_copy=\d+/);
    assert.equal(h.actions[0].type, 'serverManage/setState');
    assert.equal(h.actions[0].payload.servers.length, copiedNodes.length);
    assert.equal(h.component.state.batchLoading, false);
    assert.equal(h.component.state.batchError, '');
    assert.equal(h.component.state.batchSelection.length, 0);
    assert.equal(successful(h), true);
});

test('turning off regeneration is passed through and never guessed', async () => {
    const h = seeded({ responses: [copied(), listing(copiedNodes)] });
    h.component.changeBatchField('batchCopyRegenerate', false);
    await h.component.runBatchCopy();
    assert.equal(h.requests[0].body.regenerate_reality_keys, false);
});

test('copies that come back visible are reported instead of claiming success', async () => {
    // 副本必须落库为隐藏；列表里若是 show=1 说明落库没生效或读到的是旧列表
    const visible = copiedNodes.map(node => Object.assign({}, node, { show: 1 }));
    const h = seeded({ responses: [copied(), listing(visible)] });
    await h.component.runBatchCopy();
    assert.match(h.component.state.batchError, /与复制结果不一致/);
    assert.equal(successful(h), false);
});

test('copy without a selection makes no request', async () => {
    const h = harness({ responses: [] });
    h.component.openBatchDialog('copy');
    await h.component.runBatchCopy();
    assert.equal(h.requests.length, 0);
    assert.match(h.component.state.batchError, /勾选/);
    assert.equal(successful(h), false);
});

test('double clicks issue only one copy request', async () => {
    const h = seeded({ responses: [copied(), listing(copiedNodes)] });
    await Promise.all([h.component.runBatchCopy(), h.component.runBatchCopy()]);
    assert.equal(h.requests.length, 2);
});

test('failed refresh keeps the saved warning visible and prevents another write', async () => {
    const h = seeded({ responses: [copied(), new Error('offline')] });
    await h.component.runBatchCopy();
    await h.component.runBatchCopy();
    assert.equal(h.requests.length, 2);
    assert.match(h.component.state.batchError, /已返回复制成功.*勿重复提交/);
    assert.equal(successful(h), false);
});

test('batch tls fill previews then applies with confirmation', async () => {
    const h = seeded({ responses: [tlsPreview(), tlsApplied(), listing(copiedNodes)] });
    h.component.changeBatchField('batchServerName', 'new-sni.example');
    h.component.changeBatchField('batchDest', 'new-dest.example');
    await h.component.previewBatchTls();
    assert.equal(h.requests.length, 1, 'preview must not submit an update');
    assert.equal(successful(h), false);
    assert.deepEqual(h.requests[0].body, {
        nodes: [{ type: 'v2node', id: 1, name: 'reality-a' }, { type: 'vmess', id: 1, name: 'vmess-a' }],
        server_name: 'new-sni.example',
        dest: 'new-dest.example',
    });

    await h.component.applyBatchTls();
    assert.equal(h.requests.length, 3);
    assert.equal(h.requests[1].url, '/api/v1/test-admin/server/manage/tls-fields/apply');
    assert.deepEqual(h.requests[1].body, {
        nodes: [{ type: 'v2node', id: 1, name: 'reality-a' }, { type: 'vmess', id: 1, name: 'vmess-a' }],
        server_name: 'new-sni.example',
        dest: 'new-dest.example',
        confirm: true,
    });
    assert.match(h.requests[2].url, /\/getNodes\?_batch_tls=\d+/);
    assert.equal(h.component.state.batchPreview, null);
    assert.equal(successful(h), true);
});

test('a blank field is sent as an empty string so the server leaves it alone', async () => {
    const h = seeded({ responses: [tlsPreview({ dest: null, changed_count: 1 }), tlsApplied(), listing(copiedNodes)] });
    h.component.changeBatchField('batchServerName', 'new-sni.example');
    await h.component.previewBatchTls();
    await h.component.applyBatchTls();
    assert.equal(h.requests[0].body.dest, '');
    assert.equal(h.requests[0].body.server_name, 'new-sni.example');
    // 空串必须原样透传，服务端据此判断「这一项不动」
    assert.equal(h.requests[1].body.dest, '');
});

test('both fields blank is rejected before any request', async () => {
    const h = seeded({ responses: [] });
    await h.component.previewBatchTls();
    assert.equal(h.requests.length, 0);
    assert.match(h.component.state.batchError, /至少填写/);
});

test('editing an input invalidates the preview', async () => {
    const h = seeded({ responses: [tlsPreview()] });
    h.component.changeBatchField('batchServerName', 'new-sni.example');
    await h.component.previewBatchTls();
    h.component.changeBatchField('batchServerName', 'other.example');
    await h.component.applyBatchTls();
    assert.equal(h.requests.length, 1);
    assert.equal(h.component.state.batchPreview, null);
});

test('a preview with nothing to change never shows success', async () => {
    const h = seeded({ responses: [tlsPreview({ changed_count: 0 })] });
    h.component.changeBatchField('batchServerName', 'new-sni.example');
    await h.component.previewBatchTls();
    await h.component.applyBatchTls();
    assert.equal(h.requests.length, 1);
    assert.match(h.component.state.batchResult, /未执行任何修改/);
    assert.equal(successful(h), false);
});

test('closing the dialog makes no write request', async () => {
    const h = seeded({ responses: [tlsPreview()] });
    h.component.changeBatchField('batchServerName', 'new-sni.example');
    h.component.openBatchDialog('tls');
    await h.component.previewBatchTls();
    h.component.closeBatchDialog();
    assert.equal(h.requests.length, 1);
    assert.equal(h.component.state.batchDialog, '');
});

test('failed apply disables stale confirmation', async () => {
    const h = seeded({ responses: [tlsPreview(), { status: 500, body: { message: 'save failed' } }] });
    h.component.changeBatchField('batchServerName', 'new-sni.example');
    await h.component.previewBatchTls();
    await h.component.applyBatchTls();
    await h.component.applyBatchTls();
    assert.equal(h.requests.length, 2);
    assert.match(h.component.state.batchError, /save failed/);
    assert.equal(h.component.state.batchPreview, null);
    assert.equal(successful(h), false);
});

test('network, validation and invalid payload errors stay visible', async () => {
    for (const response of [new Error('offline'), { status: 422, body: { message: 'invalid selection' } }, { body: {} }]) {
        const h = seeded({ responses: [response] });
        await h.component.runBatchCopy();
        assert.equal(h.requests.length, 1);
        assert.ok(h.component.state.batchError);
        assert.equal(h.component.state.batchLoading, false);
    }
});

test('selection resolves against the live node list and drops unknown keys', () => {
    const h = harness({});
    // 勾选后节点可能已被删除或列表已刷新，残留的键不能变成幽灵节点提交给服务端
    h.component.changeBatchSelection(['v2node:1', 'vmess:404', 'nonsense']);
    assert.deepEqual(
        plain(h.component.selectedBatchNodes()),
        [{ type: 'v2node', id: 1, name: 'reality-a' }]
    );
    assert.equal(h.requests.length, 0);
});

test('a failed copy cannot be resubmitted with the same selection', async () => {
    const h = seeded({ responses: [copied(), new Error('offline')] });
    await h.component.runBatchCopy();
    assert.equal(h.requests.length, 2);
    // 服务端已确认创建，第二次点击必须被拦住，否则会重复建节点
    await h.component.runBatchCopy();
    await h.component.runBatchCopy();
    assert.equal(h.requests.length, 2);
    assert.match(h.component.state.batchError, /已返回复制成功.*勿重复提交/);
    // 重开对话框后恢复可操作
    h.component.openBatchDialog('copy');
    assert.equal(h.component.state.batchCopyConfirmed, false);
});

test('both dialogs render without touching the network', () => {
    const h = seeded({ responses: [] });
    assert.equal(h.component.renderBatchOperations(), null);
    assert.equal(h.requests.length, 0);

    h.component.openBatchDialog('copy');
    const copyDialog = h.component.renderBatchOperations();
    assert.ok(copyDialog, 'copy dialog must render');
    // 勾选两个节点时对话框里应逐个列出，便于确认范围
    assert.equal(copyDialog.children[0].children.length >= 1, true);

    h.component.openBatchDialog('tls');
    const tlsDialog = h.component.renderBatchOperations();
    assert.ok(tlsDialog, 'tls dialog must render');

    h.component.closeBatchDialog();
    assert.equal(h.component.renderBatchOperations(), null);
    assert.equal(h.requests.length, 0, 'rendering must never issue requests');
});
