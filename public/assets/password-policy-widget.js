(function () {
    'use strict';

    // default 主题是 2.4MB 无构建步骤的 webpack 产物，逻辑放在这里而不是补进 bundle：
    // 补丁面越小越安全，且横幅要出现在**每个**路由上，走 document.body 就不用为此再改一次
    // umi.js，React 重渲染也冲不掉它。形制照 two-factor-widget.js。
    //
    // 只服务用户端。管理端有自己的入口（用户管理 → 操作 → 重置密码），而且管理员本来就被
    // 排除在提醒之外。
    if (window.settings && window.settings.secure_path) return;

    var API = '/api/v1/user';
    var required = null;

    function authHeaders() {
        var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
        var token = null;
        try { token = localStorage.getItem('authorization'); } catch (error) { token = null; }
        if (token) headers.Authorization = token;
        return headers;
    }

    function hasAuthorization() {
        try {
            return Boolean(localStorage.getItem('authorization'));
        } catch (error) {
            return false;
        }
    }

    function request(path, options) {
        options = options || {};
        return fetch(API + path, {
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

    function escapeText(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
        });
    }

    function addStyle() {
        if (document.getElementById('v2board-pwd-style')) return;
        var style = document.createElement('style');
        style.id = 'v2board-pwd-style';
        // 固定在底部而不是顶部：default 主题的顶栏是粘性的，从顶部插会互相遮挡；底部这一条
        // 带内边距，且下方没有任何既有的固定元素。
        style.textContent = '.v2b-pwd-banner{position:fixed;right:0;bottom:0;left:0;z-index:1090;display:flex;align-items:center;flex-wrap:wrap;gap:10px;padding:12px 18px;background:#fff8e1;border-top:1px solid #f0d68a;box-shadow:0 -4px 16px #1b192b14;color:#7a5b12;font:13px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","Microsoft YaHei",sans-serif}'
            + '.v2b-pwd-banner p{flex:1;min-width:220px;margin:0}'
            + '.v2b-pwd-banner button{flex:0 0 auto;border:0;border-radius:6px;padding:8px 14px;background:#b3820f;color:#fff;cursor:pointer;font:inherit;font-weight:600}'
            + '.v2b-pwd-banner button:hover{background:#946a09}'
            + '.v2b-pwd-help{margin:0 0 14px;color:#6c757d}'
            + '.v2b-pwd-pending{display:inline-block;margin-bottom:12px;padding:4px 9px;border-radius:99px;background:#fff8e1;color:#7a5b12;font-size:12px;font-weight:600}'
            + '.v2b-pwd-row{max-width:480px}'
            + '.v2b-pwd-error{margin:10px 0;padding:9px;border-radius:4px;background:#fff0f0;color:#a83e43}'
            + '.v2b-pwd-panel{max-width:560px;margin-top:4px;padding:15px;background:#f7f9fb;border:1px solid #e4e9ef;border-radius:4px}'
            + '.v2b-pwd-panel strong{display:block;margin-bottom:4px}'
            + '.v2b-pwd-panel small{display:block;margin-bottom:11px;color:#6c757d}'
            + '.v2b-pwd-code{display:block;padding:11px;background:#fff;border:1px solid #e4e9ef;border-radius:4px;color:#1b2927;font-family:Menlo,Consolas,monospace;font-size:13px;line-height:1.7;word-break:break-all;-webkit-user-select:all;user-select:all}'
            + '.v2b-pwd-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:13px}'
            + '.v2b-pwd-actions .btn{min-height:36px}'
            + '@media(max-width:600px){.v2b-pwd-banner{padding:10px 14px}.v2b-pwd-banner button{width:100%}.v2b-pwd-actions .btn{flex:1 1 auto}}';
        document.head.appendChild(style);
    }

    // ---------- 横幅 ----------

    function removeBanner() {
        var existing = document.getElementById('v2board-pwd-banner');
        if (existing) existing.remove();
    }

    function renderBanner() {
        // 提醒不可关闭（要求是「一直提醒」），但它只是提醒：不拦截任何操作，订阅、下单、
        // 开工单全都照常。
        if (required !== true || !hasAuthorization()) {
            removeBanner();
            return;
        }
        if (!document.body || document.getElementById('v2board-pwd-banner')) return;
        addStyle();
        var banner = document.createElement('div');
        banner.id = 'v2board-pwd-banner';
        banner.className = 'v2b-pwd-banner';
        banner.setAttribute('role', 'alert');
        banner.innerHTML = '<p>你的密码是注册时自行设置的，存在被撞库猜中的风险。请换成系统生成的 64 位随机密码。</p>';
        var button = document.createElement('button');
        button.type = 'button';
        button.textContent = '去重置';
        button.onclick = function () {
            window.location.hash = '#/profile';
            // 已经在个人中心时改 hash 不会触发路由，直接把控件滚进视野。
            setTimeout(function () {
                var root = document.getElementById('v2board-password-reset-inline');
                if (root && root.scrollIntoView) root.scrollIntoView({ block: 'center' });
            }, 260);
        };
        banner.appendChild(button);
        document.body.appendChild(banner);
    }

    var statusPending = false;

    function loadStatus() {
        if (required !== null || statusPending || !hasAuthorization()) {
            renderBanner();
            return;
        }
        // mount() 会在 DOMContentLoaded、700ms、1800ms 各跑一次，没有这个在途标记就会打三次
        // 同样的请求。
        statusPending = true;
        request('/checkLogin').then(function (data) {
            required = Boolean(data && data.password_reset_required);
            renderBanner();
        }).catch(function () {
            // 拉不到就不提醒，绝不因此把用户挡在外面。
            required = false;
        }).then(function () {
            statusPending = false;
        });
    }

    // ---------- 个人中心内嵌控件 ----------

    function helpText() {
        return '密码不再由你自己设置。输入当前密码后，系统会生成一个 64 位随机密码（大小写字母 + 数字）。'
            + '<strong>新密码只显示一次</strong>，请先存到密码管理器再关闭页面；重置后所有已登录设备会被退出。';
    }

    function renderForm(root, errorMessage) {
        var pending = required === true ? '<span class="v2b-pwd-pending">待重置</span>' : '';
        root.innerHTML = pending
            + '<p class="v2b-pwd-help">' + helpText() + '</p>'
            + (errorMessage ? '<div class="v2b-pwd-error">' + escapeText(errorMessage) + '</div>' : '')
            + '<div class="v2b-pwd-row"><div class="form-group">'
            + '<label for="v2b-pwd-current">当前密码</label>'
            + '<input id="v2b-pwd-current" class="form-control" type="password" autocomplete="current-password">'
            + '</div><button type="button" class="btn btn-primary" data-pwd-action="reset">生成新密码</button></div>';
        var input = root.querySelector('#v2b-pwd-current');
        var button = root.querySelector('[data-pwd-action="reset"]');
        function submit() {
            var value = input.value || '';
            if (!value) { input.focus(); return; }
            button.disabled = true;
            button.textContent = '生成中...';
            request('/resetPassword', { method: 'POST', body: { current_password: value } }).then(function (data) {
                required = false;
                removeBanner();
                renderPanel(root, data && data.password);
            }).catch(function (error) {
                renderForm(root, error && error.message ? error.message : '重置失败，请稍后重试');
            });
        }
        button.onclick = submit;
        input.onkeydown = function (event) { if (event.key === 'Enter') submit(); };
    }

    function copyText(text, button) {
        function done() {
            var original = button.getAttribute('data-label') || button.textContent;
            button.setAttribute('data-label', original);
            button.textContent = '已复制';
            setTimeout(function () { button.textContent = button.getAttribute('data-label'); }, 1600);
        }
        // 非 HTTPS 下 navigator.clipboard 不存在，静默失败会让用户直接丢掉这个只显示一次的
        // 密码，所以必须留 execCommand 兜底。
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(function () { legacyCopy(text, done); });
            return;
        }
        legacyCopy(text, done);
    }

    function legacyCopy(text, done) {
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', 'readonly');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); done(); } catch (error) { /* 还有 user-select:all 可以手选 */ }
        area.remove();
    }

    function renderPanel(root, password) {
        if (!password) {
            renderForm(root, '服务端没有返回新密码，请重试');
            return;
        }
        root.innerHTML = '<div class="v2b-pwd-panel">'
            + '<strong>新密码已生成</strong>'
            + '<small>关闭后无法再次查看。请立即保存，然后用它重新登录。</small>'
            + '<code class="v2b-pwd-code">' + escapeText(password) + '</code>'
            + '<div class="v2b-pwd-actions">'
            + '<button type="button" class="btn btn-alt-secondary" data-pwd-action="copy">复制密码</button>'
            + '<button type="button" class="btn btn-primary" data-pwd-action="relogin">我已保存，重新登录</button>'
            + '</div></div>';
        root.querySelector('[data-pwd-action="copy"]').onclick = function () { copyText(password, this); };
        // 服务端已经把所有会话杀了，本页其它请求从此会 403。不自动跳转，等用户确认已保存
        // 才退出 —— 新密码只显示这一次，自动跳转会把它带走。
        root.querySelector('[data-pwd-action="relogin"]').onclick = function () {
            try { localStorage.removeItem('authorization'); } catch (error) { /* ignore */ }
            window.location.hash = '#/login';
            window.location.reload();
        };
    }

    function mountInline() {
        var root = document.getElementById('v2board-password-reset-inline');
        if (!root || root.getAttribute('data-loaded')) return;
        addStyle();
        root.setAttribute('data-loaded', '1');
        renderForm(root, '');
    }

    var inlineObserver;

    function watchInline() {
        if (inlineObserver || !document.body || !window.MutationObserver) return;
        inlineObserver = new MutationObserver(mountInline);
        inlineObserver.observe(document.body, { childList: true, subtree: true });
    }

    function mount() {
        watchInline();
        mountInline();
        loadStatus();
    }

    // hashchange 里也要 loadStatus()：刚登录/注册完那一次，三个 mount() 定时器早就跑过了，
    // 而当时还没有 authorization，所以状态从没拉到过。loadStatus 自身有幂等守卫，登录后
    // 第一次路由跳转会补上，注册完立刻就能看到横幅，而不是等下次刷新。
    window.addEventListener('hashchange', function () { mountInline(); loadStatus(); });
    document.addEventListener('DOMContentLoaded', mount);
    setTimeout(mount, 700);
    setTimeout(mount, 1800);
})();
