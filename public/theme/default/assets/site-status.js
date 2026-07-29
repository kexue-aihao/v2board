(function () {
    'use strict';

    var root = document.getElementById('root');
    if (!root || !window.fetch) return;
    root.style.visibility = 'hidden';
    var overlay = null;
    var refreshing = false;
    var booted = false;

    function text(value, fallback) {
        return typeof value === 'string' && value.trim() ? value.trim() : fallback;
    }

    function normalize(payload) {
        var status = payload && payload.data && payload.data.site_status || {};
        var mode = status.mode === 'maintenance' || status.mode === 'shutdown' ? status.mode : 'normal';
        return {
            mode: mode,
            title: text(status.title, mode === 'shutdown' ? '服务暂时停止' : '服务正在维护'),
            message: text(status.message, '系统正在进行例行处理，请稍后再试。'),
            recovery_at: Number(status.recovery_at || 0),
            support_url: /^https?:\/\//i.test(status.support_url || '') ? status.support_url : ''
        };
    }

    function removeOverlay() {
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = null;
        root.style.visibility = 'visible';
    }

    function appendText(parent, tag, className, value) {
        var element = document.createElement(tag);
        if (className) element.className = className;
        element.textContent = value;
        parent.appendChild(element);
        return element;
    }

    function bootApplication() {
        if (booted) return;
        booted = true;
        var scripts = Array.isArray(window.__v2boardSiteStatusScripts) ? window.__v2boardSiteStatusScripts : [];
        var load = function (index) {
            if (index >= scripts.length) return;
            var script = document.createElement('script');
            script.src = scripts[index];
            script.onload = function () { load(index + 1); };
            script.onerror = function () { renderError('页面资源加载失败，请刷新页面后重试。'); };
            document.body.appendChild(script);
        };
        load(0);
    }

    function renderError(message) {
        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = document.createElement('main');
        overlay.id = 'v2board-site-status';
        overlay.className = 'site-status-error';
        overlay.setAttribute('role', 'alert');
        overlay.setAttribute('aria-live', 'assertive');
        var shell = document.createElement('div');
        shell.className = 'site-status-shell';
        var copy = document.createElement('section');
        copy.className = 'site-status-copy';
        appendText(copy, 'span', 'site-status-kicker', 'SERVICE STATUS');
        appendText(copy, 'h1', '', '暂时无法读取站点状态');
        appendText(copy, 'p', '', message);
        var actions = document.createElement('div');
        actions.className = 'site-status-actions';
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.textContent = '再次检查';
        retry.addEventListener('click', refresh);
        actions.appendChild(retry);
        copy.appendChild(actions);
        shell.appendChild(copy);
        overlay.appendChild(shell);
        document.body.appendChild(overlay);
        root.style.visibility = 'hidden';
    }

    function render(status) {
        if (status.mode === 'normal') {
            removeOverlay();
            bootApplication();
            return;
        }

        if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
        overlay = document.createElement('main');
        overlay.id = 'v2board-site-status';
        overlay.className = 'site-status-' + status.mode;
        overlay.setAttribute('aria-labelledby', 'v2board-site-status-title');
        overlay.setAttribute('aria-live', 'polite');

        var shell = document.createElement('div');
        shell.className = 'site-status-shell';
        var visual = document.createElement('div');
        visual.className = 'site-status-visual';
        visual.setAttribute('aria-hidden', 'true');
        appendText(visual, 'span', 'site-status-orbit', '');
        appendText(visual, 'span', 'site-status-mark', status.mode === 'shutdown' ? '×' : '⌁');
        shell.appendChild(visual);

        var copy = document.createElement('section');
        copy.className = 'site-status-copy';
        appendText(copy, 'span', 'site-status-kicker', status.mode === 'shutdown' ? 'SERVICE NOTICE' : 'SERVICE UPDATE');
        var title = appendText(copy, 'h1', '', status.title);
        title.id = 'v2board-site-status-title';
        appendText(copy, 'p', '', status.message);
        if (status.recovery_at > 0) {
            var recovery = document.createElement('p');
            recovery.className = 'site-status-recovery';
            appendText(recovery, 'span', '', '预计恢复');
            var time = appendText(recovery, 'time', '', new Date(status.recovery_at * 1000).toLocaleString('zh-CN'));
            time.dateTime = new Date(status.recovery_at * 1000).toISOString();
            copy.appendChild(recovery);
        }

        var actions = document.createElement('div');
        actions.className = 'site-status-actions';
        if (status.mode !== 'shutdown') {
            var retry = document.createElement('button');
            retry.type = 'button';
            retry.textContent = refreshing ? '正在检查' : '再次检查';
            retry.disabled = refreshing;
            retry.addEventListener('click', refresh);
            actions.appendChild(retry);
        } else if (status.support_url) {
            var support = document.createElement('a');
            support.href = status.support_url;
            support.target = '_blank';
            support.rel = 'noreferrer';
            support.textContent = '联系支持';
            actions.appendChild(support);
        }
        copy.appendChild(actions);
        shell.appendChild(copy);
        overlay.appendChild(shell);
        document.body.appendChild(overlay);
        root.style.visibility = 'hidden';
    }

    function refresh() {
        if (refreshing) return;
        refreshing = true;
        if (overlay) render(normalize({ data: { site_status: { mode: 'maintenance', title: '服务正在维护', message: '正在检查最新状态。' } } }));
        fetch('/api/v1/guest/comm/config', { headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (response) { if (!response.ok) throw new Error('status request failed'); return response.json(); })
            .then(function (payload) { refreshing = false; render(normalize(payload)); })
            .catch(function () { refreshing = false; renderError('暂时无法读取站点状态，请稍后重试。'); });
    }

    window.__v2boardSiteStatusRefresh = refresh;
    refresh();
})();
