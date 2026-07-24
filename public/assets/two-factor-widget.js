(function () {
    'use strict';

    var isAdmin = Boolean(window.settings && window.settings.secure_path);
    var activeBase = isAdmin ? '/api/v1/' + String(window.settings.secure_path).replace(/^\/+|\/+$/g, '') + '/2fa' : '/api/v1/user/2fa';
    var fallbackBase = isAdmin ? '/api/v1/staff/2fa' : '';
    var modal;
    var setupData;
    var currentStatus;

    function authHeaders() {
        var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
        var token = localStorage.getItem('authorization');
        if (token) headers.Authorization = token;
        return headers;
    }

    function request(path, options) {
        options = options || {};
        var base = options.base || activeBase;
        return fetch(base + path, {
            method: options.method || 'GET',
            headers: authHeaders(),
            body: options.body ? JSON.stringify(options.body) : undefined
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) {
                    var error = new Error(body && (body.message || body.error) || '请求失败');
                    error.status = response.status;
                    throw error;
                }
                return body && body.data !== undefined ? body.data : body;
            });
        });
    }

    function requestStaffFallback(path, options) {
        return request(path, options).catch(function (error) {
            if (!fallbackBase || error.status !== 403) throw error;
            activeBase = fallbackBase;
            return request(path, Object.assign({}, options, { base: fallbackBase }));
        });
    }

    function escapeText(value) {
        return String(value == null ? '' : value).replace(/[&<>\"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
        });
    }

    function addStyle() {
        if (document.getElementById('v2board-2fa-style')) return;
        var style = document.createElement('style');
        style.id = 'v2board-2fa-style';
        style.textContent = '.v2b-2fa-trigger{position:fixed;right:24px;bottom:24px;z-index:1000;border:0;border-radius:999px;padding:11px 16px;background:#1f7a70;color:#fff;box-shadow:0 8px 24px #153c3940;cursor:pointer;font-size:14px}.v2b-2fa-mask{position:fixed;inset:0;z-index:1100;background:#0e1d1c80;display:flex;align-items:center;justify-content:center;padding:16px}.v2b-2fa-modal{width:min(460px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 20px 60px #13242240;color:#1b2927;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}.v2b-2fa-modal h2{margin:0 0 6px;font-size:21px}.v2b-2fa-modal p{color:#667773;margin:8px 0 16px}.v2b-2fa-qr{display:block;width:220px;height:220px;margin:12px auto;border:1px solid #dbe7e3}.v2b-2fa-field{display:block;margin:12px 0}.v2b-2fa-field span{display:block;margin-bottom:5px;font-weight:600}.v2b-2fa-field input{box-sizing:border-box;width:100%;min-height:40px;border:1px solid #cbd9d5;border-radius:8px;padding:8px 10px;font:inherit}.v2b-2fa-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}.v2b-2fa-actions button{border:0;border-radius:8px;padding:10px 13px;cursor:pointer;background:#1f7a70;color:#fff}.v2b-2fa-actions button.alt{background:#edf4f1;color:#22534d}.v2b-2fa-actions button.danger{background:#a83e43}.v2b-2fa-codes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin:12px 0}.v2b-2fa-codes code{padding:7px;background:#f0f5f3;border-radius:6px;text-align:center}.v2b-2fa-status{display:inline-block;padding:4px 8px;border-radius:999px;background:#e7f5ee;color:#187247}.v2b-2fa-error{color:#a83e43;background:#fff0f0;border-radius:7px;padding:8px;margin:8px 0}.v2b-2fa-force{display:flex;align-items:center;gap:8px;padding:10px 0}.v2b-2fa-force input{width:18px;height:18px}@media(max-width:600px){.v2b-2fa-trigger{right:14px;bottom:14px}.v2b-2fa-modal{padding:18px}.v2b-2fa-qr{width:190px;height:190px}}';
        document.head.appendChild(style);
    }

    function closeModal() {
        if (modal) modal.remove();
        modal = null;
        setupData = null;
    }

    function field(label, type, name, placeholder) {
        return '<label class="v2b-2fa-field"><span>' + label + '</span><input name="' + name + '" type="' + (type || 'text') + '" autocomplete="off" placeholder="' + (placeholder || '') + '"></label>';
    }

    function showModal(title, content, onReady) {
        closeModal();
        addStyle();
        modal = document.createElement('div');
        modal.className = 'v2b-2fa-mask';
        modal.innerHTML = '<section class="v2b-2fa-modal" role="dialog" aria-modal="true"><h2>' + escapeText(title) + '</h2><div class="v2b-2fa-body">' + content + '</div><div class="v2b-2fa-error" hidden></div><div class="v2b-2fa-actions"><button type="button" class="alt v2b-2fa-close">关闭</button></div></section>';
        document.body.appendChild(modal);
        modal.querySelector('.v2b-2fa-close').onclick = closeModal;
        if (onReady) onReady(modal);
    }

    function errorMessage(root, error) {
        var box = root.querySelector('.v2b-2fa-error');
        if (box) { box.textContent = error && error.message || '操作失败，请稍后重试'; box.hidden = false; }
    }

    function showRecoveryCodes(codes) {
        if (!modal || !codes || !codes.length) return;
        var body = modal.querySelector('.v2b-2fa-body');
        var panel = document.createElement('div');
        panel.innerHTML = '<strong>请立即保存恢复码</strong><p>恢复码只显示这一次，每个恢复码只能使用一次。</p><div class="v2b-2fa-codes"></div>';
        codes.forEach(function (code) { var item = document.createElement('code'); item.textContent = code; panel.querySelector('.v2b-2fa-codes').appendChild(item); });
        body.appendChild(panel);
    }

    function renderStatus(status) {
        currentStatus = status;
        var content = '<p>支持 Google Authenticator、Microsoft Authenticator 及其他标准 TOTP 验证器。</p><span class="v2b-2fa-status">' + (status.enabled ? '已启用' : '未启用') + '</span>';
        if (isAdmin && activeBase !== fallbackBase) content += '<label class="v2b-2fa-force"><input type="checkbox" name="force"' + ((window.__v2board2faForce ? ' checked' : '')) + '> 强制管理员和员工使用二步验证</label>';
        if (!status.enabled) content += '<p>绑定后，登录时需要输入验证器中的 6 位动态验证码。</p>';
        else content += field('当前密码', 'password', 'current_password') + field('验证器或恢复码', 'text', 'code', '000000 或 XXXX-XXXX-XXXX');
        showModal('账户二步验证', content, function (root) {
            var actions = root.querySelector('.v2b-2fa-actions');
            if (!status.enabled) {
                var setupButton = document.createElement('button'); setupButton.textContent = '开始绑定'; actions.insertBefore(setupButton, actions.firstChild); setupButton.onclick = beginSetup;
            } else {
                var regen = document.createElement('button'); regen.className = 'alt'; regen.textContent = '重新生成恢复码'; actions.insertBefore(regen, actions.firstChild); regen.onclick = function () { secureAction('/recovery-codes/regenerate', 'recovery_codes'); };
                var disable = document.createElement('button'); disable.className = 'danger'; disable.textContent = '关闭二步验证'; actions.insertBefore(disable, actions.firstChild); disable.onclick = function () { secureAction('/disable', 'disabled'); };
            }
            if (isAdmin && activeBase !== fallbackBase) {
                var force = root.querySelector('[name="force"]');
                if (force) { force.checked = Boolean(window.__v2board2faForce); force.onchange = function () { saveForce(force.checked, root); }; }
            }
        });
    }

    function openWidget() {
        requestStaffFallback('/status').then(renderStatus).catch(function (error) {
            showModal('二步验证', '<p>无法读取二步验证状态。</p>', function (root) { errorMessage(root, error); });
        });
    }

    function beginSetup() {
        requestStaffFallback('/setup', { method: 'POST' }).then(function (data) {
            setupData = data;
            var content = '<p>请使用验证器扫描二维码，或手动输入密钥。</p><img class="v2b-2fa-qr" alt="二步验证二维码" src="' + escapeText(data.qr_code) + '"><p><strong>' + escapeText(data.issuer) + '</strong><br>' + escapeText(data.account) + '</p>' + field('手动密钥', 'text', 'manual_key') + field('验证器验证码', 'text', 'code', '000000');
            showModal('绑定二步验证', content, function (root) {
                root.querySelector('[name="manual_key"]').value = data.manual_key || '';
                var actions = root.querySelector('.v2b-2fa-actions');
                var confirm = document.createElement('button'); confirm.textContent = '确认绑定'; actions.insertBefore(confirm, actions.firstChild); confirm.onclick = function () {
                    requestStaffFallback('/confirm', { method: 'POST', body: { code: root.querySelector('[name="code"]').value } }).then(function (result) {
                        showRecoveryCodes(result.recovery_codes || []);
                        confirm.disabled = true;
                        confirm.textContent = '已绑定';
                        setTimeout(function () { closeModal(); window.location.reload(); }, 5000);
                    }).catch(function (error) { errorMessage(root, error); });
                };
            });
        }).catch(function (error) { errorMessage(document.body, error); });
    }

    function secureAction(path, resultKey) {
        if (!modal) return;
        var password = modal.querySelector('[name="current_password"]');
        var code = modal.querySelector('[name="code"]');
        requestStaffFallback(path, { method: 'POST', body: { current_password: password && password.value, code: code && /^\d{6}$/.test(code.value) ? code.value : '', recovery_code: code && /^\d{6}$/.test(code.value) ? '' : code && code.value } }).then(function (result) {
            if (resultKey === 'recovery_codes') showRecoveryCodes(result.recovery_codes || []);
            else { closeModal(); localStorage.removeItem('authorization'); window.location.hash = '/login'; }
        }).catch(function (error) { errorMessage(modal, error); });
    }

    function saveForce(value, root) {
        request('/config/save', { method: 'POST', base: '/api/v1/' + String(window.settings.secure_path).replace(/^\/+|\/+$/g, ''), body: { admin_2fa_force_enable: value ? 1 : 0 } }).then(function () {
            window.__v2board2faForce = value;
        }).catch(function (error) { errorMessage(root, error); });
    }

    function hasAuthorization() {
        try {
            return Boolean(localStorage.getItem('authorization'));
        } catch (error) {
            return false;
        }
    }

    function mountTrigger() {
        if (!document.body || document.getElementById('v2board-2fa-trigger')) return;
        if (isAdmin && (!hasAuthorization() || /#\/login(?:[/?]|$)/.test(window.location.hash))) return;
        if (!isAdmin && !/\/(profile)(?:[/?]|$)/.test(window.location.hash)) return;
        addStyle();
        var button = document.createElement('button');
        button.id = 'v2board-2fa-trigger'; button.className = 'v2b-2fa-trigger'; button.type = 'button'; button.textContent = '二步验证'; button.onclick = openWidget;
        document.body.appendChild(button);
    }

    function mount() {
        mountTrigger();
        if (isAdmin && hasAuthorization()) {
            request('/config/fetch?key=safe', { base: '/api/v1/' + String(window.settings.secure_path).replace(/^\/+|\/+$/g, '') }).then(function (data) {
                window.__v2board2faForce = Boolean(data && data.safe && Number(data.safe.admin_2fa_force_enable));
            }).catch(function () {});
        }
    }

    window.__v2board2faChallenge = function (data, redirect) {
        var content = data.two_factor_setup_required ? '<p>请先绑定验证器。登录成功后将显示一次恢复码。</p>' : field('验证器或恢复码', 'text', 'code', '000000 或 XXXX-XXXX-XXXX');
        showModal(data.two_factor_setup_required ? '保护管理员账户' : '完成二步验证', content, function (root) {
            if (data.two_factor_setup_required) {
                request('/passport/auth/2fa/setup', { method: 'POST', base: '/api/v1', body: { setup_token: data.challenge } }).then(function (result) {
                    var body = root.querySelector('.v2b-2fa-body'); body.innerHTML = '<p>请扫描二维码，然后输入验证码。</p><img class="v2b-2fa-qr" alt="二步验证二维码" src="' + escapeText(result.qr_code) + '"><p><strong>' + escapeText(result.issuer) + '</strong><br>' + escapeText(result.account) + '<br>手动密钥：' + escapeText(result.manual_key) + '</p>' + field('验证码', 'text', 'code', '000000');
                    var button = document.createElement('button'); button.textContent = '确认并登录'; root.querySelector('.v2b-2fa-actions').insertBefore(button, root.querySelector('.v2b-2fa-close')); button.onclick = function () { request('/passport/auth/2fa/confirm', { method: 'POST', base: '/api/v1', body: { setup_token: data.challenge, code: root.querySelector('[name="code"]').value } }).then(function (value) { localStorage.setItem('authorization', value.auth_data); closeModal(); window.location.hash = '/' + String(redirect || 'dashboard').replace(/^\//, ''); }).catch(function (error) { errorMessage(root, error); }); };
                }).catch(function (error) { errorMessage(root, error); });
            } else {
                var button = document.createElement('button'); button.textContent = '验证并继续'; root.querySelector('.v2b-2fa-actions').insertBefore(button, root.querySelector('.v2b-2fa-close')); button.onclick = function () { var value = root.querySelector('[name="code"]').value || ''; var body = { challenge: data.challenge }; if (/^\d{6}$/.test(value)) body.code = value; else body.recovery_code = value; request('/passport/auth/verify2fa', { method: 'POST', base: '/api/v1', body: body }).then(function (result) { localStorage.setItem('authorization', result.auth_data); closeModal(); window.location.hash = '/' + String(redirect || 'dashboard').replace(/^\//, ''); }).catch(function (error) { errorMessage(root, error); }); };
            }
        });
        return true;
    };

    window.addEventListener('hashchange', function () { var old = document.getElementById('v2board-2fa-trigger'); if (old) old.remove(); mountTrigger(); });
    document.addEventListener('DOMContentLoaded', mount);
    setTimeout(mount, 700);
    setTimeout(mount, 1800);
})();
