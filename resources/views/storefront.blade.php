<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Store</title>
    <style>
        :root { color-scheme: light; font-family: system-ui, sans-serif; color: #17202a; background: #f5f7fa; }
        body { margin: 0; } main { max-width: 980px; margin: 0 auto; padding: 36px 18px; }
        header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:28px; }
        h1 { margin:0 0 8px; } p { color:#667085; } section { background:#fff; border:1px solid #e4e7ec; border-radius:8px; padding:20px; margin-bottom:16px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); gap:14px; }
        .plan { border:1px solid #e4e7ec; border-radius:8px; padding:16px; } .plan h3 { margin-top:0; }
        button { border:0; border-radius:6px; background:#246bce; color:#fff; padding:9px 14px; cursor:pointer; } button.secondary { background:#667085; }
        input, select { box-sizing:border-box; width:100%; padding:10px; border:1px solid #d0d5dd; border-radius:6px; margin:6px 0 10px; }
        form { max-width:420px; } .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; } .muted { color:#667085; font-size:13px; }
        #message { min-height:24px; color:#b42318; } .hidden { display:none; }
    </style>
</head>
<body>
<main>
    <header><div><h1 id="store-name">店铺</h1><p id="store-description"></p></div><div id="account" class="muted"></div></header>
    <div id="message"></div>
    <section id="auth-section">
        <h2>登录或注册</h2>
        <form id="auth-form">
            <label>邮箱<input name="email" type="email" required></label>
            <label>密码<input name="password" type="password" minlength="8" required></label>
            <div class="row"><button type="submit">登录</button><button type="button" id="register" class="secondary">注册</button></div>
        </form>
    </section>
    <section><h2>可售套餐</h2><div id="plans" class="grid"></div></section>
    <section id="checkout-section" class="hidden"><h2>支付订单</h2><p class="muted">订单号：<span id="trade-no"></span></p><select id="payment-method"></select><button id="checkout">发起支付</button></section>
    <section id="orders-section" class="hidden"><h2>我的订单</h2><div id="orders"></div></section>
</main>
<script>window.STORE_SLUG = @json($slug);</script>
<script src="/storefront/app.js"></script>
</body>
</html>
