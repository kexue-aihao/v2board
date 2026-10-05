'use strict';
const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {compile} = require('../build-admin-security.cjs');
const root = path.resolve(__dirname, '../..');
const manifest = JSON.parse(fs.readFileSync(path.join(root, 'resources/admin/build/manifest.json'), 'utf8'));
for (const role of ['guest', 'super', 'operations', 'finance', 'support', 'marketing']) {
    test(role + ' bundle is reproducible and contains only authorized entry modules', () => {
        const result = compile(role);
        assert.equal(crypto.createHash('sha256').update(result.code).digest('hex'), manifest.roles[role].sha256);
        assert.deepEqual(result.modules, manifest.roles[role].modules);
        assert.ok(!result.modules.includes('securityAdministrators'));
        assert.ok(!result.code.includes('/security/administrators'));
        if (role !== 'super') {
            assert.ok(!result.modules.includes('securityAudit'));
            assert.ok(!result.modules.includes('d1ca'));
            assert.ok(!result.code.includes('/user/update'));
        }
        if (role === 'super') {
            assert.ok(result.modules.includes('securityRoleField'));
            assert.ok(result.code.includes('formChange("admin_role"'));
            assert.ok(result.code.includes('/user/update'));
            assert.ok(result.code.includes('formChange("email"'));
            assert.ok(!result.code.includes('formChange("is_admin"'));
            assert.ok(!result.code.includes('formChange("is_staff"'));
        }
        if (role !== 'super') assert.ok(!result.modules.includes('securityRoleField'));
        if (role === 'finance') {
            assert.ok(!result.code.includes('/user/getUserInfoById'));
            assert.ok(!result.code.includes('user/addFilter'));
            assert.ok(result.code.includes('/security/options/plans'));
        }
        if (role === 'support') {
            assert.ok(!result.code.includes('/ticket/close'));
            assert.ok(result.modules.includes('securitySupport'));
        }
        if (role === 'marketing') {
            assert.ok(!result.code.includes('/config/fetch'));
            assert.ok(result.code.includes('/security/options/plan-config'));
            assert.ok(result.code.includes('/security/options/groups'));
        }
        if (role === 'operations') assert.ok(!result.code.includes('admin_2fa_force_enable'));
    });
}
test('public assets do not contain the old unrestricted administrator bundles or source maps', () => {
    for (const file of ['umi.js', 'vendors.async.js', 'components.async.js']) assert.equal(fs.existsSync(path.join(root, 'public/assets/admin', file)), false);
    assert.equal(fs.readdirSync(path.join(root, 'public/assets/admin')).some(file => file.endsWith('.map')), false);
    const view = fs.readFileSync(path.join(root, 'resources/views/admin.blade.php'), 'utf8');
    assert.ok(view.includes('security-loader.js'));
    assert.ok(!view.includes('src="/assets/admin/umi.js'));
});
