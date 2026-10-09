(function () {
    'use strict';
    var prefix = '/api/v1/' + window.settings.secure_path;
    var initialAuthorization = localStorage.getItem('authorization');
    var loading = false;
    var failed = false;
    var root = document.getElementById('root');
    var entryScript = document.getElementById('admin-entry-script');
    var observer = new MutationObserver(ready);
    observer.observe(root, {childList: true, subtree: true});
    // Retire the former presentation cache; current identity comes from the server.
    try { sessionStorage.removeItem('v2board.admin.security:' + window.settings.secure_path); } catch (ignore) {}

    function ready() {
        if (!root.querySelector('#sidebar, .v2board-auth-box')) return;
        observer.disconnect();
        root.removeAttribute('aria-busy');
    }
    function fail(error) {
        if (failed) return;
        failed = true;
        observer.disconnect();
        root.removeAttribute('aria-busy');
        root.setAttribute('role', 'alert');
        root.textContent = error.message || '后台加载失败，请刷新重试';
        root.style.cssText = 'padding:32px;max-width:720px;margin:auto;font:16px/1.7 sans-serif';
    }
    function request(path, options) {
        options = options || {};
        options.cache = options.script ? 'no-cache' : 'no-store';
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
        if (failed) return;
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
        localStorage.removeItem('authorization'); initialAuthorization = null;
        window.adminSecurity = {role: 'guest', menus: [], landing: '/login', version: 0};
        guard();
        return fetch('/assets/admin/login.js?v=' + window.settings.admin_asset_version, {cache: 'no-cache'})
            .then(function (r) { if (!r.ok) throw new Error('登录资源加载失败'); return r.text(); }).then(execute);
    }
    function protectShortcuts(security) {
        if (!security.debug_exempt) document.addEventListener('keydown', function (event) {
            if (event.key === 'F12' || ((event.ctrlKey || event.metaKey) && event.shiftKey && /^(i|j|c)$/i.test(event.key))) event.preventDefault();
        }, true);
    }
    function start() {
        // Existing Authorization-only sessions get the page cookie once here.
        // Subsequent visits discover the authenticated script in the HTML head.
        if (!initialAuthorization) return guest();
        return request('/security/bootstrap').then(function (security) {
            window.adminSecurity = security;
            guard();
            protectShortcuts(security);
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
    window.adminAssetFailure = function () {
        if (window.adminEntryValid) start().catch(fail);
        else fail(new Error('后台加载失败，请刷新重试'));
    };
    document.querySelectorAll('link[data-admin-style]').forEach(function (link) {
        if (link.dataset.failed === 'true') fail(new Error('后台样式加载失败，请刷新重试'));
        link.addEventListener('error', function () { fail(new Error('后台样式加载失败，请刷新重试')); });
    });
    if (entryScript) {
        guard();
        protectShortcuts(window.adminSecurity);
        if (window.adminAssetLoadFailed) window.adminAssetFailure();
        entryScript.addEventListener('load', ready);
    } else start().catch(fail);
})();
