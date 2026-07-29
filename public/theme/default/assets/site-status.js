(function () {
    'use strict';

    var root = document.getElementById('root');
    if (!root || !window.fetch) return;
    root.style.visibility = 'hidden';
    var overlay = null;
    var refreshing = false;
    var booted = false;
    var countdownTimer = null;

    var labels = {
        shutdown: '\u670d\u52a1\u6682\u65f6\u505c\u6b62',
        maintenance: '\u670d\u52a1\u6b63\u5728\u7ef4\u62a4',
        message: '\u7cfb\u7edf\u6b63\u5728\u8fdb\u884c\u4f8b\u884c\u5904\u7406\uff0c\u8bf7\u7a0d\u540e\u518d\u8bd5\u3002',
        readFailed: '\u6682\u65f6\u65e0\u6cd5\u8bfb\u53d6\u7ad9\u70b9\u72b6\u6001\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5\u3002',
        resourceFailed: '\u9875\u9762\u8d44\u6e90\u52a0\u8f7d\u5931\u8d25\uff0c\u8bf7\u5237\u65b0\u9875\u9762\u540e\u91cd\u8bd5\u3002',
        recovery: '\u9884\u8ba1\u6062\u590d',
        countdown: '\u5012\u8ba1\u65f6',
        days: '\u5929',
        hours: '\u65f6',
        minutes: '\u5206',
        seconds: '\u79d2',
        pending: '\u6062\u590d\u65f6\u95f4\u5f85\u5b9a',
        remaining: '\u5269\u4f59',
        reached: '\u6062\u590d\u65f6\u95f4\u5df2\u5230\uff0c\u8bf7\u518d\u6b21\u68c0\u67e5',
        retry: '\u518d\u6b21\u68c0\u67e5',
        checking: '\u6b63\u5728\u68c0\u67e5',
        support: '\u8054\u7cfb\u652f\u6301',
        checkingMessage: '\u6b63\u5728\u68c0\u67e5\u6700\u65b0\u72b6\u6001\u3002'
    };

    function text(value, fallback) {
        return typeof value === 'string' && value.trim() ? value.trim() : fallback;
    }

    function normalize(payload) {
        var status = payload && payload.data && payload.data.site_status || {};
        var mode = status.mode === 'maintenance' || status.mode === 'shutdown' ? status.mode : 'normal';
        return {
            mode: mode,
            title: text(status.title, mode === 'shutdown' ? labels.shutdown : labels.maintenance),
            message: text(status.message, labels.message),
            recovery_at: Number(status.recovery_at || 0),
            server_time: Number(status.server_time || 0),
            support_url: /^https?:\/\//i.test(status.support_url || '') ? status.support_url : ''
        };
    }

    function stopCountdown() {
        if (countdownTimer !== null) {
            window.clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }

    function removeOverlay() {
        stopCountdown();
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
            script.onerror = function () { renderError(labels.resourceFailed); };
            document.body.appendChild(script);
        };
        load(0);
    }

    function renderError(message) {
        stopCountdown();
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
        appendText(copy, 'h1', '', '\u6682\u65f6\u65e0\u6cd5\u8bfb\u53d6\u7ad9\u70b9\u72b6\u6001');
        appendText(copy, 'p', 'site-status-message', message);
        var actions = document.createElement('div');
        actions.className = 'site-status-actions';
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.textContent = labels.retry;
        retry.addEventListener('click', refresh);
        actions.appendChild(retry);
        copy.appendChild(actions);
        shell.appendChild(copy);
        overlay.appendChild(shell);
        document.body.appendChild(overlay);
        root.style.visibility = 'hidden';
    }

    function formatRecoveryTime(timestamp) {
        return new Date(timestamp * 1000).toLocaleString('zh-CN', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });
    }

    function countdownUnit(parent, label) {
        var unit = document.createElement('span');
        unit.className = 'site-status-countdown-unit';
        var value = appendText(unit, 'strong', '', '00');
        appendText(unit, 'small', '', label);
        parent.appendChild(unit);
        return value;
    }

    function updateCountdown(status, countdown, clockOffset) {
        if (!countdown) return;
        var total = Math.max(0, Math.floor((status.recovery_at * 1000 - (Date.now() + clockOffset)) / 1000));
        var values = [
            String(Math.floor(total / 86400)).padStart(2, '0'),
            String(Math.floor((total % 86400) / 3600)).padStart(2, '0'),
            String(Math.floor((total % 3600) / 60)).padStart(2, '0'),
            String(total % 60).padStart(2, '0')
        ];
        for (var index = 0; index < countdown.values.length; index += 1) {
            countdown.values[index].textContent = values[index];
        }
        countdown.element.setAttribute('aria-label', labels.remaining + ' ' + values[0] + labels.days + values[1] + labels.hours + values[2] + labels.minutes + values[3] + labels.seconds);
        if (total === 0) {
            countdown.note.textContent = labels.reached;
            countdown.note.hidden = false;
        }
    }

    function render(status) {
        if (status.mode === 'normal') {
            removeOverlay();
            bootApplication();
            return;
        }

        stopCountdown();
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
        appendText(visual, 'span', 'site-status-signal site-status-signal-one', '');
        appendText(visual, 'span', 'site-status-signal site-status-signal-two', '');
        appendText(visual, 'span', 'site-status-mark', status.mode === 'shutdown' ? '\u00d7' : '\u231d');
        shell.appendChild(visual);

        var copy = document.createElement('section');
        copy.className = 'site-status-copy';
        appendText(copy, 'span', 'site-status-kicker', status.mode === 'shutdown' ? 'SERVICE NOTICE' : 'SERVICE UPDATE');
        var title = appendText(copy, 'h1', '', status.title);
        title.id = 'v2board-site-status-title';
        var message = appendText(copy, 'p', 'site-status-message', status.message);
        message.setAttribute('aria-live', 'polite');
        var countdown = null;
        if (status.recovery_at > 0) {
            var recovery = document.createElement('div');
            recovery.className = 'site-status-recovery';
            var recoveryHead = document.createElement('div');
            recoveryHead.className = 'site-status-recovery-head';
            appendText(recoveryHead, 'span', '', labels.recovery + labels.countdown);
            var time = appendText(recoveryHead, 'time', 'site-status-recovery-time', formatRecoveryTime(status.recovery_at));
            time.dateTime = new Date(status.recovery_at * 1000).toISOString();
            recovery.appendChild(recoveryHead);
            var countdownElement = document.createElement('div');
            countdownElement.className = 'site-status-countdown';
            countdownElement.setAttribute('role', 'timer');
            var values = [
                countdownUnit(countdownElement, labels.days),
                countdownUnit(countdownElement, labels.hours),
                countdownUnit(countdownElement, labels.minutes),
                countdownUnit(countdownElement, labels.seconds)
            ];
            var note = appendText(recovery, 'p', 'site-status-recovery-note', '');
            note.hidden = true;
            recovery.appendChild(countdownElement);
            recovery.appendChild(note);
            copy.appendChild(recovery);
            countdown = { element: countdownElement, values: values, note: note };
            var clockOffset = status.server_time > 0 ? status.server_time * 1000 - Date.now() : 0;
            updateCountdown(status, countdown, clockOffset);
            countdownTimer = window.setInterval(function () { updateCountdown(status, countdown, clockOffset); }, 1000);
        }

        var actions = document.createElement('div');
        actions.className = 'site-status-actions';
        if (status.mode !== 'shutdown') {
            var retry = document.createElement('button');
            retry.type = 'button';
            retry.textContent = refreshing ? labels.checking : labels.retry;
            retry.disabled = refreshing;
            retry.addEventListener('click', refresh);
            actions.appendChild(retry);
        } else if (status.support_url) {
            var support = document.createElement('a');
            support.href = status.support_url;
            support.target = '_blank';
            support.rel = 'noreferrer';
            support.textContent = labels.support;
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
        if (overlay) render(normalize({ data: { site_status: { mode: 'maintenance', title: labels.maintenance, message: labels.checkingMessage } } }));
        fetch('/api/v1/guest/comm/config', { headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (response) { if (!response.ok) throw new Error('status request failed'); return response.json(); })
            .then(function (payload) { refreshing = false; render(normalize(payload)); })
            .catch(function () { refreshing = false; renderError(labels.readFailed); });
    }

    window.__v2boardSiteStatusRefresh = refresh;
    refresh();
})();
