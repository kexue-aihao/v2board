/* Reproducible role bundles from the preserved Umi modules. No business bundle
 * lives under public/. AST edits fail closed when the upstream shape changes. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const acorn = require('acorn');
const walk = require('acorn-walk');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8').replace(/\r\n/g, '\n');
const parse = code => acorn.parse(code, {ecmaVersion: 'latest', sourceType: 'script'});
const source = read('resources/admin/legacy/umi.js');
const mainAst = parse(source);
const mainObject = mainAst.body[0].expression.arguments[0];
const originals = {};
function capture(object, code) {
    for (const property of object.properties) {
        if (property.type !== 'Property' || property.value.type !== 'FunctionExpression') throw new Error('Unexpected webpack module');
        originals[String(property.key.value ?? property.key.name)] = code.slice(property.value.start, property.value.end);
    }
}
for (const file of ['vendors.async.js', 'components.async.js']) {
    const code = read('resources/admin/legacy/' + file);
    capture(parse(code).body[0].expression.arguments[0].elements[1], code);
}
capture(mainObject, source);
const propertyName = p => p.key && (p.key.name ?? p.key.value);
function edit(code, visitor) {
    const wrapped = '(' + code + ')', edits = [];
    visitor(parse(wrapped), (node, text) => edits.push({start: node.start - 1, end: node.end - 1, text}), wrapped);
    // A containing edit owns its children.
    const unique = [...new Map(edits.map(e => [e.start + ':' + e.end, e])).values()];
    const accepted = unique.filter(e => !unique.some(p => p !== e && p.start <= e.start && p.end >= e.end && (p.start < e.start || p.end > e.end)));
    accepted.sort((a, b) => b.start - a.start);
    for (const item of accepted) code = code.slice(0, item.start) + item.text + code.slice(item.end);
    return code;
}
const pageRoles = {
    '/dashboard': ['super'], '/config/system': ['super', 'operations'], '/config/payment': ['super', 'operations'],
    '/config/theme': ['super', 'operations'], '/server/group': ['super', 'operations'], '/server/manage': ['super', 'operations'],
    '/server/route': ['super', 'operations'], '/rate': ['super', 'operations'], '/risk/trace': ['super', 'operations'],
    '/risk/gateway': ['super', 'operations'], '/risk/shared-ip': ['super', 'operations'],
    '/order': ['super', 'finance'], '/plan': ['super', 'marketing'], '/coupon': ['super', 'marketing'],
    '/giftcard': ['super', 'marketing'], '/reward': ['super', 'marketing'], '/ticket': ['super', 'support'],
    '/ticket/:ticket_id': ['super', 'support'], '/user': ['super'], '/notice': ['super'], '/knowledge': ['super'],
    '/reseller': ['super'], '/external': ['super'], '/queue': ['super'], '/login': ['guest'],
};
const roleModels = {
    guest: ['passport', 'user', 'layout'],
    super: null,
    operations: ['user', 'layout', 'config', 'payment', 'theme', 'serverGroup', 'serverManage', 'serverRoute',
        'serverHysteria', 'serverTuic', 'serverShadowsocks', 'serverTrojan', 'serverVless', 'serverVmess', 'serverAnyTLS', 'serverV2node'],
    finance: ['user', 'layout', 'order', 'plan'],
    support: ['user', 'layout'],
    marketing: ['user', 'layout', 'plan', 'coupon', 'giftcard', 'serverGroup', 'config'],
};
function modelEffects(code, names, replacements = {}) {
    code = edit(code, (ast, replace, wrapped) => walk.simple(ast, {
        Property(node) {
            if (propertyName(node) !== 'effects' || node.value.type !== 'ObjectExpression') return;
            replace(node.value, '{' + node.value.properties.filter(p => names.includes(propertyName(p)))
                .map(p => wrapped.slice(p.start, p.end)).join(',') + '}');
        },
    }));
    for (const [before, after] of Object.entries(replacements)) code = code.split(before).join(after);
    return code;
}
function selfModel() {
    return `function(e,t,n){n.r(t);var api=n("t3Un"),history=n("3a4m"),utils=n("yWgo");
      t.default={name:"user",state:{userInfo:{},user:{},fetchLoading:false},reducers:{setState:function(s,a){return Object.assign({},s,a.payload)}},effects:{
        *getUserInfo(a,{put}){if(!utils.c())return;var r=yield api.a('/'+window.settings.secure_path+'/user/info');if(r.code===200)yield put({type:'setState',payload:{userInfo:r.data}})},
        *checkLogin(a,{put}){if(!utils.c())return;var r=yield api.a('/'+window.settings.secure_path+'/user/checkLogin');if(r.code===200&&r.data.is_admin){yield put({type:'getUserInfo'});history.push(window.adminSecurity.landing)}}
      }};}`;
}
function compile(role) {
    const modules = Object.assign(Object.create(null), originals);
    const routes = [];
    const routeModuleIds = {};
    modules.i4x8 = edit(modules.i4x8, (ast, replace, wrapped) => walk.simple(ast, {
        VariableDeclarator(node) {
            if (node.id.name !== 'u' || !node.init || node.init.type !== 'ArrayExpression') return;
            for (const item of node.init.elements) {
                const route = item.properties.find(p => propertyName(p) === 'path').value.value;
                const component = item.properties.find(p => propertyName(p) === 'component');
                if (component) routeModuleIds[route] = component.value.object.arguments[0].value;
                if (!(pageRoles[route] || []).includes(role)) continue;
                if (role === 'support') routes.push(`{path:${JSON.stringify(route)},exact:true,component:n("securitySupport").default}`);
                else routes.push(wrapped.slice(item.start, item.end));
            }
            if (role !== 'guest') routes.push('{path:"/security/account",exact:true,component:n("securityAccount").default}');
            if (role === 'super') {
                routes.push('{path:"/security/administrators",exact:true,component:n("securityAdministrators").default}');
                routes.push('{path:"/security/audit",exact:true,component:n("securityAudit").default}');
            }
            routes.push('{path:"/",redirect:window.adminSecurity.landing}');
            replace(node.init, '[' + routes.join(',') + ']');
        },
    }));
    if (!routes.length) throw new Error('Route extraction failed');
    const models = roleModels[role];
    if (models) modules.xg5P = edit(modules.xg5P, (ast, replace) => walk.simple(ast, {
        CallExpression(node) {
            if (node.callee.type !== 'MemberExpression' || node.callee.property.name !== 'model') return;
            const config = node.arguments[0].arguments[0];
            const namespace = config.properties.find(p => propertyName(p) === 'namespace').value.value;
            if (!models.includes(namespace)) replace(node, 'void 0');
        },
    }));
    modules.Bl7J = edit(modules.Bl7J, (ast, replace) => walk.simple(ast, {
        Property(node) { if (propertyName(node) === 'nav' && node.value.type === 'ArrayExpression') replace(node.value, 'window.adminSecurity.menus'); },
    }));
    if (role !== 'super') modules.hlQx = selfModel();
    if (role === 'finance') modules.GmDa = modelEffects(modules.GmDa, ['fetch'], {'/plan/fetch': '/security/options/plans'});
    if (role === 'finance') modules.pi3A = edit(modules.pi3A, (ast, replace, wrapped) => walk.simple(ast, {
        MethodDefinition(node) {
            if (propertyName(node) === 'getOrderInfo') replace(node, `async getOrderInfo(){this.onShow();try{var order=await window.adminRequest('/order/detail',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:this.props.orderId})});this.setState({order:order,user:order.user||{},invite_user:order.invite_user||{}})}catch(error){window.alert(error.message)}}`);
            if (propertyName(node) === 'jumpUserFilter') replace(node, 'jumpUserFilter(){}');
        },
        CallExpression(node) {
            if (node.callee.type !== 'MemberExpression' || node.callee.property.name !== 'createElement' || !node.arguments[1] || node.arguments[1].type !== 'ObjectExpression') return;
            if (!node.arguments[1].properties.some(p => propertyName(p) === 'onClick' && wrapped.slice(p.start, p.end).includes('jumpUserFilter'))) return;
            replace(node.arguments[0], '"span"'); replace(node.arguments[1], '{}');
        },
    }));
    if (role === 'marketing') modules.ZlA7 = modelEffects(modules.ZlA7, ['fetch'], {'/server/group/fetch': '/security/options/groups'});
    if (role === 'marketing') modules['6lKK'] = `function(e,t,n){n.r(t);var api=n("t3Un");t.default={name:"config",state:{site:{currency:"",currency_symbol:""}},reducers:{setState:function(s,a){return Object.assign({},s,a.payload)}},effects:{*fetch(a,{put}){var r=yield api.a('/'+window.settings.secure_path+'/security/options/plan-config');if(r.code===200)yield put({type:'setState',payload:r.data})}}};}`;
    // Login must rebootstrap resources after obtaining a different identity.
    modules.yWgo = modules.yWgo.replace('return window.localStorage.setItem("authorization", e)',
        'window.localStorage.setItem("authorization", e); return window.location.reload()')
        .replace('return window.localStorage.removeItem("authorization")', 'return window.adminLogout()');
    // Remove privileged actions at build time, including their labels and dependencies.
    if (role !== 'super') {
        for (const id of Object.keys(modules)) {
            if (!originals[id] || !/user\/addFilter|admin_2fa_force_enable/.test(modules[id])) continue;
            modules[id] = edit(modules[id], (ast, replace, wrapped) => walk.ancestor(ast, {
                Property(node, ancestors) {
                    if (propertyName(node) === 'onClick' && wrapped.slice(node.start, node.end).includes('user/addFilter')) replace(node.value, 'function(){}');
                },
                Literal(node, ancestors) {
                    if (node.value !== 'admin_2fa_force_enable') return;
                    const render = ancestors.slice().reverse().find(a => a.type === 'CallExpression' && a.callee.type === 'MemberExpression'
                        && a.callee.property.name === 'createElement' && a.arguments[1] && a.arguments[1].type === 'ObjectExpression'
                        && a.arguments[1].properties.some(p => propertyName(p) === 'title'));
                    if (render) replace(render, 'null');
                },
            }));
        }
        for (const id of ['CgOb', 'X0q5']) modules[id] = 'function(e,t,n){n.d(t,"a",function(){return function(){return null}})}';
    }
    for (const id of Object.keys(modules)) {
        if (!modules[id].includes('formChange("is_admin"') && !modules[id].includes('formChange("is_staff"')) continue;
        modules[id] = edit(modules[id], (ast, replace, wrapped) => walk.ancestor(ast, {
            CallExpression(node, ancestors) {
                if (node.callee.type !== 'MemberExpression' || node.callee.property.name !== 'formChange'
                    || !node.arguments[0] || !['is_admin', 'is_staff'].includes(node.arguments[0].value)) return;
                const row = ancestors.slice().reverse().find(a => a.type === 'CallExpression' && a.callee.type === 'MemberExpression' && a.callee.property.name === 'createElement'
                    && a.arguments[1] && a.arguments[1].type === 'ObjectExpression' && a.arguments[1].properties.some(p => ['label', 'title'].includes(propertyName(p)) || (propertyName(p) === 'className' && p.value.value === 'form-group')));
                if (!row) throw new Error('Cannot remove legacy administrator toggle in ' + id);
                replace(row, 'null');
            },
        }));
    }
    for (const [id, file] of Object.entries({securitySupport: 'support', securityAccount: 'account', securityAdministrators: 'administrators', securityAudit: 'audit'})) {
        modules[id] = read('scripts/admin-security/' + file + '.js').trim();
    }
    // Include only reachable modules. Literal references also cover webpack's
    // generated dynamic contexts (e.g. moment locale maps).
    const reachable = new Set();
    function visit(id) {
        id = String(id);
        if (reachable.has(id)) return;
        if (!modules[id]) throw new Error('Missing module ' + id);
        reachable.add(id);
        let ast;
        try { ast = parse('(' + modules[id] + ')'); } catch (error) { error.message = 'Module ' + id + ': ' + error.message; throw error; }
        walk.simple(ast, {
            Literal(node) { if (typeof node.value === 'string' && modules[node.value]) visit(node.value); },
            CallExpression(node) {
                if (node.callee.type === 'Identifier' && node.arguments.length === 1 && node.arguments[0].type === 'Literal'
                    && typeof node.arguments[0].value === 'number' && modules[String(node.arguments[0].value)]) visit(node.arguments[0].value);
            },
        });
    }
    visit('1');
    for (const [route, id] of Object.entries(routeModuleIds)) {
        if (route === '/') continue;
        if (!(pageRoles[route] || []).includes(role) && reachable.has(id)) throw new Error(role + ' includes forbidden page ' + route + ' (' + id + ')');
    }
    let prefix = source.slice(0, mainObject.start + 1).replace('1: 0', '1: 0, 2: 0, 0: 0');
    let suffix = source.slice(mainObject.end - 1);
    if (role !== 'guest') suffix = '});\n';
    else suffix = suffix.replace("window.location.hash = '/' + String(redirect || 'dashboard').replace(/^\\//, '');", 'window.location.reload();');
    const code = prefix + [...reachable].sort().map(id => JSON.stringify(id) + ':' + modules[id]).join(',\n') + suffix;
    parse(code);
    return {code, modules: [...reachable].sort(), routes: Object.keys(pageRoles).filter(route => pageRoles[route].includes(role))};
}
function build() {
    const out = path.join(root, 'resources/admin/build');
    fs.mkdirSync(out, {recursive: true});
    const sourceFiles = ['resources/admin/legacy/umi.js', 'resources/admin/legacy/vendors.async.js', 'resources/admin/legacy/components.async.js',
        'scripts/build-admin-security.cjs', ...['support', 'account', 'administrators', 'audit'].map(name => 'scripts/admin-security/' + name + '.js')];
    const manifest = {version: 1, sources: Object.fromEntries(sourceFiles.map(file => [file, crypto.createHash('sha256').update(read(file).replace(/\r\n/g, '\n')).digest('hex')])), roles: {}};
    for (const role of Object.keys(roleModels)) {
        const result = compile(role);
        const destination = role === 'guest' ? path.join(root, 'public/assets/admin/login.js') : path.join(out, role + '.js');
        fs.writeFileSync(destination, result.code);
        manifest.roles[role] = {sha256: crypto.createHash('sha256').update(result.code).digest('hex'), modules: result.modules, routes: result.routes};
        console.log(role + ': ' + result.modules.length + ' modules, ' + Buffer.byteLength(result.code) + ' bytes');
    }
    fs.writeFileSync(path.join(out, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
}
if (require.main === module) build();
module.exports = {compile, build, pageRoles};
