/**
 * Telegram 机器人注册的前端承载。
 *
 * 注册入口收敛到机器人之后，注册页只剩一条路径：
 *   打开机器人 → 发送 /regedit → 提交邮箱 → 收验证码 → 回网页输入。
 *
 * 本文件做四件事：
 *   1) 注册页（#/register）打开时，尽力隐藏主题自带的邮箱注册表单，改由本弹窗承载引导
 *   2) 机器人发来的深链（#/register?tg_email=...）自动跳到「输入验证码」这一步
 *   3) 提交「邮箱 + 验证码」，成功后写入登录态并跳转面板
 *   4) 后台「停止注册」时，同样藏掉表单并直接说明注册未开放
 *
 * 与主题解耦：三套主题只是在 dashboard.blade.php 里挂一行 script，不碰主题产物。
 */
(function () {
    'use strict';

    var CONFIG_API = '/api/v1/guest/comm/config';
    var REGISTER_API = '/api/v1/passport/auth/register/telegram';

    var config = null;
    var modal = null;
    var hiddenNodes = [];

    function escapeText(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function request(path, options) {
        options = options || {};
        var headers = { Accept: 'application/json' };
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
        if (document.getElementById('v2b-tgr-style')) return;
        var style = document.createElement('style');
        style.id = 'v2b-tgr-style';
        style.textContent =
            '.v2b-tgr-mask{position:fixed;inset:0;z-index:2100;background:#0e1d1c99;display:flex;align-items:center;justify-content:center;padding:16px}' +
            '.v2b-tgr-modal{width:min(460px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:14px;padding:24px;box-shadow:0 20px 60px #13242240;color:#1b2927;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}' +
            '.v2b-tgr-modal h2{margin:0 0 8px;font-size:21px}' +
            '.v2b-tgr-modal p{color:#667773;margin:8px 0}' +
            '.v2b-tgr-steps{margin:12px 0 4px;padding-left:20px;color:#42524f}' +
            '.v2b-tgr-steps li{margin:6px 0}' +
            '.v2b-tgr-field{display:block;margin:12px 0}' +
            '.v2b-tgr-field span{display:block;margin-bottom:5px;font-weight:600}' +
            '.v2b-tgr-field input{box-sizing:border-box;width:100%;min-height:40px;border:1px solid #cbd9d5;border-radius:8px;padding:8px 10px;font:inherit}' +
            '.v2b-tgr-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:18px}' +
            '.v2b-tgr-actions button{border:0;border-radius:8px;padding:10px 14px;cursor:pointer;background:#1f7a70;color:#fff;font:inherit}' +
            '.v2b-tgr-actions button.alt{background:#edf4f1;color:#22534d}' +
            '.v2b-tgr-actions button:disabled{cursor:not-allowed;opacity:.6}' +
            '.v2b-tgr-error{color:#a83e43;background:#fff0f0;border-radius:7px;padding:8px 10px;margin:10px 0}' +
            '.v2b-tgr-ok{color:#187247;background:#e7f5ee;border-radius:7px;padding:8px 10px;margin:10px 0}' +
            '@media(max-width:600px){.v2b-tgr-modal{padding:18px}}';
        document.head.appendChild(style);
    }

    function closeModal() {
        if (modal) modal.remove();
        modal = null;
    }

    function ensureModal(title, content) {
        addStyle();
        closeModal();
        modal = document.createElement('div');
        modal.className = 'v2b-tgr-mask';
        modal.innerHTML = '<section class="v2b-tgr-modal" role="dialog" aria-modal="true"><h2>'
            + escapeText(title) + '</h2><div class="v2b-tgr-body">' + content
            + '</div><div class="v2b-tgr-error" hidden></div><div class="v2b-tgr-actions"></div></section>';
        document.body.appendChild(modal);
        return modal;
    }

    function showError(message) {
        if (!modal) return;
        var box = modal.querySelector('.v2b-tgr-error');
        var ok = modal.querySelector('.v2b-tgr-ok');
        if (ok) ok.hidden = true;
        box.textContent = message;
        box.hidden = false;
    }

    function showOk(message) {
        if (!modal) return;
        var ok = modal.querySelector('.v2b-tgr-ok');
        var box = modal.querySelector('.v2b-tgr-error');
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

    function botUsername() {
        return String((config && config.telegram_bot_username) || (config && config.oauth && config.oauth.telegram_bot_username) || '').trim();
    }

    function botLink() {
        var username = botUsername();
        return username ? 'https://t.me/' + username + '?start=reg' : '';
    }

    /* ---------------- 隐藏主题自带的邮箱注册表单 ---------------- */

    /**
     * 三套主题的 DOM 结构各不相同且产物不可改，这里只能启发式定位：
     * 从 type=email 的输入框往上找，找到「同时含提交按钮」的那层才隐藏，
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
                    if (parent.style.display !== 'none') {
                        parent.style.display = 'none';
                        hiddenNodes.push(parent);
                    }
                    break;
                }
                node = parent;
            }
        }
    }

    function restoreLegacyForm() {
        hiddenNodes.forEach(function (node) { node.style.display = ''; });
        hiddenNodes = [];
    }

    /* ---------------- 引导弹窗与验证码弹窗 ---------------- */

    /**
     * 后台「停止注册」：主题自带的邮箱表单在后端已经没有对应接口，留着只会让用户白填
     * 一遍再报错。给个出口按钮 —— 没有按钮的弹窗在这页是不可关闭的。
     */
    function openClosed() {
        ensureModal('注册暂未开放',
            '<p>本站当前未开放注册。已有账号可直接登录；如需注册，请稍后再试或联系客服。</p>');
        var actions = modal.querySelector('.v2b-tgr-actions');
        actions.appendChild(actionButton('去登录', function () {
            closeModal();
            window.location.hash = '#/login';
        }));
        hideLegacyForm();
    }

    function openIntro() {
        var link = botLink();
        var content = '<p>本站注册已改为通过 Telegram 机器人完成，邮箱只作为登录账号使用，不会再收到验证码邮件。</p>'
            + '<ol class="v2b-tgr-steps">'
            + '<li>打开 Telegram 机器人' + (botUsername() ? '（@' + escapeText(botUsername()) + '）' : '') + '</li>'
            + '<li>发送 <b>/regedit</b>，然后按提示发送一个邮箱</li>'
            + '<li>把机器人回复的验证码填回这里，即可完成注册</li>'
            + '</ol>'
            + '<div class="v2b-tgr-ok" hidden></div>';
        ensureModal('用 Telegram 注册', content);
        var actions = modal.querySelector('.v2b-tgr-actions');
        if (link) {
            actions.appendChild(actionButton('打开 Telegram 机器人', function () {
                window.open(link, '_blank', 'noopener');
                showOk('已打开 Telegram：发送 /regedit 并按提示提交邮箱，收到验证码后回到本页。');
            }));
        } else {
            showError('机器人尚未配置，请联系管理员。');
        }
        actions.appendChild(actionButton('我已拿到验证码', function () {
            openCode('', true);
        }, 'alt'));
        hideLegacyForm();
    }

    function openCode(email, manual) {
        var content = '<p>填写机器人为你下发的验证码即可完成注册。</p>'
            + '<label class="v2b-tgr-field"><span>登录邮箱</span><input name="email" type="email" autocomplete="off" placeholder="you@example.com" value="' + escapeText(email || '') + '"></label>'
            + '<label class="v2b-tgr-field"><span>验证码</span><input name="code" type="text" inputmode="numeric" autocomplete="off" placeholder="6 位数字"></label>'
            + '<div class="v2b-tgr-ok" hidden></div>';
        ensureModal('输入验证码完成注册', content);
        var actions = modal.querySelector('.v2b-tgr-actions');
        var submit = actionButton('完成注册', function () {
            submitCode(submit);
        });
        actions.appendChild(submit);
        if (botLink()) {
            actions.appendChild(actionButton('重新打开机器人', function () {
                window.open(botLink(), '_blank', 'noopener');
            }, 'alt'));
        }
        if (!manual) {
            actions.appendChild(actionButton('返回', openIntro, 'alt'));
        }
        var codeInput = modal.querySelector('[name="code"]');
        if (codeInput) codeInput.focus();
    }

    function submitCode(button) {
        var email = modal.querySelector('[name="email"]').value.trim();
        var code = modal.querySelector('[name="code"]').value.trim();
        if (!email) { showError('请填写你在机器人里提交的邮箱'); return; }
        if (!/^\d{6}$/.test(code)) { showError('请输入 6 位数字验证码'); return; }
        button.disabled = true;
        request(REGISTER_API, { method: 'POST', body: { email: email, code: code } }).then(function (data) {
            if (!data || !data.auth_data) throw new Error('注册失败，请稍后重试');
            try {
                localStorage.setItem('authorization', data.auth_data);
            } catch (e) {
                // 存不进 localStorage（隐私模式等）时仍给一条可点的跳转，不把用户卡死
            }
            showOk('注册成功，正在进入面板…');
            closeModal();
            window.location.hash = '#/dashboard';
            window.location.reload();
        }).catch(function (error) {
            showError(error.message || '验证码无效或已过期，请回到机器人重新获取');
        }).then(function () {
            button.disabled = false;
        });
    }

    /* ---------------- 入口 ---------------- */

    function isRegisterPage() {
        return String(window.location.hash || '').indexOf('#/register') === 0;
    }

    function hashQuery() {
        var hash = String(window.location.hash || '');
        var index = hash.indexOf('?');
        var out = {};
        if (index < 0) return out;
        hash.slice(index + 1).split('&').forEach(function (pair) {
            if (!pair) return;
            var kv = pair.split('=');
            out[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || '');
        });
        return out;
    }

    function enabled() {
        return Boolean(config && config.telegram_register_enabled);
    }

    function closed() {
        return Boolean(config && config.telegram_register_closed);
    }

    function check() {
        if (!isRegisterPage()) {
            closeModal();
            restoreLegacyForm();
            return;
        }
        if (closed()) {
            hideLegacyForm();
            if (!modal) openClosed();
            return;
        }
        if (!enabled()) return;
        var email = hashQuery().tg_email || '';
        if (email) {
            openCode(email, false);
            hideLegacyForm();
            return;
        }
        if (!modal) openIntro();
    }

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

    function boot() {
        request(CONFIG_API).then(function (data) {
            config = data || {};
            check();
        }).catch(function () {
            // 配置拿不到就什么都不做，不能把注册页弄成白屏
        });
        hookHistory();
        window.addEventListener('hashchange', check);
        window.addEventListener('v2b:locationchange', check);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
