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

function harness({ responses = [], servers = NODES, contentType = 'application/json; charset=utf-8', mobile = false, clipboardAvailable = true, clipboardError = false } = {}) {
    const requests = [], messages = [], actions = [], clipboardWrites = [];
    class Component {
        constructor(props) { this.props = props; }
        setState(update, callback) { this.state = Object.assign({}, this.state, update); if (callback) callback(); }
    }
    const modules = {
        q1tI: { Component, createElement: (type, props, ...children) => ({ type, props: props || {}, children }) },
        '/MKj': { c: () => ComponentClass => ComponentClass },
        yWgo: { e: () => null, c: () => 'test-auth', g: () => {}, f: () => mobile },
        p0pE: Object.assign,
        jehZ: Object.assign,
        '1l/V': asyncToGenerator,
        '20nU': { a: { serviceHost: '/api/v1' } },
        TeRw: { a: { error: value => messages.push({ kind: 'error', text: value.description }) } },
        Hg0r: { b: async (url, options) => {
            requests.push({ url, options, body: options.body ? JSON.parse(options.body) : undefined });
            let response = responses.shift();
            if (typeof response === 'function') response = await response();
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
    let copyInput;
    const context = vm.createContext({ navigator: { clipboard: clipboardAvailable ? { writeText: async text => {
        if (clipboardError) throw new Error('clipboard denied');
        clipboardWrites.push(text);
    } } : undefined }, document: {
        createElement: () => ({ style: {}, select() {} }),
        body: { appendChild: input => { copyInput = input; }, removeChild: () => { copyInput = undefined; } },
        execCommand: command => { assert.equal(command, 'copy'); clipboardWrites.push(copyInput.value); return true; },
    }, window: {
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
        serverGroup: { groups: [] },
    });
    return { component, requests, messages, actions, servers, clipboardWrites };
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
const renamePreview = (overrides = {}) => ({ body: { data: Object.assign({
    prefix: 'Hong Kong', suffix: 'v1', separator: ' | ', start_number: 1, number_width: 1,
    matched_count: 2, changed_count: 2,
    nodes: [
        { id: 1, type: 'v2node', name: 'reality-a', new_name: 'Hong Kong | 1 | v1', number: 1, changed: true },
        { id: 1, type: 'vmess', name: 'vmess-a', new_name: 'Hong Kong | 2 | v1', number: 2, changed: true },
    ],
}, overrides) } });
const renameApplied = () => ({ body: { data: {
    prefix: 'Hong Kong', suffix: 'v1', separator: ' | ', start_number: 1, number_width: 1,
    requested_count: 2, matched_count: 2, updated_count: 2,
    nodes: [
        { id: 1, type: 'v2node', old_name: 'reality-a', name: 'Hong Kong | 1 | v1' },
        { id: 1, type: 'vmess', old_name: 'vmess-a', name: 'Hong Kong | 2 | v1' },
    ],
} } });
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

test('batch rename previews and applies the exact formatted names', async () => {
    const h = seeded({ responses: [renamePreview(), renameApplied(), listing([
        { type: 'v2node', id: 1, name: 'Hong Kong | 1 | v1', show: 1 },
        { type: 'vmess', id: 1, name: 'Hong Kong | 2 | v1', show: 1 },
        NODES[2],
    ])] });
    h.component.changeBatchField('batchRenamePrefix', 'Hong Kong');
    h.component.changeBatchField('batchRenameSuffix', 'v1');
    h.component.changeBatchField('batchRenameSeparator', ' | ');
    h.component.openBatchDialog('rename');
    await h.component.previewBatchRename();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].url, '/api/v1/test-admin/server/manage/rename/preview');
    assert.deepEqual(h.requests[0].body, {
        nodes: [{ type: 'v2node', id: 1, name: 'reality-a' }, { type: 'vmess', id: 1, name: 'vmess-a' }],
        prefix: 'Hong Kong', suffix: 'v1', separator: ' | ', start_number: 1, number_width: 1,
    });
    await h.component.applyBatchRename();
    assert.equal(h.requests.length, 3);
    assert.equal(h.requests[1].url, '/api/v1/test-admin/server/manage/rename/apply');
    assert.equal(h.requests[1].body.confirm, true);
    assert.equal(h.requests[1].body.nodes[0].name, 'reality-a');
    assert.match(h.requests[2].url, /\/getNodes\?_batch_rename=\d+/);
    assert.equal(h.component.state.batchPreview, null);
    assert.equal(successful(h), true);
});

test('editing the rename format invalidates a preview without writing', async () => {
    const h = seeded({ responses: [renamePreview()] });
    h.component.openBatchDialog('rename');
    await h.component.previewBatchRename();
    h.component.changeBatchField('batchRenameSuffix', 'v2');
    await h.component.applyBatchRename();
    assert.equal(h.requests.length, 1);
    assert.equal(h.component.state.batchPreview, null);
    assert.equal(h.component.state.batchError, '');
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

    h.component.openBatchDialog('rename');
    const renameDialog = h.component.renderBatchOperations();
    assert.ok(renameDialog, 'rename dialog must render');
    assert.equal(renameDialog.props.title, '批量重命名节点');

    h.component.closeBatchDialog();
    assert.equal(h.component.renderBatchOperations(), null);
    assert.equal(h.requests.length, 0, 'rendering must never issue requests');
});

// ---- 批量删除 与 批量下发协议配置 ----

const SELECTED = [{ type: 'v2node', id: 1, name: 'reality-a' }, { type: 'vmess', id: 1, name: 'vmess-a' }];
const deletion = (nodes = [
    { id: 1, type: 'v2node', name: 'reality-a', host: 'a.example', published: true, child_count: 2 },
    { id: 1, type: 'vmess', name: 'vmess-a', host: 'b.example', published: false, child_count: 0 },
]) => ({ body: { data: { requested_count: nodes.length, deleted_count: nodes.length, nodes } } });

const protocolPreview = (overrides = {}) => ({ body: { data: Object.assign({
    network: 'ws', network_settings: '{"path":"/ws"}', matched_count: 2, applicable_count: 1, changed_count: 1,
    nodes: [
        { id: 1, type: 'v2node', name: 'reality-a', applicable: true, network: 'tcp', network_settings: '', new_network: 'ws', new_network_settings: '{"path":"/ws"}', changes: ['network', 'network_settings'] },
        { id: 1, type: 'vmess', name: 'vmess-a', applicable: false, network: null, network_settings: null, new_network: null, new_network_settings: null, changes: [] },
    ],
}, overrides) } });
const protocolApplied = (overrides = {}) => ({ body: { data: Object.assign({
    requested_count: 2, updated_count: 1,
    nodes: [{ id: 1, type: 'v2node', name: 'reality-a', network: 'ws', network_settings: '{"path":"/ws"}', changes: ['network', 'network_settings'] }],
}, overrides) } });

/** 把渲染出来的元素树摊平成文本，用来断言对话框里到底写了什么。 */
function flatten(node) {
    if (node === null || node === undefined || node === false || node === true) return '';
    if (typeof node === 'string' || typeof node === 'number') return String(node);
    if (Array.isArray(node)) return node.map(flatten).join('');
    if (node.children) return node.children.map(flatten).join('');
    return '';
}

test('batch delete sends the selection with confirmation, then verifies it is gone', async () => {
    const h = seeded({ responses: [deletion(), listing([NODES[2]])] });
    h.component.openBatchDialog('delete');
    await h.component.runBatchDelete();

    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[0].url, '/api/v1/test-admin/server/manage/nodes/delete');
    assert.deepEqual(h.requests[0].body, { nodes: SELECTED, confirm: true });
    assert.match(h.requests[1].url, /\/getNodes\?_batch_delete=\d+/);
    assert.deepEqual(plain(h.component.state.batchSelection), [], '删除后勾选要清空');
    assert.equal(successful(h), true);
});

test('batch delete double click issues only one delete request', async () => {
    const h = seeded({ responses: [deletion(), listing([NODES[2]])] });
    await Promise.all([h.component.runBatchDelete(), h.component.runBatchDelete()]);
    assert.equal(h.requests.filter(request => request.url.endsWith('/nodes/delete')).length, 1);
});

test('a delete whose refresh still lists the node is reported, not claimed as success', async () => {
    const h = seeded({ responses: [deletion(), listing(NODES)] });
    await h.component.runBatchDelete();
    assert.equal(successful(h), false);
    assert.match(h.component.state.batchError, /仍然存在/);
    // 服务端已经确认删除，重开对话框也不该再允许提交一次
    assert.equal(h.component.state.batchDeleteConfirmed, true);
    h.component.openBatchDialog('delete');
    assert.equal(h.component.state.batchDeleteConfirmed, false);
});

test('batch delete dialog lists children and published nodes before confirming', () => {
    const h = harness({ responses: [], servers: [
        { type: 'v2node', id: 1, name: 'reality-a', show: 1, host: 'a.example', port: 443, parent_id: null },
        { type: 'v2node', id: 2, name: 'child-a', show: 0, host: 'a.example', port: 8443, parent_id: 1 },
        { type: 'vmess', id: 1, name: 'vmess-a', show: 0, host: 'b.example', port: 80, parent_id: null },
    ] });
    h.component.changeBatchSelection(['v2node:1', 'vmess:1']);
    h.component.openBatchDialog('delete');
    const text = flatten(h.component.renderBatchOperations());

    assert.match(text, /还有子节点/, '有子节点时必须提示父子关系');
    assert.match(text, /v2node #1/);
    assert.match(text, /各发一条「节点下架」Telegram 通知/);
    assert.equal(h.requests.length, 0, '渲染对话框不该发请求');
});

test('batch protocol previews then applies the parsed JSON object', async () => {
    const h = seeded({ responses: [protocolPreview(), protocolApplied(), listing(NODES)] });
    h.component.changeBatchField('batchNetwork', 'ws');
    h.component.changeBatchField('batchNetworkSettings', '{ "path": "/ws" }');
    await h.component.previewBatchProtocol();

    assert.equal(h.requests.length, 1, 'preview must not submit an update');
    assert.equal(h.requests[0].url, '/api/v1/test-admin/server/manage/protocol/preview');
    assert.deepEqual(h.requests[0].body, { nodes: SELECTED, network: 'ws', network_settings: { path: '/ws' } });
    assert.equal(successful(h), false);

    await h.component.applyBatchProtocol();
    assert.equal(h.requests[1].url, '/api/v1/test-admin/server/manage/protocol/apply');
    assert.deepEqual(h.requests[1].body, { nodes: SELECTED, network: 'ws', network_settings: { path: '/ws' }, confirm: true });
    assert.match(h.requests[2].url, /\/getNodes\?_batch_protocol=\d+/);
    assert.equal(h.component.state.batchPreview, null);
    assert.equal(successful(h), true);
});

test('protocol payload omits the field the admin left blank', async () => {
    const h = seeded({ responses: [protocolPreview({ network_settings: null, changed_count: 1 }), protocolApplied(), listing(NODES)] });
    h.component.changeBatchField('batchNetwork', 'ws');
    await h.component.previewBatchProtocol();
    await h.component.applyBatchProtocol();
    assert.equal('network_settings' in h.requests[0].body, false, '留空的一项不能发出去');
    assert.equal('network_settings' in h.requests[1].body, false);
    assert.equal(h.requests[1].body.network, 'ws');
});

test('invalid JSON is rejected before any request', async () => {
    const h = seeded({ responses: [] });
    h.component.changeBatchField('batchNetwork', 'ws');
    h.component.changeBatchField('batchNetworkSettings', '{ not json');
    await h.component.previewBatchProtocol();
    assert.equal(h.requests.length, 0);
    assert.match(h.component.state.batchError, /不是合法的 JSON/);
});

test('a JSON array or scalar is rejected: the column stores an object', async () => {
    const h = seeded({ responses: [] });
    h.component.changeBatchField('batchNetworkSettings', '[1,2,3]');
    await h.component.previewBatchProtocol();
    assert.equal(h.requests.length, 0);
    assert.match(h.component.state.batchError, /必须是一个 JSON 对象/);
});

test('both fields blank is rejected before any request', async () => {
    const h = seeded({ responses: [] });
    await h.component.previewBatchProtocol();
    assert.equal(h.requests.length, 0);
    assert.match(h.component.state.batchError, /请至少/);
});

test('the template button fills a JSON body matching the chosen transport', () => {
    const h = seeded({ responses: [] });
    assert.equal(h.component.batchProtocolTemplate(''), '');
    assert.equal(h.component.batchProtocolTemplate('nope'), '');

    h.component.changeBatchField('batchNetwork', 'ws');
    h.component.changeBatchField('batchNetworkSettings', h.component.batchProtocolTemplate('ws'));
    const filled = JSON.parse(h.component.state.batchNetworkSettings);
    assert.equal(filled.path, '/');
    assert.equal(filled.headers.Host, 'xtls.github.io');
    assert.equal(JSON.parse(h.component.batchProtocolTemplate('grpc')).serviceName, 'GunService');
});

test('the protocol dialog marks types without those columns as 不适用', () => {
    const h = harness({ responses: [], servers: [
        { type: 'v2node', id: 1, name: 'reality-a', show: 1, network: 'tcp' },
        { type: 'tuic', id: 9, name: 'tuic-a', show: 1, network: 'tcp' },
    ] });
    h.component.changeBatchSelection(['v2node:1', 'tuic:9']);
    h.component.openBatchDialog('protocol');
    const text = flatten(h.component.renderBatchOperations());
    assert.match(text, /tuic #9/);
    assert.match(text, /没有这两列/);
    assert.equal(h.requests.length, 0);
});

const tlsInspection = (overrides = {}) => ({ body: { data: Object.assign({
    matched_count: 2,
    nodes: [
        { type: 'v2node', id: 1, name: 'reality-a', protocol: 'vless', tls_mode: 'reality', server_name: 'sni.example', dest: 'target.example', server_name_applicable: true, dest_applicable: true, server_name_conflict: false, server_name_values: [] },
        { type: 'vmess', id: 1, name: 'vmess-a', protocol: 'vmess', tls_mode: 'none', server_name: '', dest: null, server_name_applicable: true, dest_applicable: false, server_name_conflict: false, server_name_values: [] },
    ],
}, overrides) } });

function elements(tree) {
    if (!tree || typeof tree !== 'object') return [];
    if (Array.isArray(tree)) return tree.flatMap(elements);
    return [tree, ...elements(tree.children)];
}

function operationItems(h) {
    return elements(h.component.renderNodeOperations().props.overlay).filter(element => element.props.onClick);
}

function operation(h, label) {
    return operationItems(h).find(element => flatten(element.children) === label);
}

test('the search toolbar has one operations dropdown containing all node tools', () => {
    const h = seeded({});
    const toolbar = elements(h.component.render()).find(element => element.props.className === 'v2board-table-action');
    const searchIndex = toolbar.children.findIndex(element => element && element.props.placeholder === '输入任意关键字搜索');
    assert.ok(searchIndex >= 0);
    assert.equal(flatten(toolbar.children[searchIndex + 1]), '操作');
    assert.ok(toolbar.children[searchIndex + 1].props.overlay);
    assert.match(flatten(toolbar), /已选 2 个节点/);
    assert.doesNotMatch(flatten(toolbar), /批量|替换节点域名|查看 SNI/);
    assert.deepEqual(operationItems(h).map(item => flatten(item)), [
        '按 ID 范围选择', '清空选择', '替换节点域名', '批量复制', '批量重命名', '批量设置端口', '批量设置倍率',
        '批量填写 SNI/地址', '查看 SNI/地址', '批量下发协议配置', '批量删除',
    ]);
    assert.deepEqual(plain(h.component.renderNodeOperations().props.trigger), ['click']);
    assert.equal(h.requests.length, 0);
});

test('menu actions enforce selection and disable operations while sorting or loading', () => {
    const h = harness({});
    for (const item of operationItems(h)) {
        const label = flatten(item);
        assert.equal(item.props.disabled, !['按 ID 范围选择', '替换节点域名'].includes(label), label);
        if (item.props.disabled) item.props.onClick();
    }
    assert.equal(h.component.state.batchDialog, '');
    for (const change of [
        () => { h.component.props.serverManage.sortMode = true; },
        () => { h.component.props.serverManage.fetchLoading = true; },
        () => { h.component.state.batchLoading = true; },
        () => { h.component.state.hostReplaceLoading = true; },
    ]) {
        h.component.props.serverManage.sortMode = false;
        h.component.props.serverManage.fetchLoading = false;
        h.component.state.batchLoading = false;
        h.component.state.hostReplaceLoading = false;
        change();
        assert.equal(h.component.renderNodeOperations().props.disabled, true);
        assert.ok(operationItems(h).every(item => item.props.disabled));
    }
    assert.equal(h.requests.length, 0);
});

test('menu items open the matching panels and clearing selection invalidates old previews', () => {
    const h = seeded({});
    for (const [label, dialog] of [
        ['批量复制', 'copy'], ['批量重命名', 'rename'], ['批量设置端口', 'ports'], ['批量设置倍率', 'rate'],
        ['批量填写 SNI/地址', 'tls'], ['批量下发协议配置', 'protocol'], ['批量删除', 'delete'], ['按 ID 范围选择', 'select-range'],
    ]) {
        operation(h, label).props.onClick();
        assert.equal(h.component.state.batchDialog, dialog);
        h.component.closeBatchDialog();
    }
    operation(h, '替换节点域名').props.onClick();
    assert.equal(h.component.state.hostReplaceVisible, true);
    h.component.state.batchPreview = { stale: true };
    operation(h, '清空选择').props.onClick();
    assert.equal(h.component.state.batchSelection.length, 0);
    assert.equal(h.component.state.batchPreview, null);
    assert.equal(h.requests.length, 0);
});

function rangeHarness(options, input, type = 'all', mode = 'replace') {
    const h = seeded(options);
    operation(h, '按 ID 范围选择').props.onClick();
    h.component.changeBatchField('batchIdRange', input);
    h.component.changeBatchField('batchIdType', type);
    h.component.changeBatchField('batchIdSelectionMode', mode);
    return h;
}

test('ID ranges are inclusive, numeric, deduplicated, and cross pages without expanding missing IDs', () => {
    const servers = [...NODES, ...[2, 10, 11, 20].map(id => ({ type: 'v2node', id, name: 'node-' + id }))];
    const h = rangeHarness({ servers }, '1 - 10，7,9-11');
    h.component.state.pageSize = 1;
    const selection = h.component.batchRangeSelection();
    assert.equal(selection.error, '');
    assert.deepEqual(plain(selection.keys), ['v2node:1', 'vmess:1', 'trojan:7', 'v2node:2', 'v2node:10', 'v2node:11']);
    assert.match(flatten(h.component.renderBatchOperations()), /范围匹配 6 个/);
    h.component.applyBatchRangeSelection();
    assert.deepEqual(plain(h.component.state.batchSelection), plain(selection.keys));
    assert.equal(h.component.state.batchDialog, '');
    assert.equal(h.requests.length, 0);
});

test('ID selection can restrict node type even when IDs overlap', () => {
    const h = rangeHarness({}, '1-1', 'vmess');
    assert.deepEqual(plain(h.component.batchRangeSelection().keys), ['vmess:1']);
    h.component.applyBatchRangeSelection();
    assert.deepEqual(plain(h.component.selectedBatchNodes()), [{ type: 'vmess', id: 1, name: 'vmess-a' }]);
});

test('ID selection matches the current search results and preserves hidden selections only in append mode', () => {
    const h = rangeHarness({}, '1-10');
    h.component.state.searchKey = 'vmess-a';
    assert.deepEqual(plain(h.component.filteredServers()).map(node => node.type), ['vmess']);
    assert.deepEqual(plain(h.component.batchRangeSelection().keys), ['vmess:1']);
    h.component.changeBatchField('batchIdSelectionMode', 'append');
    h.component.changeBatchSelection(['v2node:1', 'vmess:1', 'missing:100']);
    assert.deepEqual(plain(h.component.batchRangeSelection().keys), ['v2node:1', 'vmess:1']);
    h.component.applyBatchRangeSelection();
    assert.deepEqual(plain(h.component.state.batchSelection), ['v2node:1', 'vmess:1']);
    assert.equal(h.requests.length, 0);
});

test('invalid or empty ranges never clear an existing selection', () => {
    for (const input of ['', ' ', '0', '-1', '01', '1.5', '1e2', '2-1', '1-', '1--2', '1,', '1,,2', 'all', '9007199254740992', '9'.repeat(1001), '300-400']) {
        const h = rangeHarness({}, input);
        assert.ok(h.component.batchRangeSelection().error, input);
        h.component.applyBatchRangeSelection();
        assert.deepEqual(plain(h.component.state.batchSelection), ['v2node:1', 'vmess:1'], input);
        assert.equal(h.component.state.batchDialog, 'select-range');
        assert.equal(h.requests.length, 0);
    }
});

test('range selection enforces the batch cap including retained selections', () => {
    const servers = Array.from({ length: 201 }, (_, index) => ({ type: 'vmess', id: index + 1, name: 'node' }));
    const h = rangeHarness({ servers }, '1-9007199254740991');
    assert.match(h.component.batchRangeSelection().error, /超过每批 200/);
    h.component.applyBatchRangeSelection();
    assert.deepEqual(plain(h.component.state.batchSelection), ['v2node:1', 'vmess:1']);
    h.component.changeBatchField('batchIdRange', '1-200');
    h.component.applyBatchRangeSelection();
    assert.equal(h.component.selectedBatchNodes().length, 200);
    h.component.openBatchDialog('select-range');
    h.component.changeBatchField('batchIdRange', '201');
    h.component.changeBatchField('batchIdSelectionMode', 'append');
    assert.match(h.component.batchRangeSelection().error, /201.*超过/);
    h.component.applyBatchRangeSelection();
    assert.equal(h.component.selectedBatchNodes().length, 200);
});

test('range selection uses the latest list and canceling makes no change', () => {
    const h = rangeHarness({}, '1-7');
    assert.equal(h.component.batchRangeSelection().nodes.length, 3);
    h.component.props.serverManage.servers = [NODES[1]];
    assert.equal(h.component.batchRangeSelection().nodes.length, 1);
    h.component.closeBatchDialog();
    assert.deepEqual(plain(h.component.state.batchSelection), ['v2node:1', 'vmess:1']);
    assert.equal(h.requests.length, 0);
});

test('ID selection works on mobile and feeds the existing batch port request', async () => {
    const h = rangeHarness({ mobile: true, responses: [portPreview()] }, '1');
    h.component.changeBatchSelection(['trojan:7']);
    h.component.applyBatchRangeSelection();
    operation(h, '批量设置端口').props.onClick();
    h.component.changeBatchField('batchServerPort', '8443');
    await h.component.previewBatchPorts();
    assert.deepEqual(h.requests[0].body.nodes, [
        { type: 'v2node', id: 1, name: 'reality-a' }, { type: 'vmess', id: 1, name: 'vmess-a' },
    ]);
});

test('the inspection menu item needs a selection outside sort mode on desktop and mobile', () => {
    const h = seeded({});
    const button = () => operation(h, '查看 SNI/地址');
    assert.equal(button().props.disabled, false);
    h.component.props.serverManage.sortMode = true;
    assert.equal(button().props.disabled, true);
    h.component.props.serverManage.sortMode = false;
    h.component.changeBatchSelection([]);
    assert.equal(button().props.disabled, true);
    const mobile = seeded({ mobile: true });
    assert.equal(operation(mobile, '查看 SNI/地址').props.disabled, false);
    assert.equal(h.requests.length, 0);
});

test('opening inspection reads immediately and sends only the selected node identities', async () => {
    const h = seeded({ responses: [tlsInspection()] });
    await h.component.openBatchTlsInspection();
    assert.equal(h.component.state.batchDialog, 'inspect');
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].url, '/api/v1/test-admin/server/manage/tls-fields/inspect');
    assert.equal(h.requests[0].options.headers.authorization, 'test-auth');
    assert.deepEqual(h.requests[0].body, { nodes: [
        { type: 'v2node', id: 1, name: 'reality-a' }, { type: 'vmess', id: 1, name: 'vmess-a' },
    ] });
    assert.equal(h.component.state.batchPreview.matched_count, 2);
    assert.equal(h.actions.length, 0);
    h.component.closeBatchDialog();
    assert.equal(h.component.state.batchPreview, null);
    assert.equal(h.requests.length, 1);
});

test('inspection refresh rereads saved values instead of reusing the node list', async () => {
    const updated = tlsInspection();
    updated.body.data.nodes[0].server_name = 'changed.example';
    const h = seeded({ responses: [tlsInspection(), updated] });
    await h.component.openBatchTlsInspection();
    assert.equal(h.component.state.batchPreview.nodes[0].server_name, 'sni.example');
    await h.component.inspectBatchTls();
    assert.equal(h.component.state.batchPreview.nodes[0].server_name, 'changed.example');
    assert.equal(h.requests.length, 2);
    assert.ok(h.requests.every(request => request.url.endsWith('/tls-fields/inspect')));
});

test('inspection renders empty, inactive and inapplicable fields and both conflicting SNI values', async () => {
    const response = tlsInspection();
    Object.assign(response.body.data.nodes[1], {
        server_name: 'legacy.example', server_name_conflict: true,
        server_name_values: { serverName: 'legacy.example', server_name: 'batch.example' },
    });
    const h = seeded({ responses: [response] });
    await h.component.openBatchTlsInspection();
    const tree = h.component.renderBatchOperations();
    const text = flatten(tree);
    assert.match(text, /未启用 TLS/);
    assert.match(text, /不适用/);
    assert.match(text, /两项 SNI 配置不一致/);
    assert.match(text, /legacy\.example/);
    assert.match(text, /batch\.example/);
    assert.match(text, /target\.example/);
    assert.doesNotMatch(text, /确认应用|新 SNI|新 Server Address/);
    assert.equal(elements(tree).filter(element => element.type === 'table').length, 1);
    assert.equal(elements(tree).filter(element => element.type === 'tr').length, 3);
    assert.equal(h.component.tlsInspectionField({ server_name_applicable: true, server_name: '' }, 'server_name'), '未填写');
    assert.equal(h.component.tlsInspectionField({ dest_applicable: true, dest: '' }, 'dest'), '未填写（默认使用 SNI）');
});

test('inspection rejects missing and oversized selections before requesting', async () => {
    const h = harness();
    await h.component.openBatchTlsInspection();
    assert.match(h.component.state.batchError, /勾选/);
    assert.equal(h.requests.length, 0);
    const servers = Array.from({ length: 201 }, (_, index) => ({ type: 'vmess', id: index + 1, name: 'node' }));
    const oversized = seeded({ servers }, servers.map(node => 'vmess:' + node.id));
    await oversized.component.openBatchTlsInspection();
    assert.match(oversized.component.state.batchError, /最多查看 200/);
    assert.equal(oversized.requests.length, 0);
});

test('inspection double clicks issue one query and keep closing disabled while loading', async () => {
    let finish;
    const h = seeded({ responses: [() => new Promise(resolve => { finish = resolve; })] });
    const first = h.component.openBatchTlsInspection();
    await h.component.openBatchTlsInspection();
    h.component.closeBatchDialog();
    assert.equal(h.component.state.batchDialog, 'inspect');
    assert.equal(h.component.renderBatchOperations().props.closable, false);
    assert.equal(h.requests.length, 1);
    finish(tlsInspection());
    await first;
    assert.equal(h.component.state.batchLoading, false);
});

test('selection changes during a query cannot resurrect the previous inspection', async () => {
    let finish;
    const h = seeded({ responses: [() => new Promise(resolve => { finish = resolve; })] });
    const reading = h.component.openBatchTlsInspection();
    h.component.changeBatchSelection(['vmess:1']);
    finish(tlsInspection());
    await reading;
    assert.equal(h.component.state.batchPreview, null);
    assert.match(h.component.state.batchError, /选择已变更/);
});

test('failed or mismatched refreshes clear old results and allow retrying the read', async () => {
    const duplicate = tlsInspection();
    duplicate.body.data.nodes[1] = duplicate.body.data.nodes[0];
    for (const failure of [new Error('offline'), { status: 422, body: { message: '节点不存在' } },
        tlsInspection({ matched_count: 1 }), duplicate, { body: { data: { matched_count: 2, nodes: [{}, {}] } } }]) {
        const h = seeded({ responses: [tlsInspection(), failure, tlsInspection()] });
        await h.component.openBatchTlsInspection();
        await h.component.inspectBatchTls();
        assert.equal(h.component.state.batchPreview, null);
        assert.ok(h.component.state.batchError);
        assert.equal(h.component.state.batchLoading, false);
        await h.component.inspectBatchTls();
        assert.equal(h.component.state.batchPreview.matched_count, 2);
        assert.equal(h.component.state.batchError, '');
    }
});

test('inspection copies a tabular result through either clipboard path without write requests', async () => {
    for (const clipboardAvailable of [true, false]) {
        const response = tlsInspection();
        response.body.data.nodes[0].name = 'name\nwith\ttabs';
        Object.assign(response.body.data.nodes[1], { server_name: 'legacy.example', server_name_conflict: true,
            server_name_values: { serverName: 'legacy.example', server_name: 'batch.example' } });
        const h = seeded({ responses: [response], clipboardAvailable });
        await h.component.openBatchTlsInspection();
        await h.component.copyBatchTlsInspection();
        assert.equal(h.clipboardWrites.length, 1);
        const lines = h.clipboardWrites[0].split('\n');
        assert.equal(lines.length, 3);
        assert.ok(lines.every(line => line.split('\t').length === 5));
        assert.match(h.clipboardWrites[0], /legacy\.example.*batch\.example/);
        assert.match(h.component.state.batchResult, /已复制 2/);
        assert.equal(h.requests.length, 1);
    }
});

test('clipboard denial keeps inspected values visible and reports the failure', async () => {
    const h = seeded({ responses: [tlsInspection()], clipboardError: true });
    await h.component.openBatchTlsInspection();
    await h.component.copyBatchTlsInspection();
    assert.equal(h.component.state.batchPreview.matched_count, 2);
    assert.match(h.component.state.batchError, /复制失败/);
    assert.equal(h.component.state.batchResult, '');
});

const ratePreview = (overrides = {}) => ({ body: { data: Object.assign({
    rate: '1.50', matched_count: 2, changed_count: 2,
    nodes: [
        { type: 'v2node', id: 1, name: 'reality-a', rate: '1', new_rate: '1.50', changed: true },
        { type: 'vmess', id: 1, name: 'vmess-a', rate: '2', new_rate: '1.50', changed: true },
    ],
}, overrides) } });
const rateApplied = (overrides = {}) => ({ body: { data: Object.assign({
    rate: '1.50', requested_count: 2, matched_count: 2, updated_count: 2,
    nodes: [{ type: 'v2node', id: 1, rate: '1.50' }, { type: 'vmess', id: 1, rate: '1.50' }],
}, overrides) } });
const rateListing = (rates = [1.5, '1.50']) => listing(NODES.map((node, index) => Object.assign({}, node, { rate: index < 2 ? rates[index] : '9' })));
function rateHarness(options, rate = '1.5') {
    const h = seeded(options);
    h.component.openBatchDialog('rate');
    h.component.changeBatchField('batchRate', rate);
    return h;
}

test('the batch rate menu item opens the drawer without a request', () => {
    const h = seeded({});
    const button = () => operation(h, '批量设置倍率');
    assert.equal(button().props.disabled, false);
    button().props.onClick();
    assert.equal(h.component.state.batchDialog, 'rate');
    assert.match(flatten(h.component.renderBatchOperations()), /节点基础倍率/);
    assert.match(flatten(h.component.renderBatchOperations()), /时段倍率和用户动态倍率/);
    assert.equal(h.requests.length, 0);
    h.component.props.serverManage.sortMode = true;
    assert.equal(button().props.disabled, true);
    h.component.props.serverManage.sortMode = false;
    h.component.changeBatchSelection([]);
    assert.equal(button().props.disabled, true);
    const mobile = seeded({ mobile: true });
    assert.equal(operation(mobile, '批量设置倍率').props.disabled, false);
});

const portPreview = (overrides = {}) => ({ body: { data: Object.assign({
    mode: 'server_port', server_port: 8443, matched_count: 2, changed_count: 2,
    nodes: [
        { type: 'v2node', id: 1, name: 'reality-a', server_port: 443, new_server_port: 8443, changed: true },
        { type: 'vmess', id: 1, name: 'vmess-a', server_port: 9443, new_server_port: 8443, changed: true },
    ],
}, overrides) } });
const portApplied = (overrides = {}) => ({ body: { data: Object.assign({
    mode: 'server_port', server_port: 8443, requested_count: 2, matched_count: 2, updated_count: 2,
    nodes: [{ type: 'v2node', id: 1, server_port: 8443 }, { type: 'vmess', id: 1, server_port: 8443 }],
}, overrides) } });
const portListing = (ports = [8443, '8443']) => listing(NODES.map((node, index) => Object.assign({}, node, { server_port: index < 2 ? ports[index] : 443 })));
function portHarness(options, port = '8443') {
    const h = seeded(options);
    h.component.openBatchDialog('ports');
    h.component.changeBatchField('batchServerPort', port);
    return h;
}

test('batch server port uses the selection and opens from the menu without making requests', () => {
    const h = seeded({});
    const button = () => operation(h, '批量设置端口');
    assert.equal(button().props.disabled, false);
    button().props.onClick();
    assert.equal(h.component.state.batchDialog, 'ports');
    const text = flatten(h.component.renderBatchOperations());
    assert.match(text, /选中的 2 个节点/);
    assert.match(text, /连接端口保持原值/);
    assert.equal(h.requests.length, 0);
    h.component.props.serverManage.sortMode = true;
    assert.equal(button().props.disabled, true);
    h.component.props.serverManage.sortMode = false;
    h.component.changeBatchSelection([]);
    assert.equal(button().props.disabled, true);
    assert.equal(operation(seeded({ mobile: true }), '批量设置端口').props.disabled, false);
});

test('server port previews saved values, confirms with the snapshot, then rereads selected nodes', async () => {
    const h = portHarness({ responses: [portPreview(), portApplied(), portListing()] });
    await h.component.previewBatchPorts();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].url, '/api/v1/test-admin/server/manage/ports/preview');
    assert.equal(h.requests[0].options.headers.authorization, 'test-auth');
    assert.equal(h.requests[0].options.headers['Content-Type'], 'application/json');
    assert.deepEqual(h.requests[0].body, { nodes: [
        { type: 'v2node', id: 1, name: 'reality-a' }, { type: 'vmess', id: 1, name: 'vmess-a' },
    ], mode: 'server_port', server_port: 8443 });
    assert.equal(successful(h), false);
    assert.match(flatten(h.component.renderBatchOperations()), /当前服务端口目标服务端口.*443.*8443/);
    await h.component.applyBatchPorts();
    assert.equal(h.requests[1].url, '/api/v1/test-admin/server/manage/ports/apply');
    assert.deepEqual(h.requests[1].body, { nodes: [
        { type: 'v2node', id: 1, server_port: 443 }, { type: 'vmess', id: 1, server_port: 9443 },
    ], mode: 'server_port', server_port: 8443, confirm: true });
    assert.match(h.requests[2].url, /\/getNodes\?_batch_ports=\d+/);
    assert.equal(h.actions[0].payload.servers[0].server_port, 8443);
    assert.match(h.component.state.batchResult, /已核对 2.*8443.*更新 2/);
    assert.equal(successful(h), true);
    assert.equal(h.component.state.batchPreview, null);
});

test('server port validation rejects invalid ports, missing selection and oversized batches before requests', async () => {
    for (const port of ['', '0', '-1', '65536', '1.5', '443.0', '1e2', '+443', '00443', '443-444', '443,444', 'NaN']) {
        const h = portHarness({ responses: [] }, port);
        await h.component.previewBatchPorts();
        assert.equal(h.requests.length, 0, port);
        assert.match(h.component.state.batchError, /1～65535/);
    }
    const h = portHarness({ responses: [] });
    h.component.changeBatchSelection([]);
    await h.component.previewBatchPorts();
    assert.equal(h.requests.length, 0);
    assert.match(h.component.state.batchError, /勾选/);
    const servers = Array.from({ length: 201 }, (_, index) => ({ type: 'vmess', id: index + 1, name: 'node' }));
    const oversized = portHarness({ servers, responses: [] });
    oversized.component.changeBatchSelection(servers.map(node => 'vmess:' + node.id));
    await oversized.component.previewBatchPorts();
    assert.equal(oversized.requests.length, 0);
    assert.match(oversized.component.state.batchError, /最多设置 200/);
});

test('server port boundaries accept legacy empty values in the preview', async () => {
    for (const port of [1, 65535]) {
        const response = portPreview({ server_port: port });
        response.body.data.nodes.forEach(node => { node.server_port = null; node.new_server_port = port; });
        const h = portHarness({ responses: [response] }, String(port));
        await h.component.previewBatchPorts();
        assert.equal(h.requests[0].body.server_port, port);
        assert.equal(h.component.state.batchError, '');
        assert.match(flatten(h.component.renderBatchOperations()), /未设置/);
    }
});

test('changing port input or selection invalidates the preview', async () => {
    for (const change of [h => h.component.changeBatchField('batchServerPort', '443'), h => h.component.changeBatchSelection(['vmess:1'])]) {
        const h = portHarness({ responses: [portPreview()] });
        await h.component.previewBatchPorts();
        change(h);
        await h.component.applyBatchPorts();
        assert.equal(h.requests.length, 1);
        assert.equal(h.component.state.batchPreview, null);
    }
});

test('server port changes during preview cannot enable outdated confirmation', async () => {
    let finish;
    const h = portHarness({ responses: [() => new Promise(resolve => { finish = resolve; })] });
    const reading = h.component.previewBatchPorts();
    h.component.changeBatchSelection(['vmess:1']);
    finish(portPreview());
    await reading;
    assert.equal(h.component.state.batchPreview, null);
    assert.match(h.component.state.batchError, /选择已变更/);
});

test('server port matching values disable confirmation and double clicks never duplicate writes', async () => {
    const response = portPreview({ changed_count: 0 });
    response.body.data.nodes.forEach(node => { node.server_port = 8443; node.changed = false; });
    const same = portHarness({ responses: [response] });
    await same.component.previewBatchPorts();
    const confirm = elements(same.component.renderBatchOperations()).find(element => flatten(element.children) === '确认应用');
    assert.equal(confirm.props.disabled, true);
    await same.component.applyBatchPorts();
    assert.equal(same.requests.length, 1);
    const h = portHarness({ responses: [portPreview(), portApplied(), portListing()] });
    await Promise.all([h.component.previewBatchPorts(), h.component.previewBatchPorts()]);
    assert.equal(h.requests.length, 1);
    await Promise.all([h.component.applyBatchPorts(), h.component.applyBatchPorts()]);
    await h.component.applyBatchPorts();
    assert.equal(h.requests.length, 3);
});

test('invalid port previews cannot enable saving', async () => {
    const duplicate = portPreview();
    duplicate.body.data.nodes[1] = duplicate.body.data.nodes[0];
    const wrongTarget = portPreview();
    wrongTarget.body.data.nodes[0].new_server_port = 443;
    for (const response of [duplicate, wrongTarget, portPreview({ matched_count: 1 }), portPreview({ changed_count: 1 }), new Error('offline')]) {
        const h = portHarness({ responses: [response] });
        await h.component.previewBatchPorts();
        await h.component.applyBatchPorts();
        assert.equal(h.component.state.batchPreview, null);
        assert.ok(h.component.state.batchError);
        assert.equal(h.requests.length, 1);
    }
});

test('failed or conflicting port saves clear confirmation and never claim success', async () => {
    for (const response of [new Error('offline'), { status: 409, body: { message: '节点服务端口已变更，请重新预览' } },
        { status: 500, body: { message: 'save failed' } }]) {
        const h = portHarness({ responses: [portPreview(), response] });
        await h.component.previewBatchPorts();
        await h.component.applyBatchPorts();
        await h.component.applyBatchPorts();
        assert.equal(h.component.state.batchPreview, null);
        assert.match(h.component.state.batchError, /未确认完成/);
        assert.equal(successful(h), false);
        assert.equal(h.requests.length, 2);
    }
});

test('port saves verify every selected node and do not retry when refresh fails', async () => {
    for (const refresh of [portListing([8443, 9443]), new Error('offline')]) {
        const preview = portPreview({ changed_count: 1 });
        preview.body.data.nodes[1].server_port = 8443;
        preview.body.data.nodes[1].changed = false;
        const h = portHarness({ responses: [preview, portApplied({ updated_count: 1, nodes: [{ type: 'v2node', id: 1, server_port: 8443 }] }), refresh] });
        await h.component.previewBatchPorts();
        await h.component.applyBatchPorts();
        await h.component.applyBatchPorts();
        assert.match(h.component.state.batchError, /端口核对失败.*勿重复提交/);
        assert.equal(successful(h), false);
        assert.equal(h.component.state.batchPreview, null);
        assert.equal(h.requests.length, 3);
    }
});

test('invalid port save responses never claim success', async () => {
    for (const response of [portApplied({ server_port: 443 }), portApplied({ updated_count: 1, nodes: [{ type: 'trojan', id: 7, server_port: 8443 }] }),
        portApplied({ nodes: [{ type: 'vmess', id: 1, server_port: 8443 }, { type: 'vmess', id: 1, server_port: 8443 }] })]) {
        const h = portHarness({ responses: [portPreview(), response] });
        await h.component.previewBatchPorts();
        await h.component.applyBatchPorts();
        assert.match(h.component.state.batchError, /勿重复提交/);
        assert.equal(successful(h), false);
        assert.equal(h.component.state.batchPreview, null);
    }
});

function multiPortHarness(mode, { connectionPort = '7443', staleConnectionPort = false } = {}) {
    const preview = portPreview({ mode }), applied = portApplied({ mode });
    if (mode !== 'server_port') {
        preview.body.data.port = connectionPort;
        applied.body.data.port = connectionPort;
        preview.body.data.nodes.forEach((node, index) => {
            node.port = index ? '443' : '10000-20000';
            node.new_port = connectionPort;
        });
        applied.body.data.nodes.forEach(node => { node.port = connectionPort; });
    }
    if (mode === 'port') {
        delete preview.body.data.server_port;
        delete applied.body.data.server_port;
        preview.body.data.nodes.forEach(node => { delete node.server_port; delete node.new_server_port; });
        applied.body.data.nodes.forEach(node => { delete node.server_port; });
    }
    const servers = NODES.map((node, index) => Object.assign({}, node, {
        server_port: mode === 'port' ? 9443 : 8443,
        port: staleConnectionPort ? '443' : (index ? Number(connectionPort) : connectionPort),
    }));
    const h = portHarness({ responses: [preview, applied, listing(servers)] });
    h.component.changeBatchField('batchConnectionPort', connectionPort);
    h.component.changeBatchField('batchPortMode', mode);
    return h;
}

test('one port button exposes three modes with only the relevant inputs', () => {
    const h = portHarness({});
    assert.equal(operationItems(h).filter(element => flatten(element.children) === '批量设置端口').length, 1);
    let tree = elements(h.component.renderBatchOperations());
    const selector = tree.find(element => element.props.id === 'batch-port-mode');
    assert.deepEqual(plain(selector.children).map(option => option.props.value), ['server_port', 'port', 'both']);
    assert.ok(tree.some(element => element.props.id === 'batch-port-server_port'));
    assert.ok(!tree.some(element => element.props.id === 'batch-port-port'));
    selector.props.onChange({ target: { value: 'port' } });
    tree = elements(h.component.renderBatchOperations());
    assert.ok(!tree.some(element => element.props.id === 'batch-port-server_port'));
    assert.ok(tree.some(element => element.props.id === 'batch-port-port'));
    selector.props.onChange({ target: { value: 'both' } });
    tree = elements(h.component.renderBatchOperations());
    assert.ok(tree.some(element => element.props.id === 'batch-port-server_port'));
    assert.ok(tree.some(element => element.props.id === 'batch-port-port'));
    assert.equal(h.requests.length, 0);
});

test('connection and both modes send only active targets and preserve full old port ranges in snapshots', async () => {
    for (const mode of ['port', 'both']) {
        const h = multiPortHarness(mode);
        await h.component.previewBatchPorts();
        assert.equal(h.requests[0].body.mode, mode);
        assert.equal(h.requests[0].body.port, '7443');
        assert.equal(h.requests[0].body.server_port, mode === 'both' ? 8443 : undefined);
        const text = flatten(h.component.renderBatchOperations());
        assert.match(text, /当前连接端口目标连接端口/);
        assert.match(text, /10000-20000/);
        if (mode === 'both') assert.match(text, /当前服务端口目标服务端口/);
        await h.component.applyBatchPorts();
        assert.equal(h.requests[1].body.nodes[0].port, '10000-20000');
        assert.equal(h.requests[1].body.nodes[0].server_port, mode === 'both' ? 443 : undefined);
        assert.equal(h.requests[1].body.confirm, true);
        assert.equal(h.requests.length, 3);
        assert.equal(successful(h), true);
        assert.match(h.component.state.batchResult, /连接端口 7443/);
        if (mode === 'both') assert.match(h.component.state.batchResult, /服务端口 8443/);
    }
});

test('both ports can be set to the same value and connection-only ignores hidden invalid server input', async () => {
    const both = multiPortHarness('both', { connectionPort: '8443' });
    await both.component.previewBatchPorts();
    await both.component.applyBatchPorts();
    assert.equal(successful(both), true);
    const connection = multiPortHarness('port');
    connection.component.changeBatchField('batchServerPort', 'invalid');
    await connection.component.previewBatchPorts();
    await connection.component.applyBatchPorts();
    assert.equal(successful(connection), true);
    assert.equal(connection.requests[1].body.server_port, undefined);
});

test('both mode requires both valid targets and switching mode invalidates a prior preview', async () => {
    for (const field of ['batchServerPort', 'batchConnectionPort']) {
        const h = multiPortHarness('both');
        h.component.changeBatchField(field, '');
        await h.component.previewBatchPorts();
        assert.equal(h.requests.length, 0);
        assert.match(h.component.state.batchError, /1～65535/);
    }
    for (const change of [h => h.component.changeBatchField('batchPortMode', 'port'), h => h.component.changeBatchField('batchConnectionPort', '9443')]) {
        const h = multiPortHarness('both');
        await h.component.previewBatchPorts();
        change(h);
        await h.component.applyBatchPorts();
        assert.equal(h.component.state.batchPreview, null);
        assert.equal(h.requests.length, 1);
    }
});

test('both mode checks connection port persistence as well as server port', async () => {
    const h = multiPortHarness('both', { staleConnectionPort: true });
    await h.component.previewBatchPorts();
    await h.component.applyBatchPorts();
    assert.equal(successful(h), false);
    assert.match(h.component.state.batchError, /端口核对失败/);
});

test('a response for the wrong port mode cannot enable confirmation', async () => {
    const h = portHarness({ responses: [portPreview({ mode: 'port' })] });
    await h.component.previewBatchPorts();
    await h.component.applyBatchPorts();
    assert.equal(h.component.state.batchPreview, null);
    assert.equal(h.requests.length, 1);
    assert.ok(h.component.state.batchError);
});

test('batch rate previews selected nodes, applies with confirmation, then verifies actual rates', async () => {
    const h = rateHarness({ responses: [ratePreview(), rateApplied(), rateListing()] });
    await h.component.previewBatchRate();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].url, '/api/v1/test-admin/server/manage/rate/preview');
    assert.equal(h.requests[0].options.headers.authorization, 'test-auth');
    assert.deepEqual(h.requests[0].body, { nodes: [
        { type: 'v2node', id: 1, name: 'reality-a' }, { type: 'vmess', id: 1, name: 'vmess-a' },
    ], rate: '1.5' });
    assert.equal(successful(h), false);
    const text = flatten(h.component.renderBatchOperations());
    assert.match(text, /当前倍率/);
    assert.match(text, /目标倍率/);
    assert.match(text, /1\.50 x/);
    await h.component.applyBatchRate();
    assert.equal(h.requests[1].url, '/api/v1/test-admin/server/manage/rate/apply');
    assert.deepEqual(h.requests[1].body, Object.assign({}, h.requests[0].body, { confirm: true }));
    assert.match(h.requests[2].url, /\/getNodes\?_batch_rate=\d+/);
    assert.equal(h.actions[0].type, 'serverManage/setState');
    assert.equal(h.actions[0].payload.servers[0].rate, 1.5);
    assert.match(h.component.state.batchResult, /已核对 2.*1\.50 x.*更新 2/);
    assert.equal(successful(h), true);
    assert.equal(h.component.state.batchPreview, null);
});

test('batch rate rejects empty, nonpositive, nondecimal, excessive and oversized inputs before requesting', async () => {
    for (const rate of ['', '0', '0.00', '-1', '0.001', '1.234', '100000000', '1e2', 'NaN', 'Infinity', '+1', '1.']) {
        const h = rateHarness({ responses: [] }, rate);
        await h.component.previewBatchRate();
        assert.equal(h.requests.length, 0, rate);
        assert.match(h.component.state.batchError, /倍率须/);
    }
    const h = rateHarness({ responses: [] });
    h.component.changeBatchSelection([]);
    await h.component.previewBatchRate();
    assert.match(h.component.state.batchError, /勾选/);
    const servers = Array.from({ length: 201 }, (_, index) => ({ type: 'vmess', id: index + 1, name: 'node' }));
    const oversized = rateHarness({ servers, responses: [] });
    oversized.component.changeBatchSelection(servers.map(node => 'vmess:' + node.id));
    await oversized.component.previewBatchRate();
    assert.equal(oversized.requests.length, 0);
    assert.match(oversized.component.state.batchError, /最多设置 200/);
});

test('rate input and selection changes invalidate confirmation', async () => {
    const h = rateHarness({ responses: [ratePreview(), ratePreview()] });
    await h.component.previewBatchRate();
    h.component.changeBatchField('batchRate', '2');
    await h.component.applyBatchRate();
    assert.equal(h.requests.length, 1);
    h.component.changeBatchField('batchRate', '1.5');
    await h.component.previewBatchRate();
    h.component.changeBatchSelection(['vmess:1']);
    await h.component.applyBatchRate();
    assert.equal(h.requests.length, 2);
    assert.equal(h.component.state.batchPreview, null);
});

test('a rate preview with no changes disables confirmation and makes no write request', async () => {
    const response = ratePreview({ changed_count: 0 });
    response.body.data.nodes.forEach(node => { node.rate = '1.500'; node.changed = false; });
    const h = rateHarness({ responses: [response] });
    await h.component.previewBatchRate();
    const confirm = elements(h.component.renderBatchOperations()).find(element => flatten(element.children) === '确认应用');
    assert.equal(confirm.props.disabled, true);
    assert.match(h.component.state.batchResult, /无需修改/);
    await h.component.applyBatchRate();
    assert.equal(h.requests.length, 1);
    assert.equal(successful(h), false);
});

test('rate preview and apply double clicks each issue one request', async () => {
    const h = rateHarness({ responses: [ratePreview(), rateApplied(), rateListing()] });
    await Promise.all([h.component.previewBatchRate(), h.component.previewBatchRate()]);
    assert.equal(h.requests.length, 1);
    await Promise.all([h.component.applyBatchRate(), h.component.applyBatchRate()]);
    assert.equal(h.requests.length, 3);
    await h.component.applyBatchRate();
    assert.equal(h.requests.length, 3);
});

test('rate selection changes during preview cannot restore an outdated confirmation', async () => {
    let finish;
    const h = rateHarness({ responses: [() => new Promise(resolve => { finish = resolve; })] });
    const reading = h.component.previewBatchRate();
    h.component.changeBatchSelection(['vmess:1']);
    finish(ratePreview());
    await reading;
    assert.equal(h.component.state.batchPreview, null);
    assert.match(h.component.state.batchError, /选择已变更/);
});

test('invalid or mismatched rate previews cannot enable a write', async () => {
    const duplicate = ratePreview();
    duplicate.body.data.nodes[1] = duplicate.body.data.nodes[0];
    const wrongTarget = ratePreview();
    wrongTarget.body.data.nodes[0].new_rate = '2';
    for (const response of [duplicate, wrongTarget, ratePreview({ matched_count: 1 }), ratePreview({ changed_count: 1 }), ratePreview({ rate: '2' }), new Error('offline')]) {
        const h = rateHarness({ responses: [response] });
        await h.component.previewBatchRate();
        assert.equal(h.component.state.batchPreview, null);
        assert.ok(h.component.state.batchError);
        await h.component.applyBatchRate();
        assert.equal(h.requests.length, 1);
        assert.equal(successful(h), false);
    }
});

test('failed rate saves clear confirmation and cannot be blindly resubmitted', async () => {
    for (const response of [new Error('offline'), { status: 500, body: { message: 'save failed' } }]) {
        const h = rateHarness({ responses: [ratePreview(), response] });
        await h.component.previewBatchRate();
        await h.component.applyBatchRate();
        assert.equal(h.component.state.batchPreview, null);
        assert.match(h.component.state.batchError, /未确认完成/);
        assert.equal(successful(h), false);
        await h.component.applyBatchRate();
        assert.equal(h.requests.length, 2);
    }
});

test('rate verification checks every selected node including nodes the server did not update', async () => {
    const preview = ratePreview({ changed_count: 1 });
    preview.body.data.nodes[1].rate = '1.50';
    preview.body.data.nodes[1].changed = false;
    const applied = rateApplied({ updated_count: 1, nodes: [{ type: 'v2node', id: 1, rate: '1.50' }] });
    const h = rateHarness({ responses: [preview, applied, rateListing([1.5, 2])] });
    await h.component.previewBatchRate();
    await h.component.applyBatchRate();
    assert.match(h.component.state.batchError, /倍率核对失败.*勿重复提交/);
    assert.match(h.component.state.batchError, /与目标值不一致/);
    assert.equal(successful(h), false);
    assert.equal(h.component.state.batchPreview, null);
});

test('a rate save which becomes a no-op still rereads and confirms matching values', async () => {
    const h = rateHarness({ responses: [ratePreview(), rateApplied({ updated_count: 0, nodes: [] }), rateListing()] });
    await h.component.previewBatchRate();
    await h.component.applyBatchRate();
    assert.equal(h.requests.length, 3);
    assert.equal(successful(h), true);
    assert.match(h.component.state.batchResult, /本次更新 0/);
});

test('invalid saved results and failed rate refreshes never claim success or keep confirmation', async () => {
    for (const response of [rateApplied({ updated_count: 1, nodes: [{ type: 'trojan', id: 7, rate: '1.50' }] }),
        rateApplied({ rate: '2' }), rateApplied({ updated_count: 2, nodes: [{ type: 'vmess', id: 1, rate: '1.50' }, { type: 'vmess', id: 1, rate: '1.50' }] })]) {
        const h = rateHarness({ responses: [ratePreview(), response] });
        await h.component.previewBatchRate();
        await h.component.applyBatchRate();
        assert.equal(successful(h), false);
        assert.match(h.component.state.batchError, /勿重复提交/);
        assert.equal(h.component.state.batchPreview, null);
    }
    const h = rateHarness({ responses: [ratePreview(), rateApplied(), new Error('offline')] });
    await h.component.previewBatchRate();
    await h.component.applyBatchRate();
    assert.equal(successful(h), false);
    assert.match(h.component.state.batchError, /倍率核对失败/);
    await h.component.applyBatchRate();
    assert.equal(h.requests.length, 3);
});
