const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const bundle = fs.readFileSync(path.join(__dirname, '../../resources/admin/legacy/umi.js'), 'utf8');
const start = bundle.indexOf('    d1ca: function(e, t, n) {');
const end = bundle.indexOf('    dI71: function(e, t, n) {', start);
assert.ok(start >= 0 && end > start, 'user management module must exist');
const source = bundle.slice(start, end).trim().slice('d1ca: '.length).replace(/,$/, '');

function harness(respond) {
    const requests = [], modals = [], copied = [], notices = [], dispatched = [];
    class Component {
        constructor(props) { this.props = props; }
    }
    const modules = {
        q1tI: { Component, createElement: (type, props, ...children) => ({ type, props: props || {}, children }) },
        '/MKj': { c: () => ComponentClass => ComponentClass },
        jehZ: Object.assign,
        p0pE: Object.assign,
        yWgo: { a: value => copied.push(value), f: () => false },
        kLXV: { a: { confirm: options => {
            const modal = { options, updates: [], destroyed: false, update(update) { this.updates.push(update); Object.assign(this.options, update); }, destroy() { this.destroyed = true; } };
            modals.push(modal);
            return modal;
        }, success: options => notices.push({type: 'success', options}), error: options => notices.push({type: 'error', options}), info: options => {
            const modal = {
                options, updates: [], destroyed: false,
                update(update) { this.updates.push(update); Object.assign(this.options, update); },
                destroy() { this.destroyed = true; },
            };
            modals.push(modal);
            return modal;
        } } },
        t3Un: {
            // a = GET，b = POST；解绑走 b
            a: async (url, params) => {
                requests.push({ url, params });
                return respond(url, params);
            },
            b: async (url, params) => {
                requests.push({ url, params });
                return respond(url, params);
            }
        },
    };
    function requireModule(id) { return modules[id] || { a: () => {} }; }
    requireModule.r = () => {};
    requireModule.n = value => { const getter = () => value; getter.a = value; return getter; };
    const context = vm.createContext({ window: { settings: { secure_path: 'test-admin' } } });
    const exports = {};
    vm.runInContext('(' + source + ')', context)({}, exports, requireModule);
    const component = new exports.default({
        dispatch: action => dispatched.push(action), user: { users: [], pagination: {}, filter: [] },
        serverGroup: { groups: [] }, plan: { plans: [] },
    });
    return { component, requests, modals, copied, notices, dispatched };
}
function nodes(tree) {
    if (!tree || typeof tree !== 'object') return [];
    return [tree, ...(tree.children || []).flatMap(nodes), ...nodes(tree.props && tree.props.overlay)];
}
function text(tree) {
    if (typeof tree === 'string' || typeof tree === 'number') return String(tree);
    return tree && (tree.children || []).map(text).join('') || '';
}
const user = { id: 17, email: 'member@example.test' };
const bound = (extra = {}) => ({ code: 200, data: {
    bound: true, telegram_id: '4503599627370001', username: 'alice',
    username_status: 'available', message: '', ...extra,
} });

test('both action menus open the selected user Telegram details', () => {
    const h = harness(() => bound());
    const tree = h.component.render();
    const table = nodes(tree).find(node => Array.isArray(node.props.columns));
    assert.ok(table, 'user table must render');
    const actions = table.props.columns.find(column => column.key === 'action').render(null, user);
    const clicked = [];
    h.component.showTelegramInfo = selected => clicked.push(selected.id);
    const desktop = nodes(actions).find(node => node.props.onClick && text(node).includes('查看 Telegram 绑定'));
    assert.ok(desktop, 'operation dropdown must include the new entry');
    desktop.props.onClick();
    h.component.record = { id: 21, email: 'second@example.test' };
    const contextMenu = nodes(tree).find(node => node.props.onClick && text(node).includes('查看 Telegram 绑定'));
    assert.ok(contextMenu, 'context menu must include the new entry');
    contextMenu.props.onClick();
    assert.deepEqual(clicked, [17, 21]);
});

test('shows the UID and current username with working copy buttons', async () => {
    const h = harness(() => bound());
    await h.component.showTelegramInfo(user);
    assert.equal(h.requests[0].url, '/test-admin/user/telegramInfo');
    assert.equal(h.requests[0].params.id, 17);
    const content = h.modals[0].options.content;
    assert.match(text(content), /已绑定/);
    assert.match(text(content), /4503599627370001/);
    assert.match(text(content), /@alice/);
    nodes(content).filter(node => text(node) === '复制').forEach(node => node.props.onClick());
    assert.deepEqual(h.copied, ['4503599627370001', '@alice']);
});

test('unbound users are distinguished from users without a username', async () => {
    const h = harness(() => bound({ bound: false, telegram_id: null, username: null, username_status: 'unbound' }));
    await h.component.showTelegramInfo(user);
    assert.match(text(h.modals[0].options.content), /未绑定/);
    assert.doesNotMatch(text(h.modals[0].options.content), /Telegram UID/);
    const unnamed = harness(() => bound({ username: null, username_status: 'not_set' }));
    await unnamed.component.showTelegramInfo(user);
    assert.match(text(unnamed.modals[0].options.content), /未设置用户名/);
    assert.match(text(unnamed.modals[0].options.content), /4503599627370001/);
});

test('a Telegram lookup failure keeps the known binding visible', async () => {
    const h = harness(() => bound({ username: null, username_status: 'unavailable', message: '请稍后重试' }));
    await h.component.showTelegramInfo(user);
    const content = text(h.modals[0].options.content);
    assert.match(content, /已绑定/);
    assert.match(content, /4503599627370001/);
    assert.match(content, /暂时无法获取/);
    assert.doesNotMatch(content, /未设置用户名/);
});

test('API errors remain visible and offer a retry', async () => {
    for (const response of [new Error('offline'), { code: 403, msg: '登录已过期' }, { code: 200, data: {} }]) {
        const h = harness(() => { if (response instanceof Error) throw response; return response; });
        await h.component.showTelegramInfo(user);
        const content = h.modals[0].options.content;
        assert.ok(nodes(content).some(node => node.props.role === 'alert'));
        assert.match(text(content), /重新查询/);
    }
});

test('a slow response never updates a closed or replaced modal', async () => {
    const pending = [];
    const h = harness(() => new Promise(resolve => pending.push(resolve)));
    const first = h.component.showTelegramInfo(user);
    const second = h.component.showTelegramInfo({ id: 22, email: 'second@example.test' });
    assert.equal(h.modals[0].destroyed, true);
    pending[0](bound());
    await first;
    assert.equal(h.modals[0].updates.length, 0);
    h.modals[1].options.onOk();
    pending[1](bound());
    await second;
    assert.equal(h.modals[1].updates.length, 0);
});

test('leaving user management closes the details and ignores its pending response', async () => {
    let resolve;
    const h = harness(() => new Promise(done => { resolve = done; }));
    const pending = h.component.showTelegramInfo(user);
    h.component.componentWillUnmount();
    assert.equal(h.modals[0].destroyed, true);
    resolve(bound());
    await pending;
    assert.equal(h.modals[0].updates.length, 0);
});

test('the unbind entry only shows for a bound account and asks for confirmation first', async () => {
    const h = harness(() => bound());
    await h.component.showTelegramInfo(user);

    const button = nodes(h.modals[0].options.content).find(node => text(node) === "解绑");
    assert.ok(button, "已绑定时要给出解绑入口");
    assert.equal(button.props.type, "danger");

    button.props.onClick();
    const confirm = h.modals[h.modals.length - 1];
    assert.match(text(confirm.options.content), /解除/);
    assert.equal(confirm.options.okType, "danger");
    // 点确定之前不能发请求：解绑要人明确确认过
    assert.equal(h.requests.filter(request => request.url.endsWith("/user/telegramUnbind")).length, 0);

    await confirm.options.onOk();
    const post = h.requests.find(request => request.url.endsWith("/user/telegramUnbind"));
    assert.ok(post, "确认后必须真的发出解绑请求");
    // vm 沙箱里的对象与 Node realm 原型不同，deepEqual 会误判，比 JSON 更稳
    assert.equal(JSON.stringify(post.params), JSON.stringify({ id: 17, confirm: 1 }));
});

test('an unbound account has no unbind entry', async () => {
    const h = harness(() => ({ code: 200, data: {
        bound: false, telegram_id: null, username: null, username_status: "unbound",
        message: "该用户尚未绑定 Telegram 账号",
    } }));
    await h.component.showTelegramInfo(user);

    assert.equal(nodes(h.modals[0].options.content).find(node => text(node) === "解绑"), undefined);
});

test('subscription cleanup scans first and only deletes after confirmation', async () => {
    const h = harness((url, params) => {
        if (url.endsWith('/user/subscription-cleanup') && params.action === 'scan') {
            return { code: 200, total: 2, data: [
                { id: 7, email: 'expired@example.test', reason: 'expired' },
                { id: 8, email: 'empty@example.test', reason: 'empty' },
            ], scan_token: '12345678901234567890123456789012ab' };
        }
        return { code: 200, data: { deleted_count: 2 } };
    });

    const entry = nodes(h.component.render()).find(node => node.props.onClick && text(node) === ' 检测无效账号');
    assert.ok(entry, 'user management actions must expose cleanup');
    await entry.props.onClick();
    assert.equal(h.requests[0].url, '/test-admin/user/subscription-cleanup');
    assert.equal(h.requests[0].params.action, 'scan');
    assert.equal(h.modals.length, 1);
    assert.match(text(h.modals[0].options.content), /2 个普通用户/);
    assert.match(text(h.modals[0].options.content), /佣金为 0、余额为 0/);
    assert.match(text(h.modals[0].options.content), /其他未过期或长期有效订阅/);
    assert.equal(h.modals[0].options.okType, 'danger');
    assert.equal(h.modals[0].options.cancelText, '仅查看');
    assert.equal(h.requests.length, 1, 'scan must not delete anything');

    await h.modals[0].options.onOk();
    assert.equal(h.requests[1].params.action, 'delete');
    assert.equal(h.requests[1].params.confirm, 1);
    assert.equal(h.requests[1].params.scan_token, '12345678901234567890123456789012ab');
    assert.match(h.notices[0].options.content, /已删除 2 个无效账号/);
    assert.equal(h.dispatched[0].type, 'user/fetch');
});

test('an empty cleanup scan reports the full rule and never offers deletion', async () => {
    const h = harness(() => ({ code: 200, total: 0, data: [], scan_token: 'empty-scan' }));
    await h.component.subscriptionCleanup();
    assert.equal(h.requests.length, 1);
    assert.equal(h.modals.length, 1);
    assert.match(h.modals[0].options.content, /佣金和余额均为 0/);
    assert.equal(h.modals[0].options.onOk, undefined);
});

test('choosing view only leaves all accounts untouched', async () => {
    const h = harness(() => ({code: 200, total: 1, data: [{id: 1, email: 'test'}], scan_token: 'snapshot'}));
    await h.component.subscriptionCleanup();
    h.modals[0].options.onCancel();
    assert.equal(h.requests.length, 1);
    assert.equal(h.component.subscriptionCleanupModal, null);
});

test('leaving user management closes cleanup and ignores pending scan responses', async () => {
    let resolve;
    const h = harness(() => new Promise(done => { resolve = done; }));
    const pending = h.component.subscriptionCleanup();
    h.component.componentWillUnmount();
    resolve({code: 200, total: 1, data: [{id: 1, email: 'test'}], scan_token: 'snapshot'});
    await pending;
    assert.equal(h.modals.length, 0);
    const opened = harness(() => ({code: 200, total: 1, data: [{id: 1, email: 'test'}], scan_token: 'snapshot'}));
    await opened.component.subscriptionCleanup();
    opened.component.componentWillUnmount();
    assert.equal(opened.modals[0].destroyed, true);
});

test('cleanup scan failures are visible and permit a new scan', async () => {
    for (const response of [new Error('offline'), {code: 403, message: '登录已过期'}, {code: 200, data: []}]) {
        const h = harness(() => { if (response instanceof Error) throw response; return response; });
        await h.component.subscriptionCleanup();
        assert.equal(h.notices[0].type, 'error');
        assert.equal(h.modals.length, 0);
        assert.equal(h.component.subscriptionCleanupPending, false);
        await h.component.subscriptionCleanup();
        assert.equal(h.requests.length, 2);
    }
});

test('a repeated click while scanning does not create another request', async () => {
    let resolve;
    const h = harness(() => new Promise(done => { resolve = done; }));
    const first = h.component.subscriptionCleanup();
    await h.component.subscriptionCleanup();
    assert.equal(h.requests.length, 1);
    resolve({code: 200, total: 0, data: [], scan_token: 'empty-scan'});
    await first;
    assert.equal(h.component.subscriptionCleanupPending, false);
});

test('a truncated cleanup preview states the full number of accounts to delete', async () => {
    const h = harness(() => ({code: 200, total: 250, scan_token: 'snapshot', data:
        Array.from({length: 200}, (_, index) => ({id: index + 1, email: 'user' + index, reason: 'empty'}))
    }));
    await h.component.subscriptionCleanup();
    assert.match(text(h.modals[0].options.content), /仅展示前 20 个，共 250 个用户/);
    assert.match(h.modals[0].options.okText, /全部 250 个/);
});

test('a cleanup deletion failure remains visible and does not report success', async () => {
    for (const result of [new Error('offline'), {code: 422, message: '检测结果已失效，请重新检测'}]) {
        const h = harness((url, params) => {
            if (params.action === 'scan') return {code: 200, total: 1, data: [{id: 1, email: 'test'}], scan_token: 'snapshot'};
            if (result instanceof Error) throw result;
            return result;
        });
        await h.component.subscriptionCleanup();
        await assert.rejects(h.modals[0].options.onOk());
        assert.equal(h.notices[0].type, 'error');
        assert.equal(h.notices.some(notice => notice.type === 'success'), false);
        assert.equal(h.dispatched[0].type, 'user/fetch');
    }
});
