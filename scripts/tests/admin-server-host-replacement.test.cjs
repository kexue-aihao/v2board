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
function harness({ responses = [], contentType = 'application/json; charset=utf-8' } = {}) {
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
    const component = new exports.default({ dispatch: action => actions.push(action) });
    component.startHostReplace();
    component.changeHostReplace('hostReplaceMode', 'contains');
    component.changeHostReplace('hostReplaceOld', 'old.example');
    component.changeHostReplace('hostReplaceNew', 'new.example');
    return { component, requests, messages, actions };
}
const preview = () => ({ body: { data: { matched_count: 1, nodes: [
    { type: 'vmess', id: 3, name: 'HK', host: 'hk.old.example', new_host: 'hk.new.example' },
] } } });
const replaced = () => ({ body: { data: { updated_count: 1, nodes: [
    { type: 'vmess', id: 3, old_host: 'hk.old.example', new_host: 'hk.new.example' },
] } } });
const listing = (host = 'hk.new.example') => ({ body: { data: [{ type: 'vmess', id: 3, host }] } });
const successful = h => h.messages.some(message => message.kind === 'success');

for (const contentType of ['application/json', 'application/json; charset=utf-8', 'Application/JSON; Charset=UTF-8']) {
    test('saves, refreshes and verifies with ' + contentType, async () => {
        const h = harness({ responses: [preview(), replaced(), listing()], contentType });
        assert.equal(h.requests.length, 0);
        assert.equal(h.component.renderHostReplacement().props.visible, true);
        await h.component.previewHostReplace();
        assert.equal(h.requests.length, 1, 'preview must not submit an update');
        assert.equal(successful(h), false);
        assert.equal(h.component.state.hostReplacePreview.matched_count, 1);
        await h.component.replaceHost();
        assert.equal(h.requests.length, 3);
        assert.equal(h.requests[0].url, '/api/v1/test-admin/server/manage/host/preview');
        assert.equal(h.requests[1].url, '/api/v1/test-admin/server/manage/host/replace');
        assert.equal(h.requests[1].options.headers.authorization, 'test-auth');
        assert.equal(h.requests[1].options.headers['Content-Type'], 'application/json');
        assert.deepEqual(h.requests[1].body, { mode: 'contains', old_host: 'old.example', new_host: 'new.example', confirm: true });
        assert.match(h.requests[2].url, /\/getNodes\?_host_replace=\d+/);
        assert.equal(h.actions[0].type, 'serverManage/setState');
        assert.equal(h.actions[0].payload.servers[0].host, 'hk.new.example');
        assert.equal(h.component.state.hostReplaceLoading, false);
        assert.equal(h.component.state.hostReplacePreview, null);
        assert.equal(h.component.state.hostReplaceError, '');
        assert.equal(successful(h), true);
    });
}
test('editing the input invalidates the preview', async () => {
    const h = harness({ responses: [preview()] });
    await h.component.previewHostReplace();
    h.component.changeHostReplace('hostReplaceNew', 'other.example');
    await h.component.replaceHost();
    assert.equal(h.requests.length, 1);
    assert.equal(h.component.state.hostReplacePreview, null);
});
test('closing the panel makes no write request', async () => {
    const h = harness({ responses: [preview()] });
    await h.component.previewHostReplace();
    h.component.closeHostReplace();
    assert.equal(h.requests.length, 1);
    assert.equal(h.component.state.hostReplaceVisible, false);
});
test('empty previews and zero updates never show success', async () => {
    const empty = harness({ responses: [{ body: { data: { matched_count: 0, nodes: [] } } }] });
    await empty.component.previewHostReplace();
    await empty.component.replaceHost();
    assert.equal(empty.requests.length, 1);
    assert.match(empty.component.state.hostReplaceResult, /没有匹配/);
    assert.equal(successful(empty), false);
    const zero = harness({ responses: [preview(), { body: { data: { updated_count: 0, nodes: [] } } }] });
    await zero.component.previewHostReplace();
    await zero.component.replaceHost();
    assert.equal(zero.requests.length, 2);
    assert.match(zero.component.state.hostReplaceError, /没有更新/);
    assert.equal(successful(zero), false);
});
test('stale list data is reported instead of claiming success', async () => {
    const h = harness({ responses: [preview(), replaced(), listing('hk.old.example')] });
    await h.component.previewHostReplace();
    await h.component.replaceHost();
    assert.match(h.component.state.hostReplaceError, /域名与保存结果不一致/);
    assert.equal(successful(h), false);
});
test('failed refresh keeps the saved warning visible and prevents another write', async () => {
    const h = harness({ responses: [preview(), replaced(), new Error('offline')] });
    await h.component.previewHostReplace();
    await h.component.replaceHost();
    await h.component.replaceHost();
    assert.equal(h.requests.length, 3);
    assert.match(h.component.state.hostReplaceError, /已返回保存成功.*勿重复提交/);
    assert.equal(successful(h), false);
});
test('preview network, validation and invalid response errors stay visible', async () => {
    for (const response of [new Error('offline'), { status: 422, body: { message: 'invalid host' } }, { body: {} }]) {
        const h = harness({ responses: [response] });
        await h.component.previewHostReplace();
        assert.equal(h.requests.length, 1);
        assert.ok(h.component.state.hostReplaceError);
        assert.equal(h.component.state.hostReplaceLoading, false);
        assert.equal(h.component.state.hostReplacePreview, null);
    }
});
test('failed replacement disables stale confirmation', async () => {
    const h = harness({ responses: [preview(), { status: 500, body: { message: 'save failed' } }] });
    await h.component.previewHostReplace();
    await h.component.replaceHost();
    await h.component.replaceHost();
    assert.equal(h.requests.length, 2);
    assert.match(h.component.state.hostReplaceError, /save failed/);
    assert.equal(h.component.state.hostReplacePreview, null);
});
test('double clicks issue only one write', async () => {
    const h = harness({ responses: [preview(), replaced(), listing()] });
    await h.component.previewHostReplace();
    await Promise.all([h.component.replaceHost(), h.component.replaceHost()]);
    assert.equal(h.requests.length, 3);
});
