/*
 * Reseller workspace client.
 * The admin approval module lives inside umi.js; this page is the reseller's own workspace.
 */
(function () {
    'use strict';

    var auth = localStorage.getItem('reseller_auth') || '';
    var message = document.getElementById('message');
    var serviceEnabled = document.body.dataset.resellerEnabled === '1';
    var messageTimer;
    var state = { account: null, saleEnabled: false };
    var paymentFormRequest = 0;
    var paymentFormReady = false;

    if (!serviceEnabled) return;

    function show(text, bad) {
        window.clearTimeout(messageTimer);
        message.textContent = text || '';
        message.className = 'toast' + (bad ? ' toast-error' : ' toast-success');
        message.hidden = !text;
        if (text) {
            messageTimer = window.setTimeout(function () { message.hidden = true; }, 5000);
        }
    }

    function errorMessage(data, fallback) {
        if (data && data.message) return data.message;
        if (data && data.errors) {
            var first = Object.keys(data.errors).map(function (key) {
                return Array.isArray(data.errors[key]) ? data.errors[key][0] : data.errors[key];
            })[0];
            if (first) return first;
        }
        return fallback || '请求失败，请稍后重试';
    }

    function api(path, options) {
        options = options || {};
        var headers = {Accept: 'application/json', 'Content-Type': 'application/json'};
        if (auth) headers.Authorization = 'Bearer ' + auth;
        return fetch('/api/v1/reseller' + path, {
            method: options.method || 'GET',
            headers: Object.assign(headers, options.headers || {}),
            body: options.body
        }).then(function (response) {
            return response.text().then(function (body) {
                var data = {};
                try { data = body ? JSON.parse(body) : {}; } catch (error) {}
                if (!response.ok) throw new Error(errorMessage(data, '请求失败，请稍后重试'));
                return data;
            });
        });
    }

    function formBody(form) {
        var body = {};
        new FormData(form).forEach(function (value, key) {
            if (value !== '') body[key] = value;
        });
        return body;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
        });
    }

    function copyText(text, button) {
        function copied() {
            var original = button.textContent;
            button.textContent = '已复制';
            window.setTimeout(function () { button.textContent = original; }, 1600);
        }
        function fallback() {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); copied(); } catch (error) {}
            area.remove();
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(copied).catch(fallback);
            return;
        }
        fallback();
    }

    function renderRegistrationCredentials(password) {
        var panel = document.getElementById('registration-credentials');
        if (!panel) return;
        if (!password) {
            panel.hidden = true;
            panel.innerHTML = '';
            return;
        }
        panel.innerHTML = '<strong>安全密码已生成</strong>'
            + '<small>密码只在本次注册结果中显示，请先复制或保存，再关闭此页面。</small>'
            + '<code class="registration-password">' + escapeHtml(password) + '</code>'
            + '<button class="btn btn-quiet" type="button" data-copy-registration-password>复制密码</button>';
        panel.hidden = false;
        panel.querySelector('[data-copy-registration-password]').addEventListener('click', function () {
            copyText(password, this);
        });
    }

    function money(value) {
        return value === null || value === undefined || value === '' ? '-' : '¥' + (Number(value) / 100).toFixed(2);
    }

    function statusText(status) {
        return {pending: '待审核', active: '已启用', rejected: '已拒绝', suspended: '已停用'}[status] || '未知';
    }

    function statusClass(status) {
        return {pending: 'status-pending', active: 'status-active', rejected: 'status-rejected', suspended: 'status-suspended'}[status] || 'status-neutral';
    }

    function setButtonLoading(button, loading, text) {
        if (!button) return;
        if (loading) {
            button.dataset.originalText = button.textContent;
            button.disabled = true;
            button.textContent = text || '处理中...';
        } else {
            button.disabled = false;
            if (button.dataset.originalText) button.textContent = button.dataset.originalText;
        }
    }

    function setAuthenticated(visible) {
        document.getElementById('auth-shell').hidden = visible;
        document.getElementById('workspace').hidden = !visible;
    }

    function setSaleAccess(canSell) {
        state.saleEnabled = !!canSell;
        document.querySelectorAll('[data-sale-control]').forEach(function (element) {
            element.disabled = !canSell;
        });
        var banner = document.getElementById('approval-banner');
        if (!canSell) {
            banner.hidden = false;
            banner.className = 'approval-banner approval-banner-warning';
            banner.innerHTML = '<div><strong>店铺暂不可销售</strong><span>账号和店铺均通过审核后，才能发布套餐和配置收款方式。</span></div>';
        } else {
            banner.hidden = false;
            banner.className = 'approval-banner approval-banner-ready';
            banner.innerHTML = '<div><strong>店铺已具备销售资格</strong><span>可以发布套餐、配置支付并向客户开放销售页。</span></div>';
        }
    }

    function renderAccount(account) {
        state.account = account;
        document.getElementById('account-email').textContent = account.email || '-';
        document.getElementById('account-status').innerHTML = '<span class="status-pill ' + statusClass(account.account_status) + '">' + escapeHtml(statusText(account.account_status)) + '</span>';
        document.getElementById('store-status').innerHTML = '<span class="status-pill ' + statusClass(account.store_status) + '">' + escapeHtml(statusText(account.store_status)) + '</span>';
        document.getElementById('store-url').textContent = account.store_slug ? '/store/' + account.store_slug : '-';
        document.getElementById('store-url-inline').textContent = account.store_slug ? '/store/' + account.store_slug : '/store/{slug}';
        document.getElementById('store-url-link').href = account.store_slug ? '/store/' + encodeURIComponent(account.store_slug) : '#';
        setSaleAccess(account.can_sell);
        var accountReason = account.reseller_review_reason ? '账号备注：' + account.reseller_review_reason : '';
        var storeReason = account.store_review_reason ? '店铺备注：' + account.store_review_reason : '';
        document.getElementById('review-note').textContent = [accountReason, storeReason].filter(Boolean).join('；') || '审核状态会在管理员处理后更新。';
    }

    function fillStore(account) {
        var form = document.getElementById('store-form');
        ['store_slug', 'store_name', 'store_description'].forEach(function (key) {
            if (form.elements[key]) form.elements[key].value = account[key] || '';
        });
    }

    function loadTemplates() {
        return api('/plan-template').then(function (result) {
            var list = result.data || [];
            document.getElementById('templates').innerHTML = list.length ? list.map(function (template) {
                return '<button class="template-option" type="button" data-template-id="' + Number(template.id) + '"><strong>' + escapeHtml(template.name) + '</strong><span>模板 #' + Number(template.id) + '</span></button>';
            }).join('') : '<div class="empty-state">管理员还没有发布可销售的基础套餐。</div>';
        });
    }

    function loadPlans() {
        return api('/plans').then(function (result) {
            var list = result.data || [];
            document.getElementById('plans').innerHTML = list.length ? list.map(function (plan) {
                var prices = [money(plan.month_price) + ' 月付', money(plan.quarter_price) + ' 季付', money(plan.year_price) + ' 年付'].join(' · ');
                return '<div class="list-row"><div><strong>' + escapeHtml(plan.name) + '</strong><span>基础套餐 #' + Number(plan.base_plan_id) + '</span></div><em>' + prices + '</em></div>';
            }).join('') : '<div class="empty-state">还没有销售套餐。先从右侧选择基础套餐并设置价格。</div>';
        });
    }

    function paymentFieldLabel(definition, key) {
        var label = definition && definition.label;
        if (label && typeof label === 'object') label = label.custom || label.label;
        return String(label || key);
    }

    function renderPaymentFields(fields) {
        var container = document.getElementById('payment-fields');
        var keys = fields && typeof fields === 'object' ? Object.keys(fields) : [];
        container.innerHTML = '';
        if (!keys.length) {
            container.innerHTML = '<div class="empty-state">此支付驱动没有可配置字段。</div>';
            paymentFormReady = true;
            return;
        }
        keys.forEach(function (key) {
            var definition = fields[key] || {};
            var label = document.createElement('label');
            var input = definition.type === 'textarea' ? document.createElement('textarea') : document.createElement('input');
            label.className = 'field payment-field';
            label.appendChild(document.createTextNode(paymentFieldLabel(definition, key)));
            input.name = key;
            input.setAttribute('data-payment-field', '1');
            input.autocomplete = 'off';
            if (input.tagName === 'INPUT') input.type = 'text';
            if (definition.value !== undefined && definition.value !== null) input.value = String(definition.value);
            label.appendChild(input);
            if (definition.description) {
                var help = document.createElement('span');
                help.className = 'field-help';
                help.textContent = String(definition.description);
                label.appendChild(help);
            }
            container.appendChild(label);
        });
        paymentFormReady = true;
    }

    function renderPaymentFieldsMessage(message) {
        var container = document.getElementById('payment-fields');
        container.innerHTML = '';
        var state = document.createElement('div');
        state.className = 'empty-state';
        state.textContent = message;
        container.appendChild(state);
    }

    function loadPaymentForm(driver) {
        var request = ++paymentFormRequest;
        paymentFormReady = false;
        if (!driver) {
            renderPaymentFieldsMessage('暂无可用支付驱动。');
            return Promise.resolve();
        }
        renderPaymentFieldsMessage('正在加载支付驱动配置字段...');
        return api('/payments/form', {
            method: 'POST',
            body: JSON.stringify({driver: driver})
        }).then(function (result) {
            if (request === paymentFormRequest) renderPaymentFields(result.data || {});
        }).catch(function (error) {
            if (request === paymentFormRequest) renderPaymentFieldsMessage(error.message);
            show(error.message, true);
        });
    }

    function paymentConfig() {
        var config = {};
        document.querySelectorAll('#payment-fields [data-payment-field]').forEach(function (field) {
            config[field.name] = field.value;
        });
        return config;
    }

    function loadPayments() {
        return Promise.all([
            api('/payments').then(function (result) {
                var list = result.data || [];
                document.getElementById('payments').innerHTML = list.length ? list.map(function (payment) {
                    return '<div class="list-row"><div><strong>' + escapeHtml(payment.name) + '</strong><span>' + escapeHtml(payment.driver) + '</span></div><span class="status-dot ' + (payment.enabled ? 'is-on' : '') + '">' + (payment.enabled ? '已启用' : '已停用') + '</span></div>';
                }).join('') : '<div class="empty-state">还没有支付配置。</div>';
            }),
            api('/me').then(function (result) {
                var drivers = (result.data || {}).allowed_payment_drivers || [];
                document.getElementById('payment-driver').innerHTML = drivers.length ? drivers.map(function (driver) {
                    return '<option value="' + escapeHtml(driver) + '">' + escapeHtml(driver) + '</option>';
                }).join('') : '<option value="">暂无可用驱动</option>';
            })
        ]);
    }

    function loadWorkspace() {
        return api('/me').then(function (result) {
            renderAccount(result.data || {});
            fillStore(result.data || {});
            return Promise.all([loadTemplates(), loadPlans(), loadPayments()]).then(function () {
                return loadPaymentForm(document.getElementById('payment-driver').value);
            });
        });
    }

    function showAuthTab(tab) {
        document.querySelectorAll('[data-auth-tab]').forEach(function (button) {
            var active = button.dataset.authTab === tab;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        document.getElementById('login-form').hidden = tab !== 'login';
        document.getElementById('register-form').hidden = tab !== 'register';
        document.getElementById('auth-heading').textContent = tab === 'login' ? '登录倒卖商工作台' : '申请成为倒卖商';
        document.getElementById('auth-caption').textContent = tab === 'login' ? '使用已审核的账号继续管理你的店铺。' : '提交账号和店铺信息，等待管理员分别审核。';
    }

    document.querySelectorAll('[data-auth-tab]').forEach(function (button) {
        button.addEventListener('click', function () { showAuthTab(button.dataset.authTab); });
    });

    document.getElementById('login-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var button = event.target.querySelector('button[type="submit"]');
        setButtonLoading(button, true, '登录中...');
        api('/auth/login', {method: 'POST', body: JSON.stringify(formBody(event.target))}).then(function (result) {
            auth = result.data.auth_data;
            localStorage.setItem('reseller_auth', auth);
            setAuthenticated(true);
            renderAccount(result.data.reseller || {});
            return loadWorkspace();
        }).then(function () {
            show('已登录，欢迎回来。');
        }).catch(function (error) {
            show(error.message, true);
        }).then(function () { setButtonLoading(button, false); });
    });

    document.getElementById('register-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var body = formBody(event.target);
        var button = event.target.querySelector('button[type="submit"]');
        setButtonLoading(button, true, '提交中...');
        api('/auth/register', {method: 'POST', body: JSON.stringify(body)}).then(function (result) {
            var data = result.data || {};
            renderRegistrationCredentials(data.password);
            show(data.message || '申请已提交，请等待管理员审核。');
            event.target.reset();
            showAuthTab('login');
        }).catch(function (error) {
            show(error.message, true);
        }).then(function () { setButtonLoading(button, false); });
    });

    document.getElementById('store-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var button = event.target.querySelector('button[type="submit"]');
        setButtonLoading(button, true, '保存中...');
        api('/store', {method: 'POST', body: JSON.stringify(formBody(event.target))}).then(function (result) {
            renderAccount(result.data || state.account || {});
            show('店铺信息已保存。');
        }).catch(function (error) {
            show(error.message, true);
        }).then(function () { setButtonLoading(button, false); });
    });

    document.getElementById('plan-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var button = event.target.querySelector('button[type="submit"]');
        setButtonLoading(button, true, '保存中...');
        api('/plans', {method: 'POST', body: JSON.stringify(formBody(event.target))}).then(function () {
            show('销售套餐已保存。');
            return loadPlans();
        }).catch(function (error) {
            show(error.message, true);
        }).then(function () { setButtonLoading(button, false); });
    });

    document.getElementById('payment-driver').addEventListener('change', function (event) {
        loadPaymentForm(event.target.value);
    });

    document.getElementById('payment-form').addEventListener('submit', function (event) {
        event.preventDefault();
        if (!paymentFormReady) {
            show('请先选择支付驱动并加载配置字段。', true);
            return;
        }
        var body = formBody(event.target);
        body.config = paymentConfig();
        body.enabled = event.target.elements.enabled.checked ? 1 : 0;
        var button = event.target.querySelector('button[type="submit"]');
        setButtonLoading(button, true, '保存中...');
        api('/payments', {method: 'POST', body: JSON.stringify(body)}).then(function () {
            show('支付配置已保存。');
            return loadPayments();
        }).catch(function (error) {
            show(error.message, true);
        }).then(function () { setButtonLoading(button, false); });
    });

    document.getElementById('templates').addEventListener('click', function (event) {
        var option = event.target.closest('[data-template-id]');
        if (!option) return;
        document.getElementById('plan-form').elements.base_plan_id.value = option.dataset.templateId;
        document.getElementById('plan-form').elements.name.focus();
        show('已选择基础套餐 #' + option.dataset.templateId + '，请继续填写销售名称和价格。');
    });

    document.getElementById('load-customers').addEventListener('click', function (event) {
        var button = event.currentTarget;
        setButtonLoading(button, true, '读取中...');
        api('/customers').then(function (result) {
            document.getElementById('audit').innerHTML = '<div class="audit-stat"><strong>关联客户</strong><span>' + Number((result.data || {}).total || 0) + ' 人</span></div>';
        }).catch(function (error) {
            show(error.message, true);
        }).then(function () { setButtonLoading(button, false); });
    });

    document.getElementById('load-orders').addEventListener('click', function (event) {
        var button = event.currentTarget;
        setButtonLoading(button, true, '读取中...');
        api('/orders').then(function (result) {
            document.getElementById('audit').innerHTML = '<div class="audit-stat"><strong>销售订单</strong><span>' + Number((result.data || {}).total || 0) + ' 笔</span></div>';
        }).catch(function (error) {
            show(error.message, true);
        }).then(function () { setButtonLoading(button, false); });
    });

    document.getElementById('logout').addEventListener('click', function () {
        api('/auth/logout', {method: 'POST' }).catch(function () {}).then(function () {
            localStorage.removeItem('reseller_auth');
            window.location.reload();
        });
    });

    document.getElementById('refresh-workspace').addEventListener('click', function (event) {
        var button = event.currentTarget;
        setButtonLoading(button, true, '刷新中...');
        loadWorkspace().then(function () {
            show('数据已刷新。');
        }).catch(function (error) {
            show(error.message, true);
        }).then(function () { setButtonLoading(button, false); });
    });

    function boot() {
        if (!auth) {
            setAuthenticated(false);
            showAuthTab('login');
            return;
        }
        setAuthenticated(true);
        loadWorkspace().catch(function (error) {
            localStorage.removeItem('reseller_auth');
            auth = '';
            setAuthenticated(false);
            show(error.message || '登录状态已失效，请重新登录。', true);
        });
    }

    boot();
}());
