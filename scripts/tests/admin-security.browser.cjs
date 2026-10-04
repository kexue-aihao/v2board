'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const roles = {
    super: ['/security/administrators', ['/security/administrators', '/security/audit', '/security/account']],
    operations: ['/server/manage', ['/config/system', '/config/payment', '/config/theme', '/server/manage', '/server/group', '/server/route', '/risk/trace', '/risk/gateway', '/risk/shared-ip', '/security/account']],
    finance: ['/order', ['/order', '/security/account']], support: ['/ticket', ['/ticket', '/security/account']],
    marketing: ['/plan', ['/plan', '/coupon', '/giftcard', '/reward', '/security/account']],
};
const labels = {'/server/manage': '节点管理', '/order': '订单管理', '/plan': '订阅管理', '/ticket': '工单管理', '/security/account': '账号安全', '/security/administrators': '管理员权限', '/security/audit': '安全审计'};
const html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/admin/umi.css"><link rel="stylesheet" href="/assets/admin/components.chunk.css"></head><body><div id="root"></div><script>window.routerBase="/";window.settings={secure_path:"test",title:"4A Test",version:"test",theme:{sidebar:"dark",header:"light"},admin_asset_version:"test"};</script><script src="/assets/admin/security-loader.js"></script></body></html>';
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
        for (const role of (process.env.ADMIN_TEST_ROLE ? [process.env.ADMIN_TEST_ROLE] : ['guest', ...Object.keys(roles)])) {
            const context = await browser.newContext({viewport: {width: role === 'support' ? 390 : 1440, height: 1000}});
            const page = await context.newPage(), errors = [], calls = [];
            const order = {id: 10, trade_no: 'TEST-ORDER-123', user_id: 6, invite_user_id: 8, plan_id: 1, plan_name: '测试套餐', period: 'month_price', type: 1, status: 3, total_amount: 1000, balance_amount: 0, discount_amount: 0, refund_amount: 0, surplus_amount: 0, commission_balance: 100, commission_status: 0, created_at: 1700000000, updated_at: 1700000000, commission_log: [], user: {id: 6, email: 'buyer@example.test'}, invite_user: {id: 8, email: 'inviter@example.test'}};
            page.on('pageerror', error => { errors.push(error.message); console.error(role, error.message); });
            if (role !== 'guest') await page.addInitScript(() => localStorage.setItem('authorization', 'fixture'));
            await page.route('**/api/v1/test/**', async route => {
                const url = new URL(route.request().url()), endpoint = url.pathname.replace('/api/v1/test', ''); calls.push(endpoint);
                const currentRole = role === 'guest' ? 'operations' : role;
                let body = {data: [], total: 0};
                if (endpoint === '/security/bootstrap') body.data = {role: currentRole, version: 1, role_label: currentRole, menus: roles[currentRole][1].map(href => ({href, title: labels[href] || href, type: 'item'})), landing: roles[currentRole][0], debug_exempt: true};
                else if (endpoint === '/security/asset') return route.fulfill({contentType: 'application/javascript', body: fs.readFileSync(path.join(root, 'resources/admin/build', currentRole + '.js'), 'utf8')});
                else if (endpoint === '/user/info') body.data = {email: 'admin@example.test'};
                else if (endpoint === '/user/checkLogin') body.data = {is_login: true, is_admin: true};
                else if (endpoint === '/passport/auth/login') body.data = {auth_data: 'logged-in', is_admin: true};
                else if (endpoint === '/security/administrators') body = {data: [{id: 1, email: 'founder@example.test', role: 'super', protected: true}], total: 1, roles: {operations: '运维管理员', finance: '财务管理员', support: '客服管理员', marketing: '运营管理员'}};
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
            await page.goto('http://127.0.0.1:' + server.address().port + '/test#' + (role === 'guest' ? '/login' : roles[role][0]));
            if (role === 'guest') {
                await page.locator('input[type=password]').waitFor();
                await page.locator('input[type=text]').fill('admin@example.test'); await page.locator('input[type=password]').fill('testpassword');
                await page.getByRole('button', {name: '登入'}).click();
                await page.locator('#sidebar').waitFor();
            } else {
                await page.locator('#sidebar').waitFor();
                const links = await page.locator('#sidebar .nav-main-link-name').allTextContents();
                assert.deepEqual(links, roles[role][1].map(href => labels[href] || href), role + ' menus');
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
                assert.ok(!calls.includes('/user/fetch'), role + ' does not fetch user administration');
            }
            assert.deepEqual(errors, [], role + ' browser errors');
            console.log(role + ': browser passed (' + calls.length + ' requests)');
            await context.close();
        }
    } finally { await browser.close(); server.close(); }
})().catch(error => { console.error(error); server.close(); process.exitCode = 1; });
