(function () {
    var slug = window.STORE_SLUG;
    var key = 'store_auth_' + slug;
    var auth = sessionStorage.getItem(key);
    var currentTradeNo = null;
    var message = document.getElementById('message');
    function show(value, bad) { message.textContent = value || ''; message.style.color = bad ? '#b42318' : '#027a48'; }
    function api(path, options) {
        options = options || {};
        options.headers = Object.assign({'Content-Type':'application/json'}, options.headers || {});
        if (auth) options.headers.authorization = auth;
        return fetch('/api/v1/store/' + encodeURIComponent(slug) + path, options).then(function (r) {
            return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || '请求失败'); return j; });
        });
    }
    function load() {
        api('/config').then(function (j) {
            document.getElementById('store-name').textContent = j.data.store_name;
            document.getElementById('store-description').textContent = j.data.store_description || '';
        });
        api('/plans').then(function (j) {
            document.getElementById('plans').innerHTML = j.data.map(function (p) {
                var periods = ['month_price','quarter_price','half_year_price','year_price','two_year_price','three_year_price','onetime_price'];
                var labels = {month_price:'月付',quarter_price:'季付',half_year_price:'半年',year_price:'年付',two_year_price:'两年',three_year_price:'三年',onetime_price:'一次性'};
                var choices = periods.filter(function (period) { return p[period] > 0; }).map(function (period) { return '<button data-plan="' + p.id + '" data-period="' + period + '">' + labels[period] + ' ¥' + (p[period] / 100).toFixed(2) + '</button>'; }).join('');
                return '<article class="plan"><h3>' + escapeHtml(p.name) + '</h3><p>' + escapeHtml(p.content || '') + '</p>' + choices + '</article>';
            }).join('');
        });
        if (auth) {
            document.getElementById('auth-section').classList.add('hidden');
            document.getElementById('orders-section').classList.remove('hidden');
            document.getElementById('account').textContent = '已登录';
            loadOrders();
        }
    }
    function loadOrders() {
        api('/order/fetch').then(function (j) {
            document.getElementById('orders').innerHTML = j.data.data.map(function (o) {
                return '<p>' + escapeHtml(o.trade_no) + ' · ' + status(o.status) + ' · ¥' + (o.total_amount / 100).toFixed(2) + '</p>';
            }).join('') || '<p class="muted">暂无订单</p>';
        });
    }
    function loadPayments() {
        api('/payments').then(function (j) {
            document.getElementById('payment-method').innerHTML = j.data.map(function (p) {
                return '<option value="' + p.id + '">' + escapeHtml(p.name) + '</option>';
            }).join('');
        });
    }
    document.getElementById('auth-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var body = Object.fromEntries(new FormData(e.target));
        api('/passport/login', {method:'POST', body:JSON.stringify(body)}).then(function (j) {
            if (!j.data.auth_data) throw new Error('该账号需要完成二次验证，请使用主站登录');
            auth = j.data.auth_data; sessionStorage.setItem(key, auth); show('登录成功'); load();
        }).catch(function (e) { show(e.message, true); });
    });
    document.getElementById('register').addEventListener('click', function () {
        var body = Object.fromEntries(new FormData(document.getElementById('auth-form')));
        api('/passport/register', {method:'POST', body:JSON.stringify(body)}).then(function (j) {
            if (!j.data.auth_data) throw new Error('注册未完成');
            auth = j.data.auth_data; sessionStorage.setItem(key, auth); show('注册成功'); load();
        }).catch(function (e) { show(e.message, true); });
    });
    document.getElementById('plans').addEventListener('click', function (e) {
        if (!e.target.dataset.plan) return;
        if (!auth) return show('请先登录', true);
        api('/order/save', {method:'POST', body:JSON.stringify({plan_id:e.target.dataset.plan, period:e.target.dataset.period})}).then(function (j) {
            currentTradeNo = j.data;
            document.getElementById('trade-no').textContent = currentTradeNo;
            document.getElementById('checkout-section').classList.remove('hidden');
            loadPayments(); loadOrders(); show('订单已创建，请选择支付方式');
        }).catch(function (e) { show(e.message, true); });
    });
    document.getElementById('checkout').addEventListener('click', function () {
        if (!currentTradeNo) return;
        api('/order/checkout', {method:'POST', body:JSON.stringify({trade_no:currentTradeNo, method:document.getElementById('payment-method').value})}).then(function (j) {
            if (j.type === 1 && j.data) window.location.href = j.data;
            else show('支付请求已创建，请按页面提示完成支付');
        }).catch(function (e) { show(e.message, true); });
    });
    function status(v) { return ({0:'待支付',1:'开通中',2:'已取消',3:'已完成',4:'已关闭'})[v] || '未知'; }
    function escapeHtml(v) { return String(v).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
    load();
}());
