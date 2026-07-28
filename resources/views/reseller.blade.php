<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #182230; background: #f5f7fa; }
        * { box-sizing: border-box; }
        body { margin: 0; min-width: 320px; }
        button, input, select, textarea { font: inherit; }
        button { border: 0; border-radius: 7px; padding: 10px 14px; color: #fff; background: #246bce; cursor: pointer; }
        button:hover { background: #1d5bb1; }
        button.secondary { color: #344054; border: 1px solid #d0d5dd; background: #fff; }
        button.secondary:hover { background: #f8fafc; }
        button.danger { background: #b54708; }
        input, select, textarea { width: 100%; border: 1px solid #d0d5dd; border-radius: 7px; padding: 10px 11px; color: #182230; background: #fff; }
        textarea { min-height: 92px; resize: vertical; }
        label { display: block; color: #475467; font-size: 13px; font-weight: 600; }
        label input, label textarea, label select { margin-top: 7px; font-weight: 400; }
        .app { min-height: 100vh; display: grid; grid-template-columns: 238px minmax(0, 1fr); }
        .sidebar { display: flex; flex-direction: column; padding: 26px 18px; color: #d9e2f0; background: #162333; }
        .brand { display: flex; align-items: center; gap: 10px; margin: 0 8px 44px; color: #fff; font-size: 16px; font-weight: 750; }
        .brand-mark { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 9px; color: #162333; background: #8ed1c5; font-weight: 800; }
        .nav-label { margin: 0 10px 10px; color: #8ea0b8; font-size: 11px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
        .nav-item { display: flex; align-items: center; gap: 10px; padding: 11px 12px; border-radius: 7px; color: #f2f5fa; background: rgba(255,255,255,.1); font-size: 14px; }
        .sidebar-note { margin: auto 10px 0; color: #8ea0b8; font-size: 12px; line-height: 1.7; }
        .main { min-width: 0; padding: 34px clamp(18px, 4vw, 54px); }
        .topbar { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; margin-bottom: 28px; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { margin-bottom: 7px; font-size: 28px; letter-spacing: 0; }
        h2 { margin-bottom: 6px; font-size: 17px; }
        h3 { margin-bottom: 5px; font-size: 15px; }
        .subtle, .muted { color: #667085; }
        .subtle { margin-bottom: 0; font-size: 14px; }
        #message { min-height: 23px; margin-bottom: 12px; font-size: 13px; }
        .workspace { max-width: 1180px; }
        .dashboard { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(300px, .85fr); gap: 18px; align-items: start; }
        .panel { min-width: 0; margin-bottom: 18px; border: 1px solid #e1e7ef; border-radius: 10px; background: #fff; box-shadow: 0 2px 8px rgba(16,24,40,.035); }
        .panel-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; padding: 18px 20px; border-bottom: 1px solid #eaecf0; }
        .panel-head p { margin-bottom: 0; color: #667085; font-size: 13px; line-height: 1.6; }
        .panel-body { padding: 18px 20px; }
        .stack { display: grid; gap: 12px; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .form-grid .wide { grid-column: 1 / -1; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .choice-list { display: flex; flex-wrap: wrap; gap: 8px; }
        .choice-list button { color: #344054; border: 1px solid #d0d5dd; background: #fff; font-size: 13px; }
        .choice-list button:hover { border-color: #246bce; color: #246bce; background: #f5f9ff; }
        .data-list { display: grid; gap: 8px; margin-top: 15px; }
        .data-row { display: flex; justify-content: space-between; gap: 12px; padding: 11px 12px; border: 1px solid #eaecf0; border-radius: 7px; font-size: 13px; }
        .data-row strong { color: #182230; }
        .data-row span { color: #667085; text-align: right; }
        .empty { padding: 18px 0; color: #667085; text-align: center; font-size: 13px; }
        .auth-shell { max-width: 960px; margin: 7vh auto 0; display: grid; grid-template-columns: .85fr 1.15fr; overflow: hidden; border: 1px solid #dfe6ee; border-radius: 14px; background: #fff; box-shadow: 0 18px 50px rgba(16,24,40,.08); }
        .auth-intro { padding: 42px; color: #e8eef7; background: #162333; }
        .auth-intro .brand { margin: 0 0 56px; }
        .auth-intro h1 { color: #fff; font-size: 30px; line-height: 1.25; }
        .auth-intro p { color: #b6c4d6; line-height: 1.8; }
        .auth-copy { margin-top: 45px; font-size: 13px; }
        .auth-forms { padding: 34px; }
        .auth-forms h2 { margin-bottom: 18px; }
        .auth-divider { height: 1px; margin: 28px 0; background: #eaecf0; }
        @media (max-width: 880px) { .dashboard { grid-template-columns: 1fr; } .auth-shell { grid-template-columns: 1fr; margin: 20px auto; } .auth-intro { padding: 28px; } .auth-intro .brand { margin-bottom: 28px; } .auth-copy { margin-top: 24px; } }
        @media (max-width: 680px) { .app { display: block; } .sidebar { padding: 16px 18px; } .brand { margin-bottom: 18px; } .sidebar-note { display: none; } .main { padding: 24px 16px; } .topbar { display: block; } .topbar .actions { margin-top: 14px; } .form-grid { grid-template-columns: 1fr; } .form-grid .wide { grid-column: auto; } .panel-head { display: block; } .panel-head .actions { margin-top: 12px; } .auth-forms { padding: 24px 20px; } }
    </style>
</head>
<body>
<div id="message" style="position:fixed;top:18px;right:22px;z-index:20;max-width:min(420px,calc(100vw - 44px));padding:10px 13px;border:1px solid #dfe6ee;border-radius:7px;background:#fff;box-shadow:0 8px 24px rgba(16,24,40,.1);font-size:13px"></div>
<div id="login" class="auth-shell">
    <section class="auth-intro">
        <div class="brand"><span class="brand-mark">R</span><span>Reseller Portal</span></div>
        <h1>管理你的专属销售店铺</h1>
        <p>配置可售套餐和支付方式，使用独立店铺向客户提供订阅服务。</p>
        <p class="auth-copy">倒卖商账号需先提交申请，经管理员审核通过后才能登录和销售。</p>
    </section>
    <section class="auth-forms">
        <h2>登录倒卖商中心</h2>
        <form id="login-form" class="stack">
            <label>邮箱<input name="email" type="email" placeholder="name@example.com" autocomplete="email" required></label>
            <label>密码<input name="password" type="password" placeholder="请输入密码" autocomplete="current-password" required></label>
            <button type="submit">登录</button>
        </form>
        <div class="auth-divider"></div>
        <h2>申请成为倒卖商</h2>
        <form id="register-form" class="stack">
            <label>邮箱<input name="email" type="email" placeholder="用于登录和接收通知" autocomplete="email" required></label>
            <label>密码<input name="password" type="password" minlength="8" placeholder="至少 8 位" autocomplete="new-password" required></label>
            <div class="form-grid">
                <label>店铺标识<input name="store_slug" placeholder="demo-store" pattern="[a-z0-9][a-z0-9-]{2,31}" required></label>
                <label>店铺名称<input name="store_name" placeholder="我的订阅店" required></label>
            </div>
            <button type="submit" class="secondary">提交申请</button>
        </form>
        <div id="message"></div>
    </section>
</div>
<div id="panel" class="app" hidden>
    <aside class="sidebar">
        <div class="brand"><span class="brand-mark">R</span><span>Reseller Portal</span></div>
        <p class="nav-label">工作台</p><span class="nav-item">店铺与销售</span>
        <p class="sidebar-note">只可使用管理员发布的基础套餐。基础流量、节点和权限由平台统一控制。</p>
    </aside>
    <main class="main"><div class="workspace">
        <header class="topbar"><div><h1>倒卖商工作台</h1><p class="subtle">管理店铺展示、销售套餐和收款方式。</p></div><div class="actions"><a class="muted" href="/store" style="padding:10px 4px;font-size:13px">查看店铺入口</a><button id="logout" class="secondary" type="button">退出登录</button></div></header>
        <div class="dashboard">
            <div>
                <section class="panel"><div class="panel-head"><div><h2>店铺设置</h2><p>客户将通过你的店铺标识访问销售页面。</p></div></div><div class="panel-body"><form id="store-form" class="stack"><div class="form-grid"><label>店铺标识<input name="store_slug" placeholder="demo-store" pattern="[a-z0-9][a-z0-9-]{2,31}" required></label><label>店铺名称<input name="store_name" placeholder="我的订阅店" required></label><label class="wide">店铺说明<textarea name="store_description" placeholder="介绍你的服务内容"></textarea></label></div><div class="actions"><button type="submit">保存店铺设置</button><span class="muted">访问地址：/store/{slug}</span></div></form></div></section>
                <section class="panel"><div class="panel-head"><div><h2>我的销售套餐</h2><p>节点、流量和设备限制继承基础套餐，你只需设置展示信息和价格。</p></div></div><div class="panel-body"><form id="plan-form" class="stack"><input type="hidden" name="id"><div class="form-grid"><label>基础套餐 ID<input name="base_plan_id" type="number" min="1" placeholder="从右侧模板选择" required></label><label>展示名称<input name="name" placeholder="例如：高速月付" required></label><label class="wide">展示内容<textarea name="content" placeholder="面向客户展示的套餐说明"></textarea></label></div><div class="form-grid"><label>月付（分）<input name="month_price" type="number" min="1" placeholder="不销售可留空"></label><label>季付（分）<input name="quarter_price" type="number" min="1" placeholder="不销售可留空"></label><label>半年付（分）<input name="half_year_price" type="number" min="1" placeholder="不销售可留空"></label><label>年付（分）<input name="year_price" type="number" min="1" placeholder="不销售可留空"></label><label>两年付（分）<input name="two_year_price" type="number" min="1" placeholder="不销售可留空"></label><label>一次性（分）<input name="onetime_price" type="number" min="1" placeholder="不销售可留空"></label></div><div class="actions"><button type="submit">保存销售套餐</button><span class="muted">价格单位为分，必须大于 0。</span></div></form><div id="plans" class="data-list"></div></div></section>
            </div>
            <div>
                <section class="panel"><div class="panel-head"><div><h2>可用基础套餐</h2><p>管理员发布后才会出现在这里。</p></div></div><div class="panel-body"><div id="templates" class="choice-list"></div></div></section>
                <section class="panel"><div class="panel-head"><div><h2>支付配置</h2><p>使用管理员允许的支付驱动，密钥由系统加密保存。</p></div></div><div class="panel-body"><form id="payment-form" class="stack"><input type="hidden" name="id"><label>支付驱动<select name="driver" id="payment-driver" required></select></label><label>支付名称<input name="name" placeholder="例如：支付宝" required></label><label>配置 JSON<textarea name="config_json" placeholder="按支付驱动要求填写 JSON"></textarea></label><label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="enabled" value="1" style="width:16px;margin:0"> 启用此支付方式</label><button type="submit">保存支付配置</button></form><div id="payments" class="data-list"></div></div></section>
                <section class="panel"><div class="panel-head"><div><h2>销售概览</h2><p>查看当前店铺的客户和订单数量。</p></div></div><div class="panel-body"><div class="actions"><button id="load-customers" class="secondary" type="button">刷新客户</button><button id="load-orders" class="secondary" type="button">刷新订单</button></div><div id="audit" class="data-list"></div></div></section>
            </div>
        </div>
    </div></main>
</div>
<script src="/reseller/app.js"></script>
</body>
</html>
