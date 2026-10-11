const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');
const acorn = require('acorn');

const sources = new Map();
function moduleSources(file) {
    if (!sources.has(file)) {
        const source = fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');
        const object = acorn.parse(source, { ecmaVersion: 'latest' }).body[0].expression.arguments[0];
        sources.set(file, new Map(object.properties.map(property => [
            property.key.value ?? property.key.name, source.slice(property.value.start, property.value.end),
        ])));
    }
    return sources.get(file);
}
function elements(node) {
    if (Array.isArray(node)) return node.flatMap(elements);
    return node && typeof node === 'object' ? [node, ...elements(node.children)] : [];
}
const plain = value => JSON.parse(JSON.stringify(value));
const fields = names => Object.fromEntries(names.map(name => [name, { label: name, type: 'input' }]));
const forms = {
    AlipayF2F: fields(['app_id', 'private_key', 'public_key', 'seller_id', 'product_name']),
    EPay: fields(['url', 'pid', 'key', 'type']),
    EPayQrcode: fields(['url', 'pid', 'key', 'type']),
};
const epayConfig = { url: 'https://gateway.example.test', pid: 'merchant-1', key: 'test-key', type: 'alipay' };
function harness(file, { record, deferred = false, methods = Object.keys(forms) } = {}) {
    const actions = [];
    class Component {
        constructor(props) { this.props = props; }
        setState(update, callback) { this.state = Object.assign({}, this.state, update); if (callback) callback(); }
    }
    function Select() {}
    Select.Option = 'SelectOption';
    const modules = {
        q1tI: {
            Component, Fragment: 'Fragment',
            createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
            cloneElement: (element, props) => ({ ...element, props: { ...element.props, ...props } }),
        },
        '/MKj': { c: () => Class => Class },
        p0pE: Object.assign, jehZ: Object.assign,
        '2fM7': { a: Select }, kLXV: { a: 'Modal' }, '5rEg': { a: 'Input' },
    };
    const raw = moduleSources(file), cache = {};
    const context = vm.createContext({ window: { settings: { secure_path: 'test-admin' } } });
    function requireModule(id) {
        if (modules[id]) return modules[id];
        if (id !== 'wd/R' && id !== 'N9RS' && id !== 'eIZb') return { a: 'stub:' + id };
        if (!cache[id]) {
            const module = cache[id] = { exports: {} };
            vm.runInContext('(' + raw.get(id) + ')', context)(module, module.exports, requireModule);
        }
        return cache[id].exports;
    }
    requireModule.r = () => {};
    requireModule.n = value => { const getter = () => value; getter.a = value; return getter; };
    requireModule.d = (target, name, get) => Object.defineProperty(target, name, { get });
    const Page = requireModule('eIZb').default;
    const page = new Page({ payment: { payments: [], fetchLoading: false } });
    const Editor = elements(page.render()).find(node => node.type?.prototype?.onSelectPaymentMethod).type;
    const editor = new Editor({ record, children: { type: 'a', props: {} }, payment: { fetchLoading: false }, dispatch(action) {
        actions.push(action);
        if (action.type === 'payment/getPaymentMethods') action.complete(methods);
        if (action.type === 'payment/getPaymentForm' && !deferred) {
            const form = plain(forms[action.payment]);
            if (action.payment === editor.props.record?.payment) {
                for (const key of Object.keys(form)) {
                    if (Object.hasOwn(editor.props.record.config, key)) form[key].value = editor.props.record.config[key];
                }
            }
            action.complete(form);
        }
    } });
    return {
        editor, actions, requireModule, modules, Select,
        modal: () => elements(editor.render()).find(node => node.type === 'Modal'),
        select: () => elements(editor.render()).find(node => node.type === Select),
        inputs: () => elements(editor.render()).filter(node => node.type === 'Input'),
        saves: () => actions.filter(action => action.type === 'payment/save'),
    };
}

for (const file of ['resources/admin/legacy/umi.js', 'resources/admin/build/super.js', 'resources/admin/build/operations.js']) {
    test(file + ': existing EPay configuration survives reopening and saving', () => {
        const record = { id: 42, name: 'EPay channel', payment: 'EPay', config: epayConfig };
        const h = harness(file, { record });
        h.editor.show();
        assert.equal(h.select().props.value, 'EPay');
        assert.equal(h.actions.at(-1).payment, 'EPay');
        assert.equal(h.actions.at(-1).id, 42);
        assert.deepEqual(plain(h.editor.state.config), epayConfig);
        h.editor.save();
        const saved = plain(h.saves()[0].params);
        assert.equal(saved.payment, 'EPay');
        h.saves()[0].complete();
        h.editor.props.record = saved;
        h.editor.show();
        h.editor.save();
        assert.equal(h.select().props.value, 'EPay');
        assert.deepEqual(plain(h.saves()[1].params.config), epayConfig);
        assert.equal(h.saves()[1].params.payment, 'EPay');
        const reloaded = harness(file, { record: saved });
        reloaded.editor.show();
        assert.equal(reloaded.select().props.value, 'EPay');
    });

    test(file + ': switching from Alipay to EPay saves the new fields and reopens from current props', () => {
        const h = harness(file, { record: { id: 42, payment: 'AlipayF2F', config: { app_id: 'old-app', private_key: 'old-key' } } });
        h.editor.show();
        h.editor.onSelectPaymentMethod('EPay');
        assert.deepEqual(Object.keys(h.editor.state.form), Object.keys(forms.EPay));
        assert.deepEqual(plain(h.editor.state.config), {});
        for (const [key, value] of Object.entries(epayConfig)) h.editor.configOnChange(key, value);
        h.editor.save();
        const saved = plain(h.saves()[0].params);
        assert.equal(saved.payment, 'EPay');
        assert.deepEqual(saved.config, epayConfig);
        h.saves()[0].complete();
        h.editor.props.record = saved;
        h.editor.show();
        assert.equal(h.select().props.value, 'EPay');
        assert.deepEqual(plain(h.editor.state.config), epayConfig);
    });

    test(file + ': cancelling discards unsaved driver and config changes', () => {
        const record = { id: 42, payment: 'EPay', config: { ...epayConfig } };
        const h = harness(file, { record });
        h.editor.show();
        h.editor.configOnChange('key', 'unsaved-secret');
        assert.equal(record.config.key, epayConfig.key);
        h.editor.onSelectPaymentMethod('AlipayF2F');
        h.modal().props.onCancel();
        h.editor.show();
        assert.equal(h.select().props.value, 'EPay');
        assert.deepEqual(plain(h.editor.state.config), epayConfig);
        assert.equal(h.modal().props.destroyOnClose, true);
    });

    test(file + ': new methods use the first driver while existing unlisted drivers remain selected', () => {
        const added = harness(file);
        added.editor.show();
        assert.equal(added.select().props.value, 'AlipayF2F');
        assert.equal(added.actions.at(-1).id, undefined);
        const existing = harness(file, { record: { id: 42, payment: 'EPay', config: epayConfig }, methods: ['AlipayF2F'] });
        existing.editor.show();
        assert.equal(existing.select().props.value, 'EPay');
        assert.ok(existing.editor.state.paymentMethods.includes('EPay'));
    });

    test(file + ': shared field names are cleared when changing drivers', () => {
        const h = harness(file, { record: { id: 42, payment: 'EPay', config: epayConfig } });
        h.editor.show();
        h.editor.onSelectPaymentMethod('EPayQrcode');
        assert.equal(h.select().props.value, 'EPayQrcode');
        assert.deepEqual(plain(h.editor.state.config), {});
        const inputs = h.inputs().filter(node => Object.hasOwn(node.props, 'value'));
        assert.equal(inputs.length, 4);
        assert.ok(inputs.every(node => node.props.value === ''));
    });

    test(file + ': pending, stale and failed form requests cannot overwrite or save another driver', () => {
        const h = harness(file, { record: { id: 42, payment: 'AlipayF2F', config: {} }, deferred: true });
        h.editor.show();
        const original = h.actions.at(-1);
        h.editor.onSelectPaymentMethod('EPay');
        const epay = h.actions.at(-1);
        h.editor.onSelectPaymentMethod('EPayQrcode');
        const latest = h.actions.at(-1);
        h.editor.save();
        assert.equal(h.saves().length, 0);
        assert.equal(h.modal().props.okButtonProps.disabled, true);
        latest.complete(forms.EPayQrcode);
        epay.complete(forms.EPay);
        original.failure();
        assert.equal(h.select().props.value, 'EPayQrcode');
        assert.equal(h.editor.state.formReady, true);
        h.editor.save();
        assert.equal(h.saves()[0].params.payment, 'EPayQrcode');
        h.editor.onSelectPaymentMethod('EPay');
        h.actions.at(-1).failure();
        assert.equal(h.editor.state.formLoading, false);
        assert.equal(h.modal().props.okButtonProps.disabled, true);
        h.editor.save();
        assert.equal(h.saves().length, 1);
        h.editor.onSelectPaymentMethod('EPay');
        h.actions.at(-1).complete(forms.EPay);
        assert.equal(h.modal().props.okButtonProps.disabled, false);
    });

    test(file + ': a late response from a cancelled edit cannot replace the reopened form', () => {
        const h = harness(file, { record: { id: 42, payment: 'AlipayF2F', config: {} }, deferred: true });
        h.editor.show();
        h.editor.onSelectPaymentMethod('EPay');
        const cancelled = h.actions.at(-1);
        h.modal().props.onCancel();
        h.editor.show();
        h.actions.at(-1).complete(forms.AlipayF2F);
        cancelled.complete(forms.EPay);
        assert.equal(h.select().props.value, 'AlipayF2F');
        assert.deepEqual(Object.keys(h.editor.state.form), Object.keys(forms.AlipayF2F));
    });

    test(file + ': form load errors reach the editor through the payment model', async () => {
        const h = harness(file);
        h.modules.t3Un = { b: async () => ({ code: 422, message: 'driver unavailable' }) };
        const model = h.requireModule('N9RS').default;
        let failed = false;
        const iterator = model.effects.getPaymentForm({ payment: 'EPay', complete: () => assert.fail('must not succeed'), failure: () => { failed = true; } });
        let step = iterator.next();
        while (!step.done) step = iterator.next(await step.value);
        assert.equal(failed, true);
    });
}
