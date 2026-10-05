'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const roles = {
    super: ['/security/audit', ['/dashboard', '/config/system', '/config/payment', '/config/theme', '/server/manage', '/server/group', '/server/route', '/plan', '/order', '/coupon', '/giftcard', '/user', '/notice', '/ticket', '/knowledge', '/reseller', '/external', '/reward', '/risk/trace', '/risk/gateway', '/risk/shared-ip', '/queue', '/security/audit', '/security/account']],
    operations: ['/server/manage', ['/config/system', '/config/payment', '/config/theme', '/server/manage', '/server/group', '/server/route', '/risk/trace', '/risk/gateway', '/risk/shared-ip', '/security/account']],
    finance: ['/order', ['/order', '/security/account']], support: ['/ticket', ['/ticket', '/security/account']],
    marketing: ['/plan', ['/plan', '/coupon', '/giftcard', '/reward', '/security/account']],
};
const labels = {
    '/dashboard': '仪表盘', '/config/system': '系统配置', '/config/payment': '支付配置', '/config/theme': '主题配置',
    '/server/manage': '节点管理', '/server/group': '权限组管理', '/server/route': '路由管理', '/order': '订单管理',
    '/plan': '订阅管理', '/coupon': '优惠券管理', '/giftcard': '礼品卡管理', '/user': '用户管理', '/notice': '公告管理',
    '/ticket': '工单管理', '/knowledge': '知识库管理', '/reseller': '倒卖商管理', '/external': '外部订阅源',
    '/reward': '签到与娱乐', '/risk/trace': '订阅溯源', '/risk/gateway': '订阅清洗网关', '/risk/shared-ip': '多账号同 IP',
    '/queue': '队列监控', '/security/account': '账号安全', '/security/audit': '安全审计',
};
const startupMarkup = fs.readFileSync(path.join(root, 'resources/views/admin.blade.php'), 'utf8').match(/<div id="admin-startup"[\s\S]*?<div id="root"[^>]*><\/div>/)[0];
const html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/admin/umi.css"><link rel="stylesheet" href="/assets/admin/components.chunk.css"><link rel="stylesheet" href="/assets/admin/custom.css"></head><body>' + startupMarkup + '<script>window.routerBase="/";window.settings={secure_path:"test",title:"4A Test",version:"test",theme:{sidebar:"dark",header:"light"},admin_asset_version:"test"};</script><script src="/assets/admin/security-loader.js"></script></body></html>';
const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/test') { res.setHeader('Content-Type', 'text/html; charset=utf-8'); return res.end(html); }
    const target = path.resolve(root, 'public', '.' + url.pathname);
    if (!target.startsWith(path.join(root, 'public') + path.sep) || !fs.existsSync(target) || !fs.statSync(target).isFile()) { res.statusCode = 404; return res.end(); }
    res.setHeader('Content-Type', target.endsWith('.js') ? 'application/javascript' : target.endsWith('.css') ? 'text/css' : 'application/octet-stream'); res.end(fs.readFileSync(target));
});
(async () => {
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const browser = await chromium.launch({headless: true});
    try {
        for (const role of (process.env.ADMIN_TEST_ROLE ? [process.env.ADMIN_TEST_ROLE] : ['guest', 'guest2fa', ...Object.keys(roles)])) {
            const isGuest = role === 'guest' || role === 'guest2fa';
            const context = await browser.newContext({viewport: {width: role === 'support' ? 390 : 1440, height: 1000}, reducedMotion: role === 'support' ? 'reduce' : 'no-preference'});
            const page = await context.newPage(), errors = [], calls = [];
            let releaseBootstrap, releaseAsset, notifyBootstrap, notifyAsset;
            const bootstrapGate = new Promise(resolve => { releaseBootstrap = resolve; });
            const assetGate = new Promise(resolve => { releaseAsset = resolve; });
            const bootstrapRequested = new Promise(resolve => { notifyBootstrap = resolve; });
            const assetRequested = new Promise(resolve => { notifyAsset = resolve; });
            const edits = [], userDefaults = {transfer_enable: 107374182400, u: 0, d: 0, total_used: 0, balance: 0, commission_balance: 0, device_limit: 6, expired_at: null, plan_id: null, banned: 0, commission_type: 0, commission_rate: null, discount: null, speed_limit: null, is_staff: 0, admin_version: 1, created_at: 1700000000, updated_at: 1700000000, alive_ip: 0, subscribe_url: 'https://example.test/subscribe', remarks: ''};
            const users = [Object.assign({}, userDefaults, {id: 6, email: 'member@example.test', is_admin: 0, admin_role: null}), Object.assign({}, userDefaults, {id: 1, email: 'founder@example.test', is_admin: 1, admin_role: null})];
            const order = {id: 10, trade_no: 'TEST-ORDER-123', user_id: 6, invite_user_id: 8, plan_id: 1, plan_name: '测试套餐', period: 'month_price', type: 1, status: 3, total_amount: 1000, balance_amount: 0, discount_amount: 0, refund_amount: 0, surplus_amount: 0, commission_balance: 100, commission_status: 0, created_at: 1700000000, updated_at: 1700000000, commission_log: [], user: {id: 6, email: 'buyer@example.test'}, invite_user: {id: 8, email: 'inviter@example.test'}};
            page.on('pageerror', error => { errors.push(error.message); console.error(role, error.message); });
            if (process.env.ADMIN_BROWSER_DEBUG) page.on('console', message => { if (message.type() === 'error') console.error(message.text()); });
            if (!isGuest) await page.addInitScript(() => localStorage.setItem('authorization', 'fixture'));
            await page.route('**/api/v1/test/**', async route => {
                const url = new URL(route.request().url()), endpoint = url.pathname.replace('/api/v1/test', ''); calls.push(endpoint);
                if (process.env.ADMIN_BROWSER_DEBUG) console.log(role, route.request().method(), url.pathname + url.search);
                const currentRole = isGuest ? 'operations' : role;
                let body = {data: [], total: 0};
                if (endpoint === '/security/bootstrap') {
                    notifyBootstrap(); await bootstrapGate;
                    body.data = {role: currentRole, version: 1, role_label: currentRole, menus: roles[currentRole][1].map(href => ({href, title: labels[href] || href, type: 'item'})), landing: roles[currentRole][0], debug_exempt: true};
                }
                else if (endpoint === '/security/asset') {
                    notifyAsset(); await assetGate;
                    return route.fulfill({contentType: 'application/javascript', body: fs.readFileSync(path.join(root, 'resources/admin/build', currentRole + '.js'), 'utf8')});
                }
                else if (endpoint === '/user/info') body.data = {email: 'admin@example.test'};
                else if (endpoint === '/user/checkLogin') body.data = {is_login: true, is_admin: true};
                else if (endpoint === '/passport/auth/login') body.data = role === 'guest2fa' ? {two_factor_required: true, challenge: 'fixture-challenge'} : {auth_data: 'logged-in', is_admin: true};
                else if (endpoint === '/passport/auth/verify2fa') body.data = {auth_data: 'logged-in', is_admin: true};
                else if (endpoint === '/security/audit') body = {data: [{id: 1, actor_id: 1, role: 'super', role_label: '超级管理员', event: 'request.finish', description: '修改用户资料（ID 6）', result: 'success', result_label: '成功', created_at: 1700000000, payload: JSON.stringify({description: '修改用户资料（ID 6）'})}], total: 1};
                else if (endpoint === '/user/fetch') body = {data: users, total: users.length};
                else if (endpoint === '/user/getUserInfoById') body.data = users.find(user => Number(user.id) === Number(url.searchParams.get('id')));
                else if (endpoint === '/user/update') {
                    const data = route.request().postDataJSON(), user = users.find(user => Number(user.id) === Number(data.id));
                    edits.push(data);
                    // Form posts carry strings; the real model returns numbers
                    // and nullable fields after Laravel validation and casting.
                    for (const [key, value] of Object.entries(data)) user[key] = typeof user[key] === 'number' ? Number(value) : value === '' && user[key] === null ? null : value;
                    body.data = true;
                }
                else if (endpoint === '/2fa/status') body.data = {enabled: false};
                else if (endpoint === '/2fa/setup') body.data = {manual_key: 'TEST-SETUP-KEY'};
                else if (endpoint === '/2fa/confirm') body.data = {recovery_codes: ['TEST-RECOVERY-ONE', 'TEST-RECOVERY-TWO']};
                else if (endpoint === '/order/fetch') body = {data: [order], total: 1};
                else if (endpoint === '/order/detail') body.data = order;
                else if (endpoint === '/security/options/plans') body.data = [{id: 1, name: '测试套餐', month_price: 1000}];
                else if (endpoint === '/security/options/groups') body.data = [{id: 1, name: '测试权限组'}];
                else if (endpoint === '/security/options/plan-config') body.data = {site: {currency: 'CNY', currency_symbol: '¥'}};
                else if (endpoint === '/security/account/sessions') body.data = {};
                else if (endpoint === '/ticket/fetch') body = url.searchParams.has('id') ? {data: {id: 2, subject: '工单测试', message: [{id: 1, message: '需要帮助', created_at: 1, is_me: false}]}} : {data: [{id: 2, subject: '工单测试', status: 0}], total: 1};
                await route.fulfill({contentType: 'application/json', body: JSON.stringify(body)});
            });
            await page.goto('http://127.0.0.1:' + server.address().port + '/test#' + (isGuest ? '/login' : roles[role][0]));
            if (isGuest) {
                await page.locator('input[type=password]').waitFor();
                await page.locator('#admin-startup').waitFor({state: 'detached'});
                await page.locator('input[type=text]').fill('admin@example.test'); await page.locator('input[type=password]').fill('testpassword');
                await page.getByRole('button', {name: '登入'}).click();
                if (role === 'guest2fa') {
                    await page.getByPlaceholder('000000 或 XXXX-XXXX-XXXX', {exact: true}).fill('123456');
                    await page.getByRole('button', {name: '验证并继续', exact: true}).click();
                }
            }
            await bootstrapRequested;
            const startup = page.locator('#admin-startup');
            await startup.waitFor();
            assert.equal(await page.locator('#admin-startup-title').textContent(), '正在加载权限');
            assert.equal(await page.locator('#root').getAttribute('aria-busy'), 'true');
            assert.equal(await page.locator('.admin-startup__indicator').evaluate(el => getComputedStyle(el, '::before').animationName), role === 'support' ? 'none' : 'admin-startup-spin');
            if (process.env.ADMIN_SCREENSHOT_DIR && ['super', 'support'].includes(role)) {
                fs.mkdirSync(process.env.ADMIN_SCREENSHOT_DIR, {recursive: true});
                await page.screenshot({path: path.join(process.env.ADMIN_SCREENSHOT_DIR, role === 'super' ? 'startup-permissions.png' : 'startup-permissions-mobile.png')});
            }
            releaseBootstrap();
            await assetRequested;
            assert.equal(await page.locator('#admin-startup-title').textContent(), '正在进入管理后台');
            assert.ok(await startup.isVisible(), 'loading remains visible while role script is pending');
            releaseAsset();
            await page.locator('#sidebar').waitFor();
            await startup.waitFor({state: 'detached'});
            assert.equal(await page.locator('#root').getAttribute('aria-busy'), null);
            if (!isGuest) {
                const links = await page.locator('#sidebar .nav-main-link-name').allTextContents();
                assert.deepEqual(links, roles[role][1].map(href => labels[href] || href), role + ' menus');
                await page.evaluate(() => document.fonts.ready);
                const icons = await page.locator('#sidebar .nav-main-link').evaluateAll(links => links.map(link => {
                    const icons = link.querySelectorAll('.nav-main-link-icon'), icon = icons[0];
                    return {title: link.textContent, count: icons.length, tag: icon && icon.tagName,
                        font: icon && getComputedStyle(icon, '::before').fontFamily,
                        content: icon && getComputedStyle(icon, '::before').content};
                }));
                for (const icon of icons) {
                    assert.equal(icon.count, 1, role + ' ' + icon.title + ' has one menu icon');
                    assert.equal(icon.tag, 'I');
                    assert.match(icon.font, /simple-line-icons/i);
                    assert.ok(icon.content && !['none', 'normal', '""'].includes(icon.content), icon.title + ' has an icon glyph');
                }
                assert.equal(await page.locator('#sidebar .nav-main-link.active .nav-main-link-name').textContent(), labels[roles[role][0]], role + ' active menu');
                if (role === 'super') {
                    await page.getByRole('cell', {name: '修改用户资料（ID 6）', exact: true}).waitFor();
                    assert.equal(await page.getByRole('cell', {name: '成功', exact: true}).count(), 1);
                    await page.getByLabel('操作内容', {exact: true}).fill('修改用户资料');
                    await Promise.all([page.waitForResponse(response => new URL(response.url()).searchParams.get('keyword') === '修改用户资料'), page.getByRole('button', {name: '查询', exact: true}).click()]);
                    if (process.env.ADMIN_SCREENSHOT_DIR) {
                        fs.mkdirSync(process.env.ADMIN_SCREENSHOT_DIR, {recursive: true});
                        await page.screenshot({path: path.join(process.env.ADMIN_SCREENSHOT_DIR, 'audit-chinese.png'), fullPage: true});
                    }
                    await page.evaluate(() => { location.hash = '/user'; });
                    async function editUser(email) {
                        await page.getByText(email, {exact: true}).first().waitFor();
                        // Ant Table renders the action column again in its fixed
                        // right pane; the copy beside the email is hidden.
                        await page.locator('tr a.ant-dropdown-trigger:visible').nth(users.findIndex(user => user.email === email)).click();
                        await page.locator('.ant-dropdown:visible').getByText('编辑', {exact: true}).click();
                        await page.locator('.ant-drawer-open select[aria-label="管理员身份"]').waitFor({timeout: 10000});
                    }
                    await editUser('member@example.test');
                    const roleField = page.locator('.ant-drawer-open select[aria-label="管理员身份"]');
                    assert.deepEqual(await roleField.locator('option').allTextContents(), ['普通用户', '运维管理员', '财务管理员', '客服管理员', '运营管理员']);
                    assert.equal(await page.getByText('是否管理员', {exact: true}).count(), 0);
                    assert.equal(await page.getByText('是否员工', {exact: true}).count(), 0);
                    await roleField.selectOption('finance');
                    if (process.env.ADMIN_SCREENSHOT_DIR) {
                        await roleField.scrollIntoViewIfNeeded();
                        await page.screenshot({path: path.join(process.env.ADMIN_SCREENSHOT_DIR, 'user-role-editor.png'), fullPage: true});
                    }
                    // Ant Design inserts a space between two Chinese characters.
                    await Promise.all([page.waitForResponse(response => response.url().endsWith('/user/update')), page.locator('.ant-drawer-open').getByRole('button', {name: /^提\s*交$/}).click()]);
                    assert.equal(edits[0].admin_role, 'finance');
                    for (const field of ['is_admin', 'is_staff', 'admin_version']) assert.ok(!Object.hasOwn(edits[0], field), 'editor does not send ' + field);
                    await roleField.waitFor({state: 'hidden'});
                    await editUser('member@example.test');
                    assert.equal(await roleField.inputValue(), 'finance', 'saved identity is restored when reopening');
                    await page.setViewportSize({width: 390, height: 1000});
                    await roleField.scrollIntoViewIfNeeded();
                    if (process.env.ADMIN_SCREENSHOT_DIR) await page.screenshot({path: path.join(process.env.ADMIN_SCREENSHOT_DIR, 'user-role-editor-mobile.png'), fullPage: true});
                    await page.locator('.ant-drawer-open').getByRole('button', {name: /^取\s*消$/}).click();
                    await roleField.waitFor({state: 'hidden'});
                    await page.setViewportSize({width: 1440, height: 1000});
                    await editUser('founder@example.test');
                    assert.equal(await roleField.isDisabled(), true);
                    assert.equal(await roleField.inputValue(), 'super');
                    assert.deepEqual(await roleField.locator('option').allTextContents(), ['超级管理员']);
                    await Promise.all([page.waitForResponse(response => response.url().endsWith('/user/update')), page.locator('.ant-drawer-open').getByRole('button', {name: /^提\s*交$/}).click()]);
                    assert.ok(!Object.hasOwn(edits[1], 'admin_role'), 'founder edits preserve the protected identity');
                    await roleField.waitFor({state: 'hidden'});
                }
                if (role === 'support') {
                    await page.getByRole('link', {name: '阅读与回复'}).click(); await page.locator('#support-reply').fill('已收到，我们会处理。');
                    await Promise.all([page.waitForResponse(r => r.url().endsWith('/ticket/reply')), page.getByRole('button', {name: '回复工单', exact: true}).click()]);
                    assert.equal(await page.getByText('关闭工单', {exact: true}).count(), 0);
                    await page.getByRole('button', {name: '返回列表', exact: true}).click();
                    await page.getByRole('link', {name: '阅读与回复'}).waitFor();
                }
                if (role === 'finance') {
                    await page.getByRole('link', {name: 'TES...123', exact: true}).click();
                    await page.getByText('buyer@example.test', {exact: true}).waitFor();
                    await page.getByText('inviter@example.test', {exact: true}).waitFor();
                    assert.ok(!calls.includes('/user/getUserInfoById'), 'finance detail uses only the order identity response');
                    await page.locator('.ant-modal-close').click();
                }
                if (role === 'marketing') {
                    await page.getByRole('button', {name: '添加订阅'}).click();
                    await page.getByText('新建订阅', {exact: true}).waitFor();
                    assert.ok(calls.includes('/security/options/groups'));
                    assert.ok(calls.includes('/security/options/plan-config'));
                    assert.ok(!calls.includes('/config/fetch'));
                    assert.ok(!calls.includes('/server/group/fetch'));
                    await page.locator('.ant-drawer-close').click();
                }
                if (process.env.ADMIN_SCREENSHOT_DIR) {
                    fs.mkdirSync(process.env.ADMIN_SCREENSHOT_DIR, {recursive: true});
                    await page.screenshot({path: path.join(process.env.ADMIN_SCREENSHOT_DIR, role + '.png'), fullPage: true});
                }
                await page.evaluate(() => { location.hash = '/security/account'; });
                await page.getByRole('heading', {name: '修改密码'}).waitFor();
                assert.equal(await page.locator('#sidebar .nav-main-link.active .nav-main-link-name').textContent(), '账号安全', role + ' active menu after navigation');
                if (role !== 'super') {
                    await page.evaluate(() => { location.hash = '/user'; });
                    await page.waitForFunction(expected => location.hash === '#' + expected, roles[role][0]);
                }
                if (role === 'support') {
                    await page.evaluate(() => { location.hash = '/security/account'; });
                    await page.getByRole('button', {name: '开始绑定验证器'}).click();
                    await page.getByText('TEST-SETUP-KEY', {exact: true}).waitFor();
                    await page.getByLabel('验证器动态码', {exact: true}).fill('123456');
                    const countBefore = calls.filter(p => p === '/2fa/status').length;
                    await page.getByRole('button', {name: '确认绑定', exact: true}).click();
                    await page.getByText('TEST-RECOVERY-ONE', {exact: false}).waitFor();
                    assert.equal(calls.filter(p => p === '/2fa/status').length, countBefore, 'revoked session is not reused while recovery codes are visible');
                    assert.equal(await page.evaluate(() => window.adminSecurityRecoveryPending), true);
                    await page.getByRole('button', {name: '已保存，重新登录'}).waitFor();
                }
                if (role !== 'super') assert.ok(!calls.includes('/user/fetch'), role + ' does not fetch user administration');
            }
            assert.deepEqual(errors, [], role + ' browser errors');
            console.log(role + ': browser passed (' + calls.length + ' requests)');
            await context.close();
        }
        if (!process.env.ADMIN_TEST_ROLE) for (const scenario of ['bootstrap-failure', 'asset-failure', 'expired-session']) {
            const context = await browser.newContext(), page = await context.newPage(), calls = [], errors = [];
            await page.addInitScript(() => localStorage.setItem('authorization', 'fixture'));
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/api/v1/test/**', async route => {
                const endpoint = new URL(route.request().url()).pathname.replace('/api/v1/test', ''); calls.push(endpoint);
                if ((scenario === 'bootstrap-failure' && endpoint === '/security/bootstrap') || (scenario === 'asset-failure' && endpoint === '/security/asset')) {
                    return route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({message: '后台加载失败，请刷新重试'})});
                }
                if (scenario === 'expired-session') return route.fulfill({status: 403, contentType: 'application/json', body: JSON.stringify({message: '登录已过期'})});
                return route.fulfill({contentType: 'application/json', body: JSON.stringify({data: {role: 'operations', version: 1, debug_exempt: true, landing: roles.operations[0], menus: roles.operations[1].map(href => ({href, title: labels[href], type: 'item'}))}})});
            });
            await page.goto('http://127.0.0.1:' + server.address().port + '/test#/server/manage');
            if (scenario === 'expired-session') {
                await page.locator('input[type=password]').waitFor();
                assert.equal(await page.evaluate(() => localStorage.getItem('authorization')), null);
                assert.ok(!calls.includes('/security/asset'), 'expired session does not load role resources');
            } else {
                await page.getByRole('alert').waitFor();
                assert.equal(await page.getByRole('alert').textContent(), '后台加载失败，请刷新重试');
                if (scenario === 'bootstrap-failure') assert.ok(!calls.includes('/security/asset'));
            }
            await page.locator('#admin-startup').waitFor({state: 'detached'});
            assert.equal(await page.locator('#root').getAttribute('aria-busy'), null);
            assert.deepEqual(errors, [], scenario + ' browser errors');
            console.log(scenario + ': browser passed');
            await context.close();
        }
    } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
