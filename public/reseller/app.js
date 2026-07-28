(function () {
    var auth = localStorage.getItem('reseller_auth');
    var message = document.getElementById('message');

    function show(value, bad) {
        message.textContent = value || '';
        message.style.color = bad ? '#b42318' : '#027a48';
    }

    function api(path, options) {
        options = options || {};
        options.headers = Object.assign({'Content-Type': 'application/json', authorization: auth || ''}, options.headers || {});
        return fetch('/api/v1/reseller' + path, options).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) throw new Error(data.message || '请求失败');
                return data;
            });
        });
    }

    function formBody(form) {
        var object = Object.fromEntries(new FormData(form));
        Object.keys(object).forEach(function (key) { if (object[key] === '') delete object[key]; });
        return object;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
        });
    }

    function money(value) { return value ? '¥' + (Number(value) / 100).toFixed(2) : '-'; }

    function boot() {
        if (!auth) return;
        document.getElementById('login').hidden = true;
        document.getElementById('panel').hidden = false;
        api('/me').then(function (data) {
            var form = document.getElementById('store-form');
            Object.keys(data.data).forEach(function (key) {
                if (form.elements[key]) form.elements[key].value = data.data[key] || '';
            });
        }).catch(function (error) {
            localStorage.removeItem('reseller_auth');
            auth = null;
            document.getElementById('login').hidden = false;
            document.getElementById('panel').hidden = true;
            show(error.message, true);
        });
        loadTemplates();
        loadPlans();
        loadPayments();
    }

    function loadTemplates() {
        api('/plan-template').then(function (data) {
            document.getElementById('templates').innerHTML = data.data.length ? data.data.map(function (template) {
                return '<button type="button" data-id="' + template.id + '">' + escapeHtml(template.name) + '<small style="display:block;margin-top:3px;opacity:.7">模板 #' + template.id + '</small></button>';
            }).join('') : '<span class="empty">管理员暂未发布可销售套餐</span>';
        }).catch(function (error) { show(error.message, true); });
    }

    function loadPlans() {
        api('/plans').then(function (data) {
            document.getElementById('plans').innerHTML = data.data.length ? data.data.map(function (plan) {
                var prices = [money(plan.month_price) + ' 月付', money(plan.quarter_price) + ' 季付', money(plan.year_price) + ' 年付'].join(' · ');
                return '<div class="data-row"><strong>' + escapeHtml(plan.name) + '<br><small class="muted">基础套餐 #' + plan.base_plan_id + '</small></strong><span>' + prices + '</span></div>';
            }).join('') : '<div class="empty">还没有销售套餐，请从右侧选择基础套餐。</div>';
        }).catch(function (error) { show(error.message, true); });
    }

    function loadPayments() {
        api('/payments').then(function (data) {
            document.getElementById('payments').innerHTML = data.data.length ? data.data.map(function (payment) {
                return '<div class="data-row"><strong>' + escapeHtml(payment.name) + '<br><small class="muted">' + escapeHtml(payment.driver) + '</small></strong><span>' + (payment.enabled ? '已启用' : '已停用') + '</span></div>';
            }).join('') : '<div class="empty">还没有支付配置</div>';
        }).catch(function (error) { show(error.message, true); });
        api('/me').then(function (data) {
            document.getElementById('payment-driver').innerHTML = (data.data.allowed_payment_drivers || []).map(function (driver) {
                return '<option value="' + escapeHtml(driver) + '">' + escapeHtml(driver) + '</option>';
            }).join('');
        }).catch(function (error) { show(error.message, true); });
    }

    document.getElementById('login-form').addEventListener('submit', function (event) {
        event.preventDefault();
        api('/auth/login', {method: 'POST', body: JSON.stringify(formBody(event.target))}).then(function (data) {
            auth = data.data.auth_data;
            localStorage.setItem('reseller_auth', auth);
            show('登录成功');
            boot();
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('register-form').addEventListener('submit', function (event) {
        event.preventDefault();
        api('/auth/register', {method: 'POST', body: JSON.stringify(formBody(event.target))}).then(function () {
            show('申请已提交，请等待管理员审核');
            event.target.reset();
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('store-form').addEventListener('submit', function (event) {
        event.preventDefault();
        api('/store', {method: 'POST', body: JSON.stringify(formBody(event.target))}).then(function () {
            show('店铺设置已保存');
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('plan-form').addEventListener('submit', function (event) {
        event.preventDefault();
        api('/plans', {method: 'POST', body: JSON.stringify(formBody(event.target))}).then(function () {
            show('销售套餐已保存');
            loadPlans();
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('payment-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var body = formBody(event.target);
        try {
            body.config = JSON.parse(body.config_json || '{}');
        } catch (error) {
            show('配置 JSON 格式不正确', true);
            return;
        }
        delete body.config_json;
        body.enabled = event.target.elements.enabled.checked ? 1 : 0;
        api('/payments', {method: 'POST', body: JSON.stringify(body)}).then(function () {
            show('支付配置已保存');
            loadPayments();
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('templates').addEventListener('click', function (event) {
        if (event.target.dataset.id) document.querySelector('#plan-form [name="base_plan_id"]').value = event.target.dataset.id;
    });

    document.getElementById('load-customers').addEventListener('click', function () {
        api('/customers').then(function (data) {
            document.getElementById('audit').innerHTML = '<div class="data-row"><strong>关联客户</strong><span>' + Number(data.data.total || 0) + ' 人</span></div>';
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('load-orders').addEventListener('click', function () {
        api('/orders').then(function (data) {
            document.getElementById('audit').innerHTML = '<div class="data-row"><strong>销售订单</strong><span>' + Number(data.data.total || 0) + ' 笔</span></div>';
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('logout').addEventListener('click', function () {
        api('/auth/logout', {method: 'POST'}).catch(function () {}).then(function () {
            localStorage.removeItem('reseller_auth');
            window.location.reload();
        });
    });

    boot();
}());
