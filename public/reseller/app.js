(function () {
    var auth = localStorage.getItem('reseller_auth');
    var message = document.getElementById('message');
    function show(value, bad) { message.textContent = value || ''; message.style.color = bad ? '#b42318' : '#027a48'; }
    function api(path, options) {
        options = options || {};
        options.headers = Object.assign({'Content-Type':'application/json', authorization:auth || ''}, options.headers || {});
        return fetch('/api/v1/reseller' + path, options).then(function (r) {
            return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || '请求失败'); return j; });
        });
    }
    function formBody(form) {
        var object = Object.fromEntries(new FormData(form));
        Object.keys(object).forEach(function (key) { if (object[key] === '') delete object[key]; });
        return object;
    }
    function boot() {
        if (!auth) return;
        document.getElementById('login').hidden = true;
        document.getElementById('panel').hidden = false;
        api('/me').then(function (j) {
            var form = document.getElementById('store-form');
            Object.keys(j.data).forEach(function (key) { if (form.elements[key]) form.elements[key].value = j.data[key] || ''; });
        }).catch(function (e) { localStorage.removeItem('reseller_auth'); auth = null; show(e.message, true); });
        loadTemplates(); loadPlans(); loadPayments();
    }
    function loadTemplates() {
        api('/plan-template').then(function (j) {
            document.getElementById('templates').innerHTML = j.data.map(function (template) {
                return '<button type="button" data-id="' + template.id + '">' + template.id + ' · ' + escapeHtml(template.name) + '</button>';
            }).join('') || '<span class="muted">管理员尚未发布模板</span>';
        });
    }
    function loadPlans() {
        api('/plans').then(function (j) {
            document.getElementById('plans').innerHTML = j.data.map(function (plan) {
                return '<p>' + plan.id + ' · ' + escapeHtml(plan.name) + ' · 基础套餐 ' + plan.base_plan_id + ' · 月付 ' + (plan.month_price || '-') + ' 分</p>';
            }).join('') || '<p class="muted">暂无销售套餐</p>';
        });
    }
    function loadPayments() {
        api('/payments').then(function (j) {
            document.getElementById('payments').innerHTML = j.data.map(function (p) { return '<p>' + escapeHtml(p.name) + ' · ' + p.driver + ' · ' + (p.enabled ? '已启用' : '已停用') + '</p>'; }).join('') || '<p class="muted">暂无支付配置</p>';
        });
        api('/me').then(function (j) { document.getElementById('payment-driver').innerHTML = j.data.allowed_payment_drivers.map(function (d) { return '<option value="' + d + '">' + d + '</option>'; }).join(''); });
    }
    document.getElementById('login-form').addEventListener('submit', function (e) {
        e.preventDefault();
        api('/auth/login', {method:'POST', body:JSON.stringify(formBody(e.target))}).then(function (j) {
            auth = j.data.auth_data; localStorage.setItem('reseller_auth', auth); boot();
        }).catch(function (e) { show(e.message, true); });
    });
    document.getElementById('register-form').addEventListener('submit', function (e) {
        e.preventDefault();
        api('/auth/register', {method:'POST', body:JSON.stringify(formBody(e.target))}).then(function () {
            show('申请已提交，请等待管理员审核'); e.target.reset();
        }).catch(function (e) { show(e.message, true); });
    });
    document.getElementById('store-form').addEventListener('submit', function (e) {
        e.preventDefault();
        api('/store', {method:'POST', body:JSON.stringify(formBody(e.target))}).then(function () { show('店铺设置已保存'); }).catch(function (e) { show(e.message, true); });
    });
    document.getElementById('plan-form').addEventListener('submit', function (e) {
        e.preventDefault();
        api('/plans', {method:'POST', body:JSON.stringify(formBody(e.target))}).then(function () { show('套餐已保存'); loadPlans(); }).catch(function (e) { show(e.message, true); });
    });
    document.getElementById('payment-form').addEventListener('submit', function (e) {
        e.preventDefault(); var body = formBody(e.target); body.config = JSON.parse(body.config_json || '{}'); delete body.config_json; body.enabled = e.target.elements.enabled.checked ? 1 : 0;
        api('/payments', {method:'POST', body:JSON.stringify(body)}).then(function () { show('支付配置已保存'); loadPayments(); }).catch(function (e) { show(e.message, true); });
    });
    document.getElementById('templates').addEventListener('click', function (e) {
        if (e.target.dataset.id) document.querySelector('#plan-form [name="base_plan_id"]').value = e.target.dataset.id;
    });
    document.getElementById('load-customers').addEventListener('click', function () {
        api('/customers').then(function (j) { document.getElementById('audit').textContent = '客户数量：' + j.data.total; }).catch(function (e) { show(e.message, true); });
    });
    document.getElementById('load-orders').addEventListener('click', function () {
        api('/orders').then(function (j) { document.getElementById('audit').textContent = '订单数量：' + j.data.total; }).catch(function (e) { show(e.message, true); });
    });
    function escapeHtml(value) { return String(value).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
    boot();
}());
