<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>订阅店铺</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #182230; background: #f5f7fa; }
        * { box-sizing: border-box; } body { margin: 0; }
        button, input, select { font: inherit; } button { border: 0; border-radius: 7px; padding: 10px 14px; color: #fff; background: #246bce; cursor: pointer; } button:hover { background: #1d5bb1; } button.secondary { color: #344054; border: 1px solid #d0d5dd; background: #fff; }
        input, select { width: 100%; margin-top: 7px; padding: 10px 11px; border: 1px solid #d0d5dd; border-radius: 7px; background: #fff; }
        label { display: block; color: #475467; font-size: 13px; font-weight: 600; } form { display: grid; gap: 12px; }
        .page { max-width: 1120px; margin: 0 auto; padding: 30px 20px 60px; } .hero { display: flex; justify-content: space-between; gap: 20px; align-items: flex-end; padding: 28px 0 30px; border-bottom: 1px solid #dfe6ee; }
        .eyebrow { margin: 0 0 8px; color: #246bce; font-size: 12px; font-weight: 750; letter-spacing: .1em; text-transform: uppercase; } h1 { margin: 0 0 8px; font-size: clamp(28px, 5vw, 44px); letter-spacing: 0; } h2 { margin: 0; font-size: 18px; } p { line-height: 1.7; } .subtle { color: #667085; }
        .hero-copy { max-width: 690px; margin-bottom: 0; color: #667085; } .hero-account { color: #667085; font-size: 13px; text-align: right; white-space: nowrap; }
        .layout { display: grid; grid-template-columns: minmax(0, 1fr) 310px; gap: 20px; margin-top: 22px; align-items: start; }
        .section { margin-bottom: 20px; padding: 20px; border: 1px solid #e1e7ef; border-radius: 10px; background: #fff; box-shadow: 0 2px 8px rgba(16,24,40,.03); } .section-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; margin-bottom: 16px; } .section-head p { margin: 5px 0 0; font-size: 13px; }
        .plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; } .plan { display: flex; flex-direction: column; min-height: 190px; padding: 17px; border: 1px solid #e1e7ef; border-radius: 8px; } .plan h3 { margin: 0 0 8px; font-size: 16px; } .plan p { min-height: 48px; margin: 0 0 15px; color: #667085; font-size: 13px; }
        .plan-actions { display: flex; flex-wrap: wrap; gap: 7px; margin-top: auto; } .plan-actions button { padding: 8px 10px; font-size: 12px; } .empty { padding: 18px 0; color: #667085; text-align: center; font-size: 13px; }
        .auth-card { display: grid; gap: 18px; } .auth-card h2 { margin-bottom: 4px; } .divider { height: 1px; background: #eaecf0; } .message { min-height: 22px; font-size: 13px; } #checkout-section, #orders-section { margin-top: 20px; } #trade-no { color: #182230; font-family: ui-monospace, monospace; }
        .order-row { display: flex; justify-content: space-between; gap: 12px; padding: 11px 0; border-bottom: 1px solid #eef1f4; font-size: 13px; } .order-row:last-child { border-bottom: 0; } .order-row span { color: #667085; text-align: right; }
        @media (max-width: 820px) { .layout { grid-template-columns: 1fr; } .hero { display: block; } .hero-account { margin-top: 15px; text-align: left; } }
    </style>
</head>
@if (!$reseller_enabled)
<body>
<main class="page">
    <section class="section" style="max-width:680px;margin:15vh auto;text-align:center">
        <h1>倒卖商服务暂未开放</h1>
        <p class="subtle">当前暂未开放店铺销售和客户购买，请稍后再试。</p>
    </section>
</main>
</body>
@else
<body>
<main class="page">
    <header class="hero"><div><p class="eyebrow">Subscription Store</p><h1 id="store-name">订阅店铺</h1><p id="store-description" class="hero-copy">正在加载店铺信息...</p></div><div id="account" class="hero-account">未登录</div></header>
    <div id="message" class="message"></div>
    <div class="layout">
        <div>
            <section><div class="section-head"><div><h2>可售套餐</h2><p class="subtle">选择套餐周期后创建订单。</p></div></div><div id="plans" class="plans"></div></section>
            <section id="checkout-section" class="section" hidden><div class="section-head"><div><h2>完成支付</h2><p class="subtle">订单号：<span id="trade-no"></span></p></div></div><select id="payment-method" aria-label="支付方式"></select><button id="checkout" style="margin-top:12px">发起支付</button></section>
            <section id="orders-section" class="section" hidden><div class="section-head"><div><h2>我的订单</h2><p class="subtle">你在当前店铺的订单记录。</p></div></div><div id="orders"></div></section>
        </div>
        <aside><section id="auth-section" class="section auth-card"><div><h2>登录或注册</h2><p class="subtle">登录后即可购买套餐并查看订单。</p></div><form id="auth-form"><label>邮箱<input name="email" type="email" placeholder="name@example.com" autocomplete="email" required></label><label>密码<input name="password" type="password" minlength="8" placeholder="至少 8 位" autocomplete="current-password" required></label><div style="display:flex;gap:8px"><button type="submit">登录</button><button type="button" id="register" class="secondary">注册</button></div></form></section></aside>
    </div>
</main>
<script>window.STORE_SLUG = @json($slug);</script>
<script src="/storefront/app.js"></script>
</body>
@endif
</html>
