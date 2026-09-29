/**
 * 强制绑定 Telegram + Telegram 验证码找回密码。
 *
 * 两件事：
 *  1) 已登录用户：/user/telegram/account/status 返回 required=true 时弹一个不可关闭的
 *     弹窗遮挡整个面板，绑定完成（轮询到 bound=true）才消失。只在前端遮挡、不拦接口，
 *     避免连带挡住移动端 App 和第三方客户端。
 *  2) 未登录用户在登录页：右下角给一个「用 Telegram 验证码找回密码」入口，
 *     弹窗内完成 发码 → 验证码+新密码 → 重置。
 *
 * 与主题解耦：三个主题只是把这个文件挂进 __v2boardSiteStatusScripts。
 */
(function () {
    'use strict';

    var STATUS_API = '/api/v1/user/telegram/account/status';
    var PREPARE_API = '/api/v1/user/telegram/account/prepare';
    var SEND_CODE_API = '/api/v1/passport/comm/sendTelegramForgetCode';
    var RESET_API = '/api/v1/passport/auth/forget/telegram';
    var GUEST_CONFIG_API = '/api/v1/guest/comm/config';

    var POLL_INTERVAL = 4000;
    var modal = null;
    var pollTimer = null;
    var blocking = false;
    var observer = null;
    var lastCheck = 0;

    function authToken() {
        try {
            return localStorage.getItem('authorization') || '';
        } catch (e) {
            return '';
        }
    }

    function escapeText(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function request(path, options) {
        options = options || {};
        var headers = { Accept: 'application/json' };
        var token = authToken();
        if (token) headers.Authorization = token;
        var body;
        if (options.body) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(options.body);
        }
        return fetch(path, {
            method: options.method || 'GET',
            headers: headers,
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (payload) {
                if (!response.ok) {
                    var error = new Error((payload && (payload.message || payload.error)) || '请求失败，请稍后重试');
                    error.status = response.status;
                    throw error;
                }
                return payload && payload.data !== undefined ? payload.data : payload;
            });
        });
    }

    function addStyle() {
        if (document.getElementById('v2b-tgb-style')) return;
        var style = document.createElement('style');
        style.id = 'v2b-tgb-style';
        style.textContent =
            '.v2b-tgb-mask{position:fixed;inset:0;z-index:2200;background:#0e1d1c99;display:flex;align-items:center;justify-content:center;padding:16px}' +
            '.v2b-tgb-modal{width:min(480px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 20px 60px #13242240;color:#1b2927;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}' +
            '.v2b-tgb-modal h2{margin:0 0 8px;font-size:21px}' +
            '.v2b-tgb-modal p{color:#667773;margin:8px 0}' +
            '.v2b-tgb-steps{margin:12px 0 4px;padding-left:20px;color:#42524f}' +
            '.v2b-tgb-steps li{margin:6px 0}' +
            '.v2b-tgb-field{display:block;margin:12px 0}' +
            '.v2b-tgb-field span{display:block;margin-bottom:5px;font-weight:600}' +
            '.v2b-tgb-field input{box-sizing:border-box;width:100%;min-height:40px;border:1px solid #cbd9d5;border-radius:8px;padding:8px 10px;font:inherit}' +
            '.v2b-tgb-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}' +
            '.v2b-tgb-actions button{border:0;border-radius:8px;padding:10px 14px;cursor:pointer;background:#1f7a70;color:#fff;font:inherit}' +
            '.v2b-tgb-actions button.alt{background:#edf4f1;color:#22534d}' +
            '.v2b-tgb-actions button:disabled{cursor:not-allowed;opacity:.6}' +
            '.v2b-tgb-error{color:#a83e43;background:#fff0f0;border-radius:7px;padding:8px 10px;margin:10px 0}' +
            '.v2b-tgb-ok{color:#187247;background:#e7f5ee;border-radius:7px;padding:8px 10px;margin:10px 0}' +
            '.v2b-tgb-entry{position:fixed;right:24px;bottom:24px;z-index:1500;border:0;border-radius:999px;padding:11px 16px;background:#1f7a70;color:#fff;box-shadow:0 8px 24px #153c3940;cursor:pointer;font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}' +
            '@media(max-width:600px){.v2b-tgb-entry{right:14px;bottom:14px}.v2b-tgb-modal{padding:18px}}';
        document.head.appendChild(style);
    }

    function closeModal() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        blocking = false;
        if (observer) { observer.disconnect(); observer = null; }
        if (modal) modal.remove();
        modal = null;
        document.body.style.overflow = '';
    }

    function ensureModal(title, content) {
        closeModal();
        addStyle();
        modal = document.createElement('div');
        modal.className = 'v2b-tgb-mask';
        modal.innerHTML = '<section class="v2b-tgb-modal" role="dialog" aria-modal="true"><h2>'
            + escapeText(title) + '</h2><div class="v2b-tgb-body">' + content
            + '</div><div class="v2b-tgb-error" hidden></div><div class="v2b-tgb-actions"></div></section>';
        document.body.appendChild(modal);
        return modal;
    }

    function showError(message) {
        if (!modal) return;
        var box = modal.querySelector('.v2b-tgb-error');
        var ok = modal.querySelector('.v2b-tgb-ok');
        if (ok) ok.hidden = true;
        box.textContent = message;
        box.hidden = false;
    }

    function showOk(message) {
        if (!modal) return;
        var box = modal.querySelector('.v2b-tgb-error');
        var ok = modal.querySelector('.v2b-tgb-ok');
        box.hidden = true;
        if (ok) { ok.textContent = message; ok.hidden = false; }
    }

    function actionButton(label, handler, className) {
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = label;
        if (className) button.className = className;
        button.onclick = handler;
        return button;
    }

    /* ---------------- 强制绑定弹窗 ---------------- */

    function lockModal() {
        blocking = true;
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', blockEscape, true);
        observer = new MutationObserver(function () {
            if (blocking && modal && !document.body.contains(modal)) {
                document.body.appendChild(modal);
            }
        });
        observer.observe(document.body, { childList: true });
    }

    function blockEscape(event) {
        if (blocking && (event.key === 'Escape' || event.keyCode === 27)) {
            event.stopPropagation();
            event.preventDefault();
        }
    }

    function renderBindModal(status) {
        var bot = status && status.bot_username ? '@' + status.bot_username : '官方机器人';
        var content = '<p>为了在你忘记密码时能通过 Telegram 收到验证码重置密码，本站要求账号绑定 Telegram 后才能使用。</p>'
            + '<ol class="v2b-tgb-steps">'
            + '<li>点击下方按钮打开 Telegram（' + escapeText(bot) + '）</li>'
            + '<li>在聊天窗口点「开始 / START」发送绑定指令</li>'
            + '<li>回到本页面，弹窗会自动消失</li>'
            + '</ol>'
            + '<div class="v2b-tgb-ok" hidden></div>';
        ensureModal('请先绑定 Telegram', content);
        var actions = modal.querySelector('.v2b-tgb-actions');
        var openButton = actionButton('打开 Telegram 绑定', function () {
            prepareBinding(openButton);
        });
        var recheck = actionButton('我已绑定，立即检查', function () {
            refreshStatus(true);
        }, 'alt');
        actions.appendChild(openButton);
        actions.appendChild(recheck);
        lockModal();
        startPolling();
    }

    function prepareBinding(button) {
        button.disabled = true;
        request(PREPARE_API, { method: 'POST' }).then(function (data) {
            if (!data || !data.binding_url) throw new Error('绑定链接生成失败');
            window.open(data.binding_url, '_blank', 'noopener');
            showOk('已打开 Telegram：请在聊天窗口点击「开始」完成绑定，成功后本弹窗会自动消失。');
            startPolling();
        }).catch(function (error) {
            showError(error.message || '绑定链接生成失败');
        }).then(function () {
            button.disabled = false;
        });
    }

    function startPolling() {
        if (pollTimer) return;
        pollTimer = setInterval(function () {
            refreshStatus(false);
        }, POLL_INTERVAL);
    }

    function refreshStatus(manual) {
        return request(STATUS_API).then(function (status) {
            if (!status || !status.required) {
                closeModal();
                if (manual) window.location.reload();
                return;
            }
            if (manual) showError('还没有检测到绑定，请在 Telegram 里点击「开始」后再试。');
        }).catch(function (error) {
            if (error.status === 401 || error.status === 403) { closeModal(); return; }
            if (manual) showError(error.message || '检查失败，请稍后重试');
        });
    }

    /* ---------------- 登录页：Telegram 验证码找回密码 ---------------- */

    function isLoginPage() {
        return String(window.location.hash || '').indexOf('#/login') === 0;
    }

    function isForgetPage() {
        var hash = String(window.location.hash || '');
        // default 使用 /forgetpassword，ez/signature 使用 /forgot-password；
        // 两者都可能带查询参数或尾部斜杠，因此只判断路由前缀。
        return hash.indexOf('#/forgetpassword') === 0
            || hash.indexOf('#/forgot-password') === 0
            || hash.indexOf('#/forget') === 0
            || hash.indexOf('#/reset') === 0;
    }

    /**
     * 邮箱验证码找回已下线，主题自带的找回表单点了只会报错。各主题 DOM 不同，
     * 只能从 type=email 的输入框往上找到「同时含提交按钮」的那层再隐藏；
     * 找不到就什么都不做（宁可不藏，也不能误藏整页）。
     */
    function hideLegacyForm() {
        var inputs = document.querySelectorAll('input[type="email"]');
        for (var i = 0; i < inputs.length; i++) {
            var node = inputs[i];
            for (var depth = 0; node && depth < 6; depth++) {
                var parent = node.parentElement;
                if (!parent || parent === document.body) break;
                if (parent.querySelector('button[type="submit"]')) {
                    parent.style.display = 'none';
                    break;
                }
                node = parent;
            }
        }
    }

    function renderResetEntry() {
        if (document.getElementById('v2b-tgb-entry')) return;
        addStyle();
        var button = document.createElement('button');
        button.type = 'button';
        button.id = 'v2b-tgb-entry';
        button.className = 'v2b-tgb-entry';
        button.textContent = '用 Telegram 验证码找回密码';
        button.onclick = openResetModal;
        document.body.appendChild(button);
    }

    function removeResetEntry() {
        var entry = document.getElementById('v2b-tgb-entry');
        if (entry) entry.remove();
    }

    function openResetModal() {
        var content = '<p>验证码会发送到该账号在 Telegram 上绑定的机器人私聊。也可以直接在机器人里发送 /resetpassword 获取验证码。</p>'
            + '<label class="v2b-tgb-field"><span>账号邮箱</span><input name="email" type="email" autocomplete="off" placeholder="you@example.com"></label>'
            + '<div class="v2b-tgb-code-fields" hidden>'
            + '<label class="v2b-tgb-field"><span>Telegram 验证码</span><input name="telegram_code" type="text" inputmode="numeric" autocomplete="off" placeholder="6 位数字"></label>'
            + '<label class="v2b-tgb-field"><span>新密码</span><input name="password" type="password" autocomplete="new-password" placeholder="至少 8 位"></label>'
            + '</div>';
        ensureModal('用 Telegram 验证码重置密码', content);
        var actions = modal.querySelector('.v2b-tgb-actions');
        var sendButton = actionButton('发送验证码到 Telegram', function () {
            sendForgetCode(sendButton);
        });
        var submitButton = actionButton('重置密码', function () {
            submitReset(submitButton);
        });
        submitButton.hidden = true;
        actions.appendChild(sendButton);
        actions.appendChild(submitButton);
        actions.appendChild(actionButton('关闭', closeModal, 'alt'));
    }

    function resetFields() {
        return {
            email: modal.querySelector('[name="email"]').value.trim(),
            code: modal.querySelector('[name="telegram_code"]').value.trim(),
            password: modal.querySelector('[name="password"]').value
        };
    }

    function sendForgetCode(button) {
        var fields = resetFields();
        if (!fields.email) { showError('请先填写账号邮箱'); return; }
        button.disabled = true;
        request(SEND_CODE_API, { method: 'POST', body: { email: fields.email } }).then(function () {
            modal.querySelector('.v2b-tgb-code-fields').hidden = false;
            modal.querySelector('.v2b-tgb-actions').querySelectorAll('button')[1].hidden = false;
            showOk('验证码已发送到该账号绑定的 Telegram，5 分钟内有效。');
        }).catch(function (error) {
            showError(error.message || '发送失败，请稍后重试');
        }).then(function () {
            button.disabled = false;
        });
    }

    function submitReset(button) {
        var fields = resetFields();
        if (!/^\d{6}$/.test(fields.code)) { showError('请输入 6 位数字验证码'); return; }
        if (!fields.password || fields.password.length < 8) { showError('新密码至少 8 位'); return; }
        button.disabled = true;
        request(RESET_API, {
            method: 'POST',
            body: { email: fields.email, telegram_code: fields.code, password: fields.password }
        }).then(function () {
            showOk('密码已重置，请用新密码登录。');
            modal.querySelector('.v2b-tgb-actions').querySelectorAll('button').forEach(function (item) {
                item.hidden = true;
            });
            modal.querySelector('.v2b-tgb-actions').appendChild(actionButton('关闭', closeModal, 'alt'));
        }).catch(function (error) {
            showError(error.message || '重置失败，请稍后重试');
        }).then(function () {
            button.disabled = false;
        });
    }

    /* ---------------- 入口 ---------------- */

    // 入口是否显示由后台开关决定，开关关着时不给一个点了会报错的入口。
    function maybeRenderResetEntry() {
        request(GUEST_CONFIG_API).then(function (config) {
            if (config && config.telegram_forget_enabled) renderResetEntry();
            else removeResetEntry();
        }).catch(function () {
            removeResetEntry();
        });
    }

    function check() {
        lastCheck = Date.now();
        if (!authToken()) {
            removeResetEntry();
            // 邮箱验证码找回已下线：忘记密码页直接进 Telegram 验证码流程，并藏掉旧表单
            if (isForgetPage()) {
                hideLegacyForm();
                if (!modal) openResetModal();
                return;
            }
            if (isLoginPage()) maybeRenderResetEntry();
            return;
        }
        removeResetEntry();
        request(STATUS_API).then(function (status) {
            if (status && status.required) {
                renderBindModal(status);
            } else if (blocking) {
                closeModal();
            }
        }).catch(function () {
            // 未登录或接口不可用：不打扰用户。
        });
    }

    // 主题既可能用 hash 路由也可能用 pushState 路由，两条都要接上；
    // 2 秒节流避免每次跳转都打一次状态接口。
    function hookHistory() {
        ['pushState', 'replaceState'].forEach(function (name) {
            var original = history[name];
            history[name] = function () {
                var result = original.apply(this, arguments);
                window.dispatchEvent(new Event('v2b:locationchange'));
                return result;
            };
        });
    }

    function onLocationChange() {
        if (blocking) return;
        if (Date.now() - lastCheck < 2000) return;
        check();
    }

    function boot() {
        check();
        hookHistory();
        window.addEventListener('hashchange', onLocationChange);
        window.addEventListener('v2b:locationchange', onLocationChange);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
