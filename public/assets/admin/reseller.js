(function () {
    'use strict';
    var state = {root: null, tab: 'accounts', keyword: '', accountStatus: '', storeStatus: '', summary: {}, accounts: {data: [], total: 0, current_page: 1, last_page: 1}, stores: {data: [], total: 0, current_page: 1, last_page: 1}, orders: {data: [], total: 0, current_page: 1, last_page: 1}, templates: [], installedDrivers: [], allowedDrivers: [], modal: null, loading: false, saving: false, error: '', keyBound: false};

    function api(path, options) {
        options = options || {};
        var headers = {Accept: 'application/json'};
        var authorization = localStorage.getItem('authorization');
        if (authorization) headers.authorization = authorization;
        if (options.body) headers['Content-Type'] = 'application/json';
        return fetch('/api/v1/' + window.settings.secure_path + path, {method: options.method || 'GET', headers: headers, body: options.body}).then(function (response) {
            return response.text().then(function (body) {
                var data = {};
                try { data = body ? JSON.parse(body) : {}; } catch (error) {}
                if (!response.ok) throw new Error(data.message || '请求失败');
                return data;
            });
        });
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
        });
    }

    function statusText(status) { return {pending: '待审核', active: '已启用', rejected: '已拒绝', suspended: '已停用'}[status] || status || '-'; }
    function statusTag(status) {
        var classes = {pending: 'badge-warning', active: 'badge-success', rejected: 'badge-danger', suspended: 'badge-secondary'};
        return '<span class="badge ' + (classes[status] || 'badge-light') + '">' + escapeHtml(statusText(status)) + '</span>';
    }
    function dateText(value) {
        if (!value) return '-';
        var number = Number(value);
        var date = new Date(number > 1000000000000 ? number : number * 1000);
        if (isNaN(date.getTime())) date = new Date(value);
        return isNaN(date.getTime()) ? '-' : date.toLocaleString();
    }
    function query(status, page) {
        var values = ['current=' + (page || 1), 'pageSize=20'];
        if (status) values.push('status=' + encodeURIComponent(status));
        if (state.keyword) values.push('keyword=' + encodeURIComponent(state.keyword));
        return '?' + values.join('&');
    }
    function loadAll() {
        state.loading = true;
        draw();
        Promise.all([api('/reseller/summary'), api('/reseller/accounts' + query(state.accountStatus, 1)), api('/reseller/stores' + query(state.storeStatus, 1)), api('/reseller/templates'), api('/reseller/payment-drivers'), api('/reseller/orders' + query('', 1))]).then(function (results) {
            state.summary = results[0].data || {};
            state.accounts = results[1].data || state.accounts;
            state.stores = results[2].data || state.stores;
            state.templates = results[3].data || [];
            state.installedDrivers = (results[4].data || {}).installed || [];
            state.allowedDrivers = (results[4].data || {}).allowed || [];
            state.orders = results[5].data || state.orders;
            state.loading = false;
            state.error = '';
            draw();
        }).catch(function (error) { state.loading = false; state.error = error.message; draw(); });
    }
    function loadTable(kind, page) {
        state.loading = true;
        draw();
        var status = kind === 'accounts' ? state.accountStatus : kind === 'stores' ? state.storeStatus : '';
        api('/reseller/' + kind + query(status, page)).then(function (result) {
            state[kind] = result.data || state[kind];
            state.loading = false;
            state.error = '';
            draw();
        }).catch(function (error) { state.loading = false; state.error = error.message; draw(); });
    }
    function actions(item, target) {
        var status = target === 'account' ? (item.reseller_status || item.status) : (item.store_status || item.status);
        if (status === 'pending') return '<button class="btn btn-sm btn-primary mr-1" data-action="review" data-target="' + target + '" data-id="' + item.id + '" data-status="active">审核通过</button><button class="btn btn-sm btn-alt-danger" data-action="review" data-target="' + target + '" data-id="' + item.id + '" data-status="rejected">拒绝</button>';
        if (status === 'active') return '<button class="btn btn-sm btn-alt-danger" data-action="review" data-target="' + target + '" data-id="' + item.id + '" data-status="suspended">停用</button>';
        return '<button class="btn btn-sm btn-alt-primary" data-action="review" data-target="' + target + '" data-id="' + item.id + '" data-status="' + (status === 'suspended' ? 'active' : 'pending') + '">' + (status === 'suspended' ? '重新启用' : '重新审核') + '</button>';
    }
    function pager(page, kind) {
        var current = Number(page.current_page || 1), last = Number(page.last_page || 1);
        return '<div class="d-flex justify-content-between align-items-center mt-3"><span class="text-muted font-size-sm">共 ' + Number(page.total || 0) + ' 条</span><div><button class="btn btn-sm btn-alt-secondary mr-1" data-action="page" data-kind="' + kind + '" data-page="' + (current - 1) + '" ' + (current <= 1 ? 'disabled' : '') + '>上一页</button><span class="text-muted font-size-sm mr-1">' + current + ' / ' + last + '</span><button class="btn btn-sm btn-alt-secondary" data-action="page" data-kind="' + kind + '" data-page="' + (current + 1) + '" ' + (current >= last ? 'disabled' : '') + '>下一页</button></div></div>';
    }
    function accountTable() {
        var rows = state.accounts.data || [];
        var body = rows.length ? rows.map(function (item) {
            return '<tr><td><strong>' + escapeHtml(item.email) + '</strong></td><td>' + escapeHtml(item.store_name || '-') + '<br><small class="text-muted">/' + escapeHtml(item.store_slug || '') + '</small></td><td>' + dateText(item.created_at) + '</td><td>' + Number(item.customers_count || 0) + ' / ' + Number(item.orders_count || 0) + '</td><td>' + statusTag(item.reseller_status || item.status) + '</td><td class="text-right text-nowrap">' + actions(item, 'account') + '</td></tr>';
        }).join('') : '<tr><td colspan="6" class="text-center text-muted py-5">暂无倒卖商申请</td></tr>';
        return '<div class="block block-rounded"><div class="block-header block-header-default"><h3 class="block-title">倒卖商账号审批</h3><span class="text-muted font-size-sm">账号通过后才能登录</span></div><div class="block-content"><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>账号</th><th>店铺</th><th>注册时间</th><th>客户 / 订单</th><th>状态</th><th class="text-right">操作</th></tr></thead><tbody>' + body + '</tbody></table></div>' + pager(state.accounts, 'accounts') + '</div></div>';
    }
    function storeTable() {
        var rows = state.stores.data || [];
        var body = rows.length ? rows.map(function (item) {
            var preview = item.store_status === 'active' && item.reseller_status === 'active' ? '<a class="btn btn-sm btn-alt-secondary ml-1" href="/store/' + encodeURIComponent(item.store_slug) + '" target="_blank" rel="noreferrer">预览</a>' : '';
            return '<tr><td><strong>' + escapeHtml(item.store_name || '-') + '</strong><br><small class="text-muted">/' + escapeHtml(item.store_slug || '') + '</small></td><td>' + escapeHtml(item.email || '-') + '</td><td>' + Number(item.plan_count || 0) + ' 套套餐 / ' + Number(item.payment_count || 0) + ' 个支付</td><td>' + statusTag(item.store_status || item.status) + '</td><td class="text-right text-nowrap">' + actions(item, 'store') + preview + '</td></tr>';
        }).join('') : '<tr><td colspan="5" class="text-center text-muted py-5">暂无店铺申请</td></tr>';
        return '<div class="block block-rounded"><div class="block-header block-header-default"><h3 class="block-title">店铺注册审批</h3><span class="text-muted font-size-sm">店铺启用后才能对外销售</span></div><div class="block-content"><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>店铺</th><th>所有者</th><th>销售资源</th><th>状态</th><th class="text-right">操作</th></tr></thead><tbody>' + body + '</tbody></table></div>' + pager(state.stores, 'stores') + '</div></div>';
    }
    function permissions() {
        var templates = state.templates.length ? state.templates.map(function (item) { return '<tr><td>' + escapeHtml(item.plan ? item.plan.name : ('#' + item.base_plan_id)) + '</td><td>' + Number(item.sort || 0) + '</td><td>' + statusTag(item.enabled ? 'active' : 'suspended') + '</td></tr>'; }).join('') : '<tr><td colspan="3" class="text-center text-muted py-4">暂无套餐模板</td></tr>';
        var drivers = state.installedDrivers.length ? state.installedDrivers.map(function (driver) { return '<label class="d-flex align-items-center mb-2"><input class="mr-2" type="checkbox" data-driver="' + escapeHtml(driver) + '"' + (state.allowedDrivers.indexOf(driver) >= 0 ? ' checked' : '') + '>' + escapeHtml(driver) + '</label>'; }).join('') : '<p class="text-muted">暂无已安装支付驱动</p>';
        return '<div class="row"><div class="col-xl-7"><div class="block block-rounded"><div class="block-header block-header-default"><h3 class="block-title">基础套餐发布</h3></div><div class="block-content"><form data-form="template" class="form-inline mb-3"><input class="form-control mr-1 mb-1" name="base_plan_id" type="number" placeholder="基础套餐 ID" required><select class="form-control mr-1 mb-1" name="enabled"><option value="1">发布</option><option value="0">撤下</option></select><input class="form-control mr-1 mb-1" name="sort" type="number" value="0" placeholder="排序"><button class="btn btn-primary mb-1">保存</button></form><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>基础套餐</th><th>排序</th><th>状态</th></tr></thead><tbody>' + templates + '</tbody></table></div></div></div></div><div class="col-xl-5"><div class="block block-rounded"><div class="block-header block-header-default"><h3 class="block-title">支付驱动白名单</h3></div><div class="block-content"><form data-form="drivers">' + drivers + '<button class="btn btn-primary mt-2">保存白名单</button></form></div></div></div></div>';
    }
    function ordersAudit() {
        var rows = state.orders.data || [];
        var body = rows.length ? rows.map(function (item) {
            return '<tr><td><code>' + escapeHtml(item.trade_no || '-') + '</code></td><td>' + escapeHtml(item.reseller_email || '-') + '<br><small class="text-muted">' + escapeHtml(item.store_name || '-') + '</small></td><td>' + escapeHtml(item.period || '-') + '</td><td>' + (Number(item.amount || 0) / 100).toFixed(2) + '</td><td>' + statusTag(Number(item.status) === 3 ? 'active' : Number(item.status) === 1 ? 'pending' : 'suspended') + '</td><td>' + dateText(item.created_at) + '</td></tr>';
        }).join('') : '<tr><td colspan="6" class="text-center text-muted py-4">暂无倒卖商订单</td></tr>';
        return '<div class="block block-rounded mt-4"><div class="block-header block-header-default"><h3 class="block-title">订单审计</h3><span class="text-muted font-size-sm">仅展示订单摘要，不展示支付密钥</span></div><div class="block-content"><div class="table-responsive"><table class="table table-vcenter"><thead><tr><th>交易号</th><th>倒卖商</th><th>周期</th><th>金额</th><th>状态</th><th>创建时间</th></tr></thead><tbody>' + body + '</tbody></table></div>' + pager(state.orders, 'orders') + '</div></div>';
    }
    function reviewModal() {
        if (!state.modal) return '';
        var required = state.modal.status === 'rejected' || state.modal.status === 'suspended';
        return '<div class="modal d-block" role="dialog" aria-modal="true" aria-labelledby="reseller-review-title" style="background:rgba(0,0,0,.45)"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 id="reseller-review-title" class="modal-title">' + escapeHtml(statusText(state.modal.status)) + ' - ' + escapeHtml(state.modal.name) + '</h5><button class="close" type="button" aria-label="关闭" data-action="close-modal"><span aria-hidden="true">×</span></button></div><div class="modal-body"><label class="font-w600">审核备注<textarea class="form-control mt-2" data-field="reason" rows="4" placeholder="' + (required ? '请填写处理原因' : '可选：填写备注') + '">' + escapeHtml(state.modal.reason || '') + '</textarea></label></div><div class="modal-footer"><button class="btn btn-alt-secondary" type="button" data-action="close-modal" ' + (state.saving ? 'disabled' : '') + '>取消</button><button class="btn btn-primary" type="button" data-action="submit-review" ' + (state.saving ? 'disabled' : '') + '>' + (state.saving ? '提交中...' : '确认') + '</button></div></div></div></div>';
    }
    function draw() {
        if (!state.root) return;
        var active = state.tab;
        var content = active === 'accounts' ? accountTable() : active === 'stores' ? storeTable() : permissions() + ordersAudit();
        var status = active === 'accounts' ? state.accountStatus : state.storeStatus;
        state.root.innerHTML = '<div class="d-flex flex-column flex-md-row justify-content-md-between align-items-md-center py-3"><div><h1 class="h3 font-w700 mb-1">倒卖商管理</h1><p class="text-muted mb-0">审核倒卖商账号、店铺和销售权限</p></div><button class="btn btn-alt-primary mt-3 mt-md-0" data-action="refresh">刷新</button></div>' + (state.error ? '<div class="alert alert-danger" role="alert">' + escapeHtml(state.error) + '</div>' : '') + '<div class="row gutters-tiny mb-4"><div class="col-6 col-xl-3"><div class="block block-rounded mb-0"><div class="block-content block-content-full"><div class="font-size-sm text-muted">待审核倒卖商</div><div class="font-size-h2 font-w700 text-warning">' + Number(state.summary.pending_resellers || 0) + '</div></div></div></div><div class="col-6 col-xl-3"><div class="block block-rounded mb-0"><div class="block-content block-content-full"><div class="font-size-sm text-muted">待审核店铺</div><div class="font-size-h2 font-w700 text-warning">' + Number(state.summary.pending_stores || 0) + '</div></div></div></div><div class="col-6 col-xl-3"><div class="block block-rounded mb-0"><div class="block-content block-content-full"><div class="font-size-sm text-muted">已启用店铺</div><div class="font-size-h2 font-w700 text-success">' + Number(state.summary.active_stores || 0) + '</div></div></div></div><div class="col-6 col-xl-3"><div class="block block-rounded mb-0"><div class="block-content block-content-full"><div class="font-size-sm text-muted">停用倒卖商</div><div class="font-size-h2 font-w700 text-muted">' + Number(state.summary.suspended_resellers || 0) + '</div></div></div></div></div><div class="block block-rounded"><ul class="nav nav-tabs nav-tabs-block"><li class="nav-item"><button class="nav-link ' + (active === 'accounts' ? 'active' : '') + '" data-action="tab" data-tab="accounts">倒卖商审批</button></li><li class="nav-item"><button class="nav-link ' + (active === 'stores' ? 'active' : '') + '" data-action="tab" data-tab="stores">店铺审批</button></li><li class="nav-item"><button class="nav-link ' + (active === 'permissions' ? 'active' : '') + '" data-action="tab" data-tab="permissions">销售权限</button></li></ul></div><div class="block block-rounded"><div class="block-content"><form data-form="filters" class="form-inline"><input class="form-control mr-1 mb-2" name="keyword" value="' + escapeHtml(state.keyword) + '" placeholder="搜索邮箱、店铺名称或 Slug"><select class="form-control mr-1 mb-2" name="status"><option value="">全部状态</option><option value="pending" ' + (status === 'pending' ? 'selected' : '') + '>待审核</option><option value="active" ' + (status === 'active' ? 'selected' : '') + '>已启用</option><option value="rejected" ' + (status === 'rejected' ? 'selected' : '') + '>已拒绝</option><option value="suspended" ' + (status === 'suspended' ? 'selected' : '') + '>已停用</option></select><button class="btn btn-alt-primary mb-2">筛选</button></form></div></div>' + (state.loading ? '<div class="text-center text-muted py-3">加载中...</div>' : content) + reviewModal();
    }
    function submitReview() {
        if (!state.modal) return;
        var reason = state.modal.reason || '';
        if ((state.modal.status === 'rejected' || state.modal.status === 'suspended') && !reason.trim()) { state.error = '拒绝或停用必须填写原因'; draw(); return; }
        state.saving = true;
        draw();
        api(state.modal.target === 'account' ? '/reseller/accounts/review' : '/reseller/stores/review', {method: 'POST', body: JSON.stringify({id: state.modal.id, target: state.modal.target, status: state.modal.status, reason: reason})}).then(function () { state.modal = null; state.saving = false; state.error = ''; loadAll(); }).catch(function (error) { state.saving = false; state.error = error.message; draw(); });
    }
    function bindRoot() {
        if (!state.root || state.root.dataset.bound) return;
        state.root.dataset.bound = '1';
        state.root.addEventListener('click', function (event) {
            var target = event.target.closest('[data-action]');
            if (!target) return;
            if (target.dataset.action === 'tab') { state.tab = target.dataset.tab; draw(); return; }
            if (target.dataset.action === 'refresh') { loadAll(); return; }
            if (target.dataset.action === 'close-modal') { state.modal = null; state.error = ''; draw(); return; }
            if (target.dataset.action === 'submit-review') { submitReview(); return; }
            if (target.dataset.action === 'page') { loadTable(target.dataset.kind, Number(target.dataset.page)); return; }
            if (target.dataset.action === 'review') {
                var list = target.dataset.target === 'account' ? state.accounts.data : state.stores.data;
                var item = list.filter(function (entry) { return String(entry.id) === String(target.dataset.id); })[0];
                if (item) state.modal = {target: target.dataset.target, id: item.id, status: target.dataset.status, name: target.dataset.target === 'account' ? item.email : item.store_name, reason: ''};
                draw();
            }
        });
        if (!state.keyBound) {
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && state.modal) { state.modal = null; state.error = ''; draw(); }
            });
            state.keyBound = true;
        }
        state.root.addEventListener('input', function (event) { if (event.target.dataset.field === 'reason' && state.modal) state.modal.reason = event.target.value; });
        state.root.addEventListener('change', function (event) {
            if (!event.target.dataset.driver) return;
            var driver = event.target.dataset.driver, allowed = state.allowedDrivers.slice(), index = allowed.indexOf(driver);
            if (event.target.checked && index < 0) allowed.push(driver);
            if (!event.target.checked && index >= 0) allowed.splice(index, 1);
            state.allowedDrivers = allowed;
        });
        state.root.addEventListener('submit', function (event) {
            event.preventDefault();
            var form = event.target;
            if (form.dataset.form === 'filters') {
                state.keyword = form.elements.keyword.value.trim();
                if (state.tab === 'accounts') state.accountStatus = form.elements.status.value;
                if (state.tab === 'stores') state.storeStatus = form.elements.status.value;
                loadTable(state.tab === 'accounts' ? 'accounts' : 'stores', 1);
            } else if (form.dataset.form === 'template') {
                api('/reseller/templates/save', {method: 'POST', body: JSON.stringify({base_plan_id: form.elements.base_plan_id.value, enabled: Number(form.elements.enabled.value), sort: Number(form.elements.sort.value || 0)})}).then(loadAll).catch(function (error) { state.error = error.message; draw(); });
            } else if (form.dataset.form === 'drivers') {
                api('/reseller/payment-drivers', {method: 'POST', body: JSON.stringify({allowed: state.allowedDrivers})}).then(loadAll).catch(function (error) { state.error = error.message; draw(); });
            }
        });
    }
    function ensureMount() {
        var root = document.getElementById('reseller-admin-module');
        if (!root || root === state.root) return;
        state.root = root;
        bindRoot();
        loadAll();
    }
    new MutationObserver(ensureMount).observe(document.body, {childList: true, subtree: true});
    window.addEventListener('hashchange', ensureMount);
    ensureMount();
}());
