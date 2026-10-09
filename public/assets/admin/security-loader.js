(function () {
    'use strict';
    var prefix = '/api/v1/' + window.settings.secure_path;
    var initialAuthorization = localStorage.getItem('authorization');
    var loading = false;
    var failed = false;
    var root = document.getElementById('root');
    var startup = document.getElementById('admin-startup');
    var startupObserver;
    var securityCacheKey = 'v2board.admin.security:' + window.settings.secure_path;
    var cachedSecurity = null;
    try {
        var cached = JSON.parse(sessionStorage.getItem(securityCacheKey) || 'null');
        if (initialAuthorization && cached && cached.authorization === initialAuthorization
            && cached.assetVersion === window.settings.admin_asset_version
            && cached.security && cached.security.role && cached.security.role !== 'guest'
            && Array.isArray(cached.security.menus)) {
            cachedSecurity = cached.security;
        }
    } catch (ignore) {}
    function showWorkspace(security) {
        document.documentElement.classList.add('admin-startup-authenticated');
        var menu = document.getElementById('admin-startup-menu');
        if (!menu || !security) return;
        menu.textContent = '';
        var path = location.hash.replace(/^#/, '').split('?')[0];
        security.menus.forEach(function (item) {
            var label = document.createElement('span');
            label.className = 'admin-startup__menu-item';
            label.textContent = item.title;
            menu.appendChild(label);
            if (item.href === path) document.getElementById('admin-startup-page-title').textContent = item.title;
        });
    }
    if (initialAuthorization) showWorkspace(cachedSecurity);
    function cacheSecurity(security) {
        try {
            sessionStorage.setItem(securityCacheKey, JSON.stringify({
                authorization: initialAuthorization,
                assetVersion: window.settings.admin_asset_version,
                security: security
            }));
        } catch (ignore) {}
    }
    function progress(title, message) {
        if (!startup) return;
        document.getElementById('admin-startup-title').textContent = title;
        document.getElementById('admin-startup-message').textContent = message;
    }
    function ready() {
        if (failed) return;
        if (!root.querySelector('#sidebar, .v2board-auth-box')) return;
        var styles = document.querySelectorAll('link[data-admin-style]');
        for (var i = 0; i < styles.length; i++) {
            if (styles[i].dataset.failed === 'true') return fail(new Error('后台样式加载失败，请刷新重试'));
            if (!styles[i].sheet || styles[i].media !== 'all') return;
        }
        startupObserver.disconnect();
        // Swap the initial layout only after React and its styles can paint.
        requestAnimationFrame(function () { requestAnimationFrame(function () {
            root.removeAttribute('aria-busy');
            startup.remove();
        }); });
    }
    if (startup) {
        startupObserver = new MutationObserver(ready);
        startupObserver.observe(root, {childList: true, subtree: true});
    }
    function fail(error) {
        if (failed) return;
        failed = true;
        if (startupObserver) startupObserver.disconnect();
        if (startup) startup.remove();
        root.removeAttribute('aria-busy');
        root.setAttribute('role', 'alert');
        root.textContent = error.message || '后台加载失败，请刷新重试';
        root.style.cssText = 'padding:32px;max-width:720px;margin:auto;font:16px/1.7 sans-serif';
    }
    function request(path, options) {
        options = options || {};
        // Code may reuse the private HTTP cache only after server revalidation.
        // Business responses and permission checks always bypass the cache.
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
        progress('正在加载登录页面', '请稍候，即将进入登录界面');
        try { sessionStorage.removeItem(securityCacheKey); } catch (ignore) {}
        document.documentElement.classList.remove('admin-startup-authenticated');
        localStorage.removeItem('authorization'); initialAuthorization = null;
        window.adminSecurity = {role: 'guest', menus: [], landing: '/login', version: 0};
        guard();
        return fetch('/assets/admin/login.js?v=' + window.settings.admin_asset_version, {cache: 'no-store'})
            .then(function (r) { if (!r.ok) throw new Error('登录资源加载失败'); return r.text(); }).then(execute);
    }
    function start() {
        if (!initialAuthorization) return guest();
        progress('正在打开管理后台', '页面即将就绪');
        return request('/security/bootstrap').then(function (security) {
            window.adminSecurity = security;
            cacheSecurity(security);
            guard();
            showWorkspace(security);
            // This is a deterrent only. It never disables authorization or auditing.
            if (!security.debug_exempt) document.addEventListener('keydown', function (event) {
                if (event.key === 'F12' || ((event.ctrlKey || event.metaKey) && event.shiftKey && /^(i|j|c)$/i.test(event.key))) event.preventDefault();
            }, true);
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
            cacheSecurity(security);
            if (security.role !== window.adminSecurity.role || security.version !== window.adminSecurity.version) location.reload();
        }).catch(function (error) {
            if (error.status === 401 || error.status === 403) {
                try { sessionStorage.removeItem(securityCacheKey); } catch (ignore) {}
                localStorage.removeItem('authorization'); location.reload();
            }
        });
    }
    window.addEventListener('focus', refreshRole);
    document.querySelectorAll('link[data-admin-style]').forEach(function (link) {
        if (link.dataset.failed === 'true') fail(new Error('后台样式加载失败，请刷新重试'));
        link.addEventListener('load', ready);
        link.addEventListener('error', function () { fail(new Error('后台样式加载失败，请刷新重试')); });
    });
    setInterval(refreshRole, 30000);
    start().catch(fail);
})();
