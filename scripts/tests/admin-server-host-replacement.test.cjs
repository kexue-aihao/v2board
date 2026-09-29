const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

// Exercise the shipped webpack module, including its actual request import.
const bundle = fs.readFileSync(path.join(__dirname, '../../public/assets/admin/umi.js'), 'utf8');
const moduleStart = bundle.indexOf('    uzXD: function(e, t, n) {');
const moduleEnd = bundle.indexOf('    v32e: function(e, t, n) {', moduleStart);
assert.ok(moduleStart >= 0 && moduleEnd > moduleStart);
const moduleSource = bundle.slice(moduleStart, moduleEnd).trim().replace(/^uzXD: /, '').replace(/,$/, '');

function harness({ responses = [], confirm = true, prompts = ['contains', 'old.example', 'new.example'] } = {}) {
    const requests = [], messages = [], confirmations = [], actions = [];
    class Component {
        constructor(props) { this.props = props; }
        setState(update, callback) { Object.assign(this.state, update); if (callback) callback(); }
    }
    const modules = {
        q1tI: { Component },
        '/MKj': { c: () => ComponentClass => ComponentClass },
        yWgo: { e: () => null },
        t3Un: { b: (url, body, json) => {
            requests.push({ url, body, json });
            const response = responses.shift();
            return response instanceof Error ? Promise.reject(response) : Promise.resolve(response);
        } },
        tsqr: { a: Object.fromEntries(['success', 'warning', 'error'].map(kind => [kind, text => messages.push({ kind, text })])) },
    };
    function requireModule(id) { return modules[id] || { a: () => {} }; }
    requireModule.r = () => {};
    requireModule.n = value => { const getter = () => value; getter.a = value; return getter; };
    const exports = {};
    const factory = vm.runInNewContext('(' + moduleSource + ')', {
        window: {
            settings: { secure_path: 'test-admin' },
            prompt: () => prompts.shift(),
            confirm: text => { confirmations.push(text); return confirm; },
        },
    });
    factory({}, exports, requireModule);
    const component = new exports.default({ dispatch: action => actions.push(action) });
    return { component, requests, messages, confirmations, actions };
}

const preview = () => ({ code: 200, data: { matched_count: 1, nodes: [
    { type: 'vmess', id: 3, host: 'hk.old.example', new_host: 'hk.new.example' },
] } });
const settle = () => new Promise(resolve => setImmediate(resolve));

test('previews, confirms host changes, posts JSON and refreshes the node list', async () => {
    const h = harness({ responses: [preview(), { code: 200, data: { updated_count: 1 } }] });
    h.component.startHostReplace();
    await settle();
    assert.equal(h.requests.length, 2);
    assert.equal(h.requests[0].url, '/test-admin/server/manage/host/preview');
    assert.equal(h.requests[1].url, '/test-admin/server/manage/host/replace');
    assert.equal(h.requests[0].json, true);
    assert.equal(h.requests[1].json, true);
    assert.equal(h.requests[1].body.mode, 'contains');
    assert.equal(h.requests[1].body.old_host, 'old.example');
    assert.equal(h.requests[1].body.new_host, 'new.example');
    assert.equal(h.requests[1].body.confirm, true);
    assert.match(h.confirmations[0], /hk\.old\.example → hk\.new\.example/);
    assert.equal(h.actions[0].type, 'serverManage/getNodes');
    assert.equal(h.component.state.hostReplaceLoading, false);
});

test('cancelling confirmation does not replace hosts', async () => {
    const h = harness({ responses: [preview()], confirm: false });
    h.component.startHostReplace();
    await settle();
    assert.equal(h.requests.length, 1);
    assert.equal(h.actions.length, 0);
});

test('an empty preview does not submit an update', async () => {
    const h = harness({ responses: [{ code: 200, data: { matched_count: 0, nodes: [] } }] });
    h.component.startHostReplace();
    await settle();
    assert.equal(h.requests.length, 1);
    assert.equal(h.confirmations.length, 0);
    assert.equal(h.messages[0].kind, 'warning');
});

test('request errors stop replacement and release the loading state', async () => {
    for (const response of [new Error('offline'), { code: 422, msg: 'invalid host' }]) {
        const h = harness({ responses: [response] });
        h.component.startHostReplace();
        await settle();
        assert.equal(h.requests.length, 1);
        assert.equal(h.messages[0].kind, 'error');
        assert.equal(h.component.state.hostReplaceLoading, false);
        if (response.msg) assert.equal(h.messages[0].text, response.msg);
    }
});

test('repeated clicks while a request is pending do not start another replacement', async () => {
    const h = harness({ responses: [preview()], confirm: false });
    h.component.startHostReplace();
    h.component.startHostReplace();
    await settle();
    assert.equal(h.requests.length, 1);
});
