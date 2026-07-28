(function () {
    var auth = localStorage.getItem('authorization') || localStorage.getItem('admin_auth');
    var message = document.getElementById('message');
    var accounts = [];
    var templates = [];
    function show(value, bad) { message.textContent = value || ''; message.style.color = bad ? '#b42318' : '#027a48'; }
    function api(path, options) {
        options = options || {};
        options.headers = Object.assign({'Content-Type':'application/json', authorization:auth || ''}, options.headers || {});
        return fetch(window.ADMIN_API_PREFIX + path, options).then(function (response) {
            return response.json().then(function (data) { if (!response.ok) throw new Error(data.message || '请求失败'); return data; });
        });
    }
    function statusLabel(status) { return {pending:'待审核', active:'已启用', suspended:'已停用'}[status] || status; }
    function statusClass(status) { return status === 'pending' ? 'pending' : (status === 'active' ? 'active' : 'suspended'); }
    function renderAccounts() {
        var body = document.getElementById('accounts');
        body.innerHTML = accounts.length ? accounts.map(function (account) {
            var action = account.status === 'pending'
                ? '<button class="btn" data-id="' + account.id + '" data-status="active">审核通过</button><button class="btn warn" data-id="' + account.id + '" data-status="suspended">拒绝</button>'
                : (account.status === 'active' ? '<button class="btn warn" data-id="' + account.id + '" data-status="suspended">停用</button>' : '<button class="btn" data-id="' + account.id + '" data-status="active">重新启用</button>');
            return '<tr><td><strong>' + escapeHtml(account.email) + '</strong></td><td>' + escapeHtml(account.store_name || '-') + '<br><span class="subtle">/' + escapeHtml(account.store_slug) + '</span></td><td>' + formatDate(account.created_at) + '</td><td><span class="pill ' + statusClass(account.status) + '">' + statusLabel(account.status) + '</span></td><td><div class="toolbar">' + action + '</div></td></tr>';
        }).join('') : '<tr><td colspan="5" class="empty">暂无倒卖商账号</td></tr>';
        document.getElementById('pending-count').textContent = accounts.filter(function (a) { return a.status === 'pending'; }).length;
        document.getElementById('active-count').textContent = accounts.filter(function (a) { return a.status === 'active'; }).length;
    }
    function loadAccounts() {
        return api('/reseller/fetch').then(function (data) { accounts = data.data.data || []; renderAccounts(); });
    }
    function loadTemplates() {
        return api('/reseller/template/fetch').then(function (data) {
            templates = data.data || [];
            document.getElementById('template-count').textContent = templates.filter(function (t) { return Number(t.enabled) === 1; }).length;
            document.getElementById('templates').innerHTML = templates.length ? templates.map(function (template) {
                return '<article class="template"><div class="template-title"><span>' + escapeHtml(template.plan ? template.plan.name : '基础套餐 #' + template.base_plan_id) + '</span><span class="pill ' + (template.enabled ? 'active' : 'suspended') + '">' + (template.enabled ? '已发布' : '已撤下') + '</span></div><p>基础套餐 ID：' + template.base_plan_id + ' · 排序：' + template.sort + '</p></article>';
            }).join('') : '<div class="empty">暂无模板</div>';
        });
    }
    function loadDrivers() {
        return api('/reseller/payment-drivers').then(function (data) {
            var installed = data.data.installed || [];
            var allowed = data.data.allowed || [];
            document.getElementById('drivers').innerHTML = installed.length ? installed.map(function (driver) {
                return '<label class="driver"><strong>' + escapeHtml(driver) + '</strong><span><input type="checkbox" name="driver" value="' + escapeHtml(driver) + '" ' + (allowed.indexOf(driver) >= 0 ? 'checked' : '') + '> 允许倒卖商使用</span></label>';
            }).join('') : '<div class="empty">未发现支付驱动</div>';
        });
    }
    function load() {
        if (!auth) { show('未检测到管理员登录凭证，请先登录主管理员后台。', true); return; }
        Promise.all([loadAccounts(), loadTemplates(), loadDrivers()]).catch(function (error) { show(error.message, true); });
    }
    document.getElementById('accounts').addEventListener('click', function (event) {
        var button = event.target.closest('[data-id]');
        if (!button) return;
        api('/reseller/update', {method:'POST', body:JSON.stringify({id:button.dataset.id, status:button.dataset.status})}).then(function () { show('账号状态已更新'); return loadAccounts(); }).catch(function (error) { show(error.message, true); });
    });
    document.getElementById('template-form').addEventListener('submit', function (event) {
        event.preventDefault();
        var form = new FormData(event.target);
        api('/reseller/template/save', {method:'POST', body:JSON.stringify({base_plan_id:form.get('base_plan_id'), enabled:form.get('enabled'), sort:form.get('sort')})}).then(function () { show('套餐模板已保存'); event.target.reset(); return loadTemplates(); }).catch(function (error) { show(error.message, true); });
    });
    document.getElementById('save-drivers').addEventListener('click', function () {
        var allowed = Array.prototype.slice.call(document.querySelectorAll('#drivers input[name="driver"]:checked')).map(function (input) { return input.value; });
        api('/reseller/payment-drivers', {method:'POST', body:JSON.stringify({allowed:allowed})}).then(function () { show('支付驱动白名单已保存'); return loadDrivers(); }).catch(function (error) { show(error.message, true); });
    });
    document.getElementById('refresh').addEventListener('click', function () { show('正在刷新...'); load(); });
    document.getElementById('logout').addEventListener('click', function () { window.location.href = '/'; });
    function formatDate(value) { if (!value) return '-'; var date = new Date(Number(value) * 1000); return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0'); }
    function escapeHtml(value) { return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
    load();
}());
