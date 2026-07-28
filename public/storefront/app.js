(function () {
    var slug = window.STORE_SLUG;
    var key = 'store_auth_' + slug;
    var auth = sessionStorage.getItem(key);
    var currentTradeNo = null;
    var message = document.getElementById('message');

    function show(value, bad) {
        message.textContent = value || '';
        message.style.color = bad ? '#b42318' : '#027a48';
    }

    function api(path, options) {
        options = options || {};
        options.headers = Object.assign({'Content-Type': 'application/json'}, options.headers || {});
        if (auth) options.headers.authorization = auth;
        return fetch('/api/v1/store/' + encodeURIComponent(slug) + path, options).then(function (response) {
            return response.json().then(function (data) {
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

    function money(value) { return '¥' + (Number(value) / 100).toFixed(2); }
    function status(value) { return ({0: '待支付', 1: '开通中', 2: '已取消', 3: '已完成', 4: '已关闭'})[value] || '未知状态'; }

    function load() {
        api('/config').then(function (data) {
            document.title = data.data.store_name || '订阅店铺';
            document.getElementById('store-name').textContent = data.data.store_name || '订阅店铺';
            document.getElementById('store-description').textContent = data.data.store_description || '选择适合你的订阅套餐。';
        }).catch(function (error) { show(error.message, true); });
        api('/plans').then(function (data) {
            var periods = ['month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price', 'three_year_price', 'onetime_price'];
            var labels = {month_price: '月付', quarter_price: '季付', half_year_price: '半年付', year_price: '年付', two_year_price: '两年付', three_year_price: '三年付', onetime_price: '一次性'};
            document.getElementById('plans').innerHTML = data.data.length ? data.data.map(function (plan) {
                var choices = periods.filter(function (period) { return Number(plan[period]) > 0; }).map(function (period) {
                    return '<button type="button" data-plan="' + plan.id + '" data-period="' + period + '">' + labels[period] + ' · ' + money(plan[period]) + '</button>';
                }).join('');
                return '<article class="plan"><h3>' + escapeHtml(plan.name) + '</h3><p>' + escapeHtml(plan.content || '基础订阅套餐') + '</p><div class="plan-actions">' + choices + '</div></article>';
            }).join('') : '<div class="empty">当前暂无可售套餐</div>';
        }).catch(function (error) { show(error.message, true); });
        if (auth) {
            document.getElementById('auth-section').hidden = true;
            document.getElementById('orders-section').hidden = false;
            document.getElementById('account').textContent = '已登录';
            loadOrders();
        }
    }

    function loadOrders() {
        api('/order/fetch').then(function (data) {
            document.getElementById('orders').innerHTML = data.data.data.length ? data.data.data.map(function (order) {
                return '<div class="order-row"><strong>' + escapeHtml(order.plan ? order.plan.name : order.trade_no) + '</strong><span>' + status(order.status) + ' · ' + money(order.total_amount) + '</span></div>';
            }).join('') : '<div class="empty">暂无订单</div>';
        }).catch(function (error) { show(error.message, true); });
    }

    function loadPayments() {
        api('/payments').then(function (data) {
            document.getElementById('payment-method').innerHTML = data.data.length ? data.data.map(function (payment) {
                return '<option value="' + payment.id + '">' + escapeHtml(payment.name) + '</option>';
            }).join('') : '<option value="">暂无可用支付方式</option>';
        }).catch(function (error) { show(error.message, true); });
    }

    document.getElementById('auth-form').addEventListener('submit', function (event) {
        event.preventDefault();
        api('/passport/login', {method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(event.target)))}).then(function (data) {
            if (!data.data.auth_data) throw new Error('登录未完成，请使用主站账号完成验证');
            auth = data.data.auth_data;
            sessionStorage.setItem(key, auth);
            show('登录成功');
            load();
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('register').addEventListener('click', function () {
        api('/passport/register', {method: 'POST', body: JSON.stringify(Object.fromEntries(new FormData(document.getElementById('auth-form'))))}).then(function (data) {
            if (!data.data.auth_data) throw new Error('注册未完成，请按主站要求完成验证');
            auth = data.data.auth_data;
            sessionStorage.setItem(key, auth);
            show('注册成功');
            load();
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('plans').addEventListener('click', function (event) {
        if (!event.target.dataset.plan) return;
        if (!auth) { show('请先登录或注册', true); return; }
        api('/order/save', {method: 'POST', body: JSON.stringify({plan_id: event.target.dataset.plan, period: event.target.dataset.period})}).then(function (data) {
            currentTradeNo = data.data;
            document.getElementById('trade-no').textContent = currentTradeNo;
            document.getElementById('checkout-section').hidden = false;
            loadPayments();
            loadOrders();
            show('订单已创建，请选择支付方式');
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('checkout').addEventListener('click', function () {
        if (!currentTradeNo) return;
        var method = document.getElementById('payment-method').value;
        if (!method) { show('当前店铺暂无可用支付方式', true); return; }
        api('/order/checkout', {method: 'POST', body: JSON.stringify({trade_no: currentTradeNo, method: method})}).then(function (data) {
            if (data.type === 1 && data.data) window.location.href = data.data;
            else show('支付请求已创建，请按页面提示完成支付');
        }).catch(function (error) { show(error.message, true); });
    });

    load();
}());
