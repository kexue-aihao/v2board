(function () {
    'use strict';
    var prefix = '/api/v1/' + window.settings.secure_path;
    var initialAuthorization = localStorage.getItem('authorization');
    var loading = false;
    var root = document.getElementById('root');
    var startup = document.getElementById('admin-startup');
    var startupObserver;
    function progress(title, message) {
        if (!startup) return;
        document.getElementById('admin-startup-title').textContent = title;
        document.getElementById('admin-startup-message').textContent = message;
    }
    function ready() {
        if (!root.querySelector('#sidebar, .v2board-auth-box')) return;
        startupObserver.disconnect();
        // Keep the cover until React's first frame has actually been painted.
        requestAnimationFrame(function () { requestAnimationFrame(function () {
            root.removeAttribute('aria-busy');
            startup.classList.add('is-finished');
            setTimeout(function () { startup.remove(); }, 160);
        }); });
    }
    if (startup) {
        startupObserver = new MutationObserver(ready);
        startupObserver.observe(root, {childList: true, subtree: true});
    }
    function fail(error) {
        if (startupObserver) startupObserver.disconnect();
        if (startup) startup.remove();
        root.removeAttribute('aria-busy');
        root.setAttribute('role', 'alert');
        root.textContent = error.message || '后台加载失败，请刷新重试';
        root.style.cssText = 'padding:32px;max-width:720px;margin:auto;font:16px/1.7 sans-serif';
    }
    function request(path, options) {
        options = options || {};
        options.cache = 'no-store';
        options.headers = Object.assign({Accept: 'application/json', authorization: localStorage.getItem('authorization') || ''}, options.headers);
        return fetch(prefix + path, options).then(function (response) {
            if (!response.ok) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    var error = new Error(data.message || '请求失败'); error.status = response.status; throw error;
                });
            }
            return options.script ? response.text() : response.json().then(function (body) { return body.data; });
        });
    }
    window.adminRequest = request;
    window.adminLogout = function () {
        return request('/security/logout', {method: 'POST'}).finally(function () {
            localStorage.removeItem('authorization'); location.hash = '/login'; location.reload();
        });
    };
    function execute(script) {
        var node = document.createElement('script');
        node.textContent = script;
        document.body.appendChild(node);
        node.remove();
    }
    function validPath(path, security) {
        if (security.role === 'guest') return path === '/login';
        return security.menus.some(function (menu) {
            return menu.href === path || (menu.href === '/ticket' && /^\/ticket\/\d+$/.test(path));
        }) || (path === '/rate' && security.menus.some(function (menu) { return menu.href === '/server/manage'; }));
    }
    function guard() {
        var path = location.hash.replace(/^#/, '').split('?')[0];
        if (!validPath(path, window.adminSecurity)) location.hash = window.adminSecurity.landing;
        if (initialAuthorization !== localStorage.getItem('authorization') && !loading) { loading = true; location.reload(); }
    }
    function guest() {
        progress('正在加载登录页面', '请稍候，即将进入登录界面');
        localStorage.removeItem('authorization'); initialAuthorization = null;
        window.adminSecurity = {role: 'guest', menus: [], landing: '/login', version: 0};
        guard();
        return fetch('/assets/admin/login.js?v=' + window.settings.admin_asset_version, {cache: 'no-store'})
            .then(function (r) { if (!r.ok) throw new Error('登录资源加载失败'); return r.text(); }).then(execute);
    }
    function start() {
        if (!initialAuthorization) return guest();
        progress('正在加载权限', '正在确认您的后台访问权限');
        return request('/security/bootstrap').then(function (security) {
            window.adminSecurity = security;
            guard();
            // This is a deterrent only. It never disables authorization or auditing.
            if (!security.debug_exempt) document.addEventListener('keydown', function (event) {
                if (event.key === 'F12' || ((event.ctrlKey || event.metaKey) && event.shiftKey && /^(i|j|c)$/i.test(event.key))) event.preventDefault();
            }, true);
            progress('正在进入管理后台', '正在为您准备工作台，请稍候');
            return request('/security/asset', {script: true}).then(execute);
        }).catch(function (error) {
            if (error.status === 401 || error.status === 403) return guest();
            throw error;
        });
    }
    window.addEventListener('hashchange', guard);
    window.addEventListener('storage', function (event) { if (event.key === 'authorization') location.reload(); });
    function refreshRole() {
        if (!window.adminSecurity || window.adminSecurity.role === 'guest' || loading || window.adminSecurityRecoveryPending) return;
        request('/security/bootstrap').then(function (security) {
            if (security.role !== window.adminSecurity.role || security.version !== window.adminSecurity.version) location.reload();
        }).catch(function (error) {
            if (error.status === 401 || error.status === 403) {
                localStorage.removeItem('authorization'); location.reload();
            }
        });
    }
    window.addEventListener('focus', refreshRole);
    setInterval(refreshRole, 30000);
    start().catch(fail);
})();
