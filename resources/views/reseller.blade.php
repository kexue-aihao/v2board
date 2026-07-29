<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root {
            --ink: #172033; --muted: #667085; --line: #e3e8ef; --canvas: #f4f7fb; --surface: #fff;
            --nav: #142235; --primary: #246bce; --primary-soft: #edf5ff; --teal: #087f72; --teal-soft: #e7f7f3;
            --amber: #9a5b00; --amber-soft: #fff5dc; --red: #b42318; --red-soft: #fff0ee;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        body { min-width: 320px; margin: 0; color: var(--ink); background: var(--canvas); }
        button, input, select, textarea { font: inherit; }
        button { cursor: pointer; }
        button:disabled, fieldset:disabled button, fieldset:disabled input, fieldset:disabled select, fieldset:disabled textarea { cursor: not-allowed; opacity: .58; }
        a { color: var(--primary); }
        .toast { position: fixed; z-index: 50; top: 20px; right: 20px; max-width: min(420px, calc(100vw - 40px)); padding: 11px 14px; border: 1px solid; border-radius: 6px; box-shadow: 0 10px 30px rgba(16,24,40,.14); font-size: 13px; }
        .toast-success { color: var(--teal); border-color: #b9e6de; background: var(--teal-soft); }
        .toast-error { color: var(--red); border-color: #f2c8c3; background: var(--red-soft); }
        .auth-shell { display: grid; grid-template-columns: minmax(280px, .9fr) minmax(360px, 1.1fr); width: min(980px, calc(100% - 32px)); min-height: 590px; margin: 7vh auto 32px; overflow: hidden; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); box-shadow: 0 22px 60px rgba(20,34,53,.1); }
        .auth-aside { display: flex; flex-direction: column; padding: 42px 38px; color: #dbe6f4; background: var(--nav); }
        .brand { display: flex; align-items: center; gap: 10px; color: #fff; font-size: 16px; font-weight: 750; }
        .brand-mark { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 8px; color: var(--nav); background: #8ed1c5; font-weight: 800; }
        .auth-aside h1 { max-width: 330px; margin: auto 0 14px; color: #fff; font-size: clamp(28px, 4vw, 38px); line-height: 1.2; letter-spacing: 0; }
        .auth-aside p { max-width: 330px; margin: 0; color: #aebed2; font-size: 14px; line-height: 1.8; }
        .aside-points { display: grid; gap: 11px; margin-top: 32px; padding-top: 24px; border-top: 1px solid rgba(255,255,255,.13); color: #dbe6f4; font-size: 13px; }
        .aside-point { display: flex; gap: 10px; align-items: center; }
        .aside-point i { display: grid; flex: 0 0 22px; width: 22px; height: 22px; place-items: center; border-radius: 50%; color: var(--nav); background: #8ed1c5; font-style: normal; font-size: 12px; font-weight: 800; }
        .auth-main { padding: 42px 44px; }
        .auth-main h2 { margin: 0 0 7px; font-size: 24px; }
        .auth-caption { margin: 0 0 24px; color: var(--muted); font-size: 14px; }
        .auth-tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; margin-bottom: 24px; padding: 4px; border-radius: 6px; background: #f1f4f8; }
        .auth-tab { min-height: 38px; border: 0; border-radius: 4px; color: var(--muted); background: transparent; font-size: 13px; font-weight: 650; }
        .auth-tab.is-active { color: var(--primary); background: #fff; box-shadow: 0 1px 4px rgba(16,24,40,.1); }
        .form-stack { display: grid; gap: 15px; }
        .field { display: grid; gap: 6px; color: #475467; font-size: 13px; font-weight: 650; }
        .field input, .field textarea, .field select { width: 100%; min-height: 40px; padding: 9px 11px; border: 1px solid #cfd7e3; border-radius: 5px; outline: 0; color: var(--ink); background: #fff; }
        .field textarea { min-height: 96px; resize: vertical; }
        .field input:focus, .field textarea:focus, .field select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(36,107,206,.12); }
        .field-help { color: var(--muted); font-size: 12px; font-weight: 400; }
        .payment-fields { display: grid; gap: 15px; padding: 2px 0; }
        .payment-fields .empty-state { padding: 12px 0; border: 1px dashed var(--line); border-radius: 5px; }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .field-check { display: flex; align-items: center; grid-template-columns: 16px 1fr; gap: 8px; }
        .field-check input { width: 16px; min-height: 16px; margin: 0; }
        .btn { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; gap: 7px; padding: 0 15px; border: 1px solid transparent; border-radius: 5px; font-size: 13px; font-weight: 700; }
        .btn-primary { color: #fff; background: var(--primary); }
        .btn-primary:hover:not(:disabled) { background: #1d5bb2; }
        .btn-quiet { color: #344054; border-color: #d2dae5; background: #fff; }
        .btn-quiet:hover:not(:disabled) { background: #f8fafc; }
        .btn-block { width: 100%; }
        .auth-footnote { margin: 22px 0 0; color: var(--muted); font-size: 12px; line-height: 1.7; }
        .registration-credentials { margin: 18px 0 22px; padding: 14px; border: 1px solid #b9e6de; border-radius: 6px; color: var(--teal); background: var(--teal-soft); }
        .registration-credentials strong, .registration-credentials small { display: block; }
        .registration-credentials strong { margin-bottom: 4px; font-size: 13px; }
        .registration-credentials small { margin-bottom: 10px; color: #276f67; font-size: 12px; line-height: 1.6; }
        .registration-password { display: block; padding: 10px; border: 1px solid #b9e6de; border-radius: 5px; color: var(--ink); background: #fff; font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; line-height: 1.7; word-break: break-all; user-select: all; }
        .registration-credentials .btn { margin-top: 10px; }
        .workspace { display: grid; grid-template-columns: 224px minmax(0, 1fr); min-height: 100vh; }
        .sidebar { display: flex; flex-direction: column; padding: 26px 18px; color: #d9e2f0; background: var(--nav); }
        .sidebar .brand { margin: 0 8px 46px; }
        .nav-caption { margin: 0 10px 9px; color: #8ea0b8; font-size: 11px; font-weight: 750; letter-spacing: .1em; text-transform: uppercase; }
        .nav-item { display: flex; align-items: center; gap: 10px; min-height: 40px; padding: 0 12px; border-radius: 5px; color: #f3f6fa; background: rgba(255,255,255,.1); font-size: 13px; font-weight: 650; }
        .nav-item i { color: #8ed1c5; font-style: normal; }
        .sidebar-note { margin: auto 10px 0; color: #8ea0b8; font-size: 12px; line-height: 1.8; }
        .main { min-width: 0; padding: 32px clamp(18px, 4vw, 54px); }
        .content { max-width: 1180px; margin: 0 auto; }
        .topbar { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; margin-bottom: 20px; }
        .eyebrow { margin: 0 0 6px; color: var(--primary); font-size: 11px; font-weight: 750; letter-spacing: .12em; }
        .topbar h1 { margin: 0; font-size: 25px; letter-spacing: 0; }
        .topbar p { margin: 6px 0 0; color: var(--muted); font-size: 13px; }
        .topbar-actions { display: flex; gap: 8px; align-items: center; }
        .approval-banner { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; padding: 13px 15px; border: 1px solid; border-radius: 6px; }
        .approval-banner strong, .approval-banner span { display: block; }
        .approval-banner strong { margin-bottom: 3px; font-size: 13px; }
        .approval-banner span { font-size: 12px; }
        .approval-banner-warning { color: var(--amber); border-color: #f0d293; background: var(--amber-soft); }
        .approval-banner-ready { color: var(--teal); border-color: #b9e6de; background: var(--teal-soft); }
        .status-overview { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 18px; }
        .status-card { padding: 14px 15px; border: 1px solid var(--line); border-radius: 6px; background: var(--surface); }
        .status-label { margin-bottom: 8px; color: var(--muted); font-size: 12px; }
        .status-pill { display: inline-flex; min-height: 24px; align-items: center; padding: 0 8px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .status-active { color: var(--teal); background: var(--teal-soft); }
        .status-pending { color: var(--amber); background: var(--amber-soft); }
        .status-rejected { color: var(--red); background: var(--red-soft); }
        .status-suspended, .status-neutral { color: #5d6879; background: #eef1f5; }
        .status-card small { display: block; margin-top: 7px; color: var(--muted); font-size: 12px; }
        .dashboard { display: grid; grid-template-columns: minmax(0, 1.12fr) minmax(290px, .88fr); gap: 14px; align-items: start; }
        .panel { min-width: 0; margin-bottom: 14px; overflow: hidden; border: 1px solid var(--line); border-radius: 6px; background: var(--surface); box-shadow: 0 5px 18px rgba(16,24,40,.035); }
        .panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 16px 17px; border-bottom: 1px solid var(--line); }
        .panel-head h2 { margin: 0; font-size: 15px; }
        .panel-head p { margin: 4px 0 0; color: var(--muted); font-size: 12px; line-height: 1.6; }
        .panel-body { padding: 16px 17px; }
        .form-actions { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
        .form-actions .hint { color: var(--muted); font-size: 12px; }
        .template-list, .data-list { display: grid; gap: 8px; }
        .template-option { display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; padding: 11px 12px; border: 1px solid var(--line); border-radius: 5px; color: var(--ink); background: #fbfcfe; text-align: left; }
        .template-option:hover { border-color: var(--primary); background: var(--primary-soft); }
        .template-option span, .list-row span { display: block; margin-top: 3px; color: var(--muted); font-size: 12px; font-weight: 400; }
        .list-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 11px 12px; border: 1px solid var(--line); border-radius: 5px; font-size: 13px; }
        .list-row em { color: var(--muted); font-size: 12px; font-style: normal; text-align: right; }
        .status-dot { color: #697586; font-size: 12px; }
        .status-dot:before { display: inline-block; width: 7px; height: 7px; margin-right: 5px; border-radius: 50%; background: #98a2b3; content: ''; }
        .status-dot.is-on { color: var(--teal); }
        .status-dot.is-on:before { background: var(--teal); }
        .empty-state { padding: 22px 10px; color: var(--muted); text-align: center; font-size: 12px; line-height: 1.7; }
        .audit-stat { display: flex; align-items: center; justify-content: space-between; padding: 13px; border: 1px solid var(--line); border-radius: 5px; }
        .audit-stat span { color: var(--primary); font-weight: 750; }
        .review-note { color: var(--muted); font-size: 12px; line-height: 1.7; }
        .service-closed { display: grid; width: min(680px, calc(100% - 32px)); min-height: 260px; margin: 12vh auto 32px; padding: 42px 34px; place-items: center; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); box-shadow: 0 22px 60px rgba(20,34,53,.08); text-align: center; }
        .service-closed h1 { margin: 0 0 10px; font-size: 26px; }
        .service-closed p { max-width: 480px; margin: 0; color: var(--muted); font-size: 14px; line-height: 1.8; }
        @media (max-width: 860px) { .auth-shell { grid-template-columns: 1fr; margin-top: 20px; } .auth-aside { min-height: 300px; padding: 28px; } .auth-aside h1 { margin-top: 56px; } .auth-main { padding: 30px 26px; } .workspace { grid-template-columns: 1fr; } .sidebar { padding: 16px 18px; } .sidebar .brand { margin-bottom: 18px; } .sidebar-note { display: none; } .main { padding: 24px 16px; } }
        @media (max-width: 680px) { .dashboard { grid-template-columns: 1fr; } .topbar { display: block; } .topbar-actions { margin-top: 14px; } .topbar-actions .btn { flex: 1; } .status-overview { grid-template-columns: 1fr; } .two-col { grid-template-columns: 1fr; } .approval-banner { display: block; } .auth-main { padding: 25px 20px; } }
        @media (prefers-reduced-motion: reduce) { * { scroll-behavior: auto !important; transition: none !important; } }
    </style>
</head>
<body data-reseller-enabled="{{ $reseller_enabled ? '1' : '0' }}">
<div id="message" class="toast" hidden role="status" aria-live="polite"></div>
@if (!$reseller_enabled)
<main class="service-closed" role="status">
    <div>
        <h1>倒卖商服务暂未开放</h1>
        <p>当前暂未开放倒卖商账号注册、登录和店铺销售，请稍后再试。</p>
    </div>
</main>
@endif
<section id="auth-shell" class="auth-shell" @if (!$reseller_enabled) hidden @endif>
    <aside class="auth-aside">
        <div class="brand"><span class="brand-mark">R</span><span>倒卖工作区</span></div>
        <h1>把你的销售店铺管理得更清楚。</h1>
        <p>从平台允许的基础套餐开始，设置展示价格与收款方式，统一管理店铺运营状态。</p>
        <div class="aside-points">
            <div class="aside-point"><i>1</i><span>注册账号和店铺，等待管理员分别审核</span></div>
            <div class="aside-point"><i>2</i><span>选择已发布的基础套餐并设置销售价格</span></div>
            <div class="aside-point"><i>3</i><span>配置白名单支付驱动后开放销售页</span></div>
        </div>
    </aside>
    <main class="auth-main">
        <div class="auth-tabs" role="tablist" aria-label="倒卖商认证">
            <button class="auth-tab is-active" type="button" data-auth-tab="login" role="tab" aria-selected="true">登录</button>
            <button class="auth-tab" type="button" data-auth-tab="register" role="tab" aria-selected="false">申请入驻</button>
        </div>
        <h2 id="auth-heading">登录倒卖商工作台</h2>
        <p id="auth-caption" class="auth-caption">使用已审核的账号继续管理你的店铺。</p>
        <div id="registration-credentials" class="registration-credentials" hidden role="status" aria-live="polite"></div>
        <form id="login-form" class="form-stack">
            <label class="field">邮箱<input name="email" type="email" autocomplete="email" placeholder="name@example.com" required></label>
            <label class="field">密码<input name="password" type="password" autocomplete="current-password" placeholder="请输入密码" required></label>
            <button class="btn btn-primary btn-block" type="submit">登录工作台</button>
        </form>
        <form id="register-form" class="form-stack" hidden>
            <label class="field">登录邮箱<input name="email" type="email" autocomplete="email" placeholder="仅用于登录" required><span class="field-help">邮箱仅用于登录，不用于接收通知。</span></label>
            <div class="two-col">
                <label class="field">店铺 Slug<input name="store_slug" pattern="[a-z0-9][a-z0-9-]{2,31}" placeholder="demo-store" required><span class="field-help">仅使用小写字母、数字和连字符</span></label>
                <label class="field">店铺名称<input name="store_name" maxlength="128" placeholder="我的订阅店" required></label>
            </div>
            <button class="btn btn-primary btn-block" type="submit">提交入驻申请</button>
            <p class="auth-footnote">提交后系统会生成一组 64 位随机密码并仅显示一次。请立即保存；账号审核和店铺审核相互独立，两者都通过后店铺才会对客户开放销售。</p>
        </form>
    </main>
</section>
<section id="workspace" class="workspace" hidden>
    <aside class="sidebar">
        <div class="brand"><span class="brand-mark">R</span><span>倒卖工作区</span></div>
        <p class="nav-caption">工作台</p>
        <span class="nav-item"><i>◆</i>店铺与销售</span>
        <p class="sidebar-note">基础套餐、节点能力和平台权限由管理员统一控制，你只需管理展示信息、价格和收款配置。</p>
    </aside>
    <main class="main">
        <div class="content">
            <header class="topbar">
                <div><p class="eyebrow">CHANNEL OPERATIONS</p><h1>倒卖商工作台</h1><p>先确认审核状态，再开始配置销售内容。</p></div>
                <div class="topbar-actions"><a id="store-url-link" class="btn btn-quiet" href="/store" target="_blank" rel="noreferrer">打开销售页</a><button id="refresh-workspace" class="btn btn-quiet" type="button">刷新</button><button id="logout" class="btn btn-quiet" type="button">退出</button></div>
            </header>
            <div id="approval-banner" class="approval-banner" role="status"></div>
            <section class="status-overview" aria-label="审核状态">
                <div class="status-card"><div class="status-label">登录账号</div><div id="account-email">-</div><small id="account-status"></small></div>
                <div class="status-card"><div class="status-label">店铺审核</div><div id="store-status">-</div><small id="store-url">-</small></div>
                <div class="status-card"><div class="status-label">审核备注</div><div id="review-note" class="review-note">-</div></div>
            </section>
            <div class="dashboard">
                <div>
                    <section class="panel">
                        <div class="panel-head"><div><h2>店铺信息</h2><p>客户将通过店铺 Slug访问你的销售页面。</p></div></div>
                        <div class="panel-body"><form id="store-form" class="form-stack"><div class="two-col"><label class="field">店铺 Slug<input name="store_slug" pattern="[a-z0-9][a-z0-9-]{2,31}" required></label><label class="field">店铺名称<input name="store_name" maxlength="128" required></label></div><label class="field">店铺介绍<textarea name="store_description" placeholder="向客户介绍你的服务内容"></textarea></label><div class="form-actions"><button class="btn btn-primary" type="submit">保存店铺信息</button><span class="hint">公开地址：<span id="store-url-inline">/store/{slug}</span></span></div></form></div>
                    </section>
                    <section class="panel">
                        <div class="panel-head"><div><h2>销售套餐</h2><p>节点、流量和设备限制继承管理员发布的基础套餐。</p></div></div>
                        <div class="panel-body"><fieldset data-sale-control><form id="plan-form" class="form-stack"><input type="hidden" name="id"><div class="two-col"><label class="field">基础套餐 ID<input name="base_plan_id" type="number" min="1" placeholder="从右侧选择" required></label><label class="field">展示名称<input name="name" placeholder="例如：高速月付" required></label></div><label class="field">展示内容<textarea name="content" placeholder="面向客户展示的套餐说明"></textarea></label><div class="two-col"><label class="field">月付（分）<input name="month_price" type="number" min="1"></label><label class="field">季付（分）<input name="quarter_price" type="number" min="1"></label><label class="field">半年付（分）<input name="half_year_price" type="number" min="1"></label><label class="field">年付（分）<input name="year_price" type="number" min="1"></label><label class="field">两年付（分）<input name="two_year_price" type="number" min="1"></label><label class="field">一次性（分）<input name="onetime_price" type="number" min="1"></label></div><div class="form-actions"><button class="btn btn-primary" type="submit">保存销售套餐</button><span class="hint">至少设置一个大于 0 的周期价格</span></div></form></fieldset><div id="plans" class="data-list" style="margin-top:15px"></div></div>
                    </section>
                </div>
                <div>
                    <section class="panel"><div class="panel-head"><div><h2>可用基础套餐</h2><p>管理员发布后才会显示。</p></div></div><div class="panel-body"><div id="templates" class="template-list"></div></div></section>
                    <section class="panel"><div class="panel-head"><div><h2>支付配置</h2><p>配置字段会按照管理员支付配置中的驱动定义生成，密钥由系统加密保存。</p></div></div><div class="panel-body"><fieldset data-sale-control><form id="payment-form" class="form-stack"><input type="hidden" name="id"><label class="field">支付驱动<select id="payment-driver" name="driver" required aria-describedby="payment-driver-help"></select><span id="payment-driver-help" class="field-help">选择驱动后填写对应的支付参数。</span></label><label class="field">支付名称<input name="name" placeholder="例如：支付宝" required></label><div id="payment-fields" class="payment-fields" aria-live="polite"><div class="empty-state">请选择支付驱动加载配置字段。</div></div><label class="field field-check"><input name="enabled" type="checkbox" value="1">启用此支付方式</label><button class="btn btn-primary" type="submit">保存支付配置</button></form></fieldset><div id="payments" class="data-list" style="margin-top:15px"></div></div></section>
                    <section class="panel"><div class="panel-head"><div><h2>销售概览</h2><p>按需读取当前店铺客户和订单数量。</p></div></div><div class="panel-body"><div class="form-actions"><button id="load-customers" class="btn btn-quiet" type="button">读取客户数</button><button id="load-orders" class="btn btn-quiet" type="button">读取订单数</button></div><div id="audit" class="data-list" style="margin-top:12px"><div class="empty-state">点击上方按钮查看统计。</div></div></div></section>
                </div>
            </div>
        </div>
    </main>
</section>
@php ($resellerAssetVersion = is_file(public_path('assets/reseller/app.js')) ? filemtime(public_path('assets/reseller/app.js')) : config('app.version'))
<script src="/assets/reseller/app.js?v={{ $resellerAssetVersion }}"></script>
</body>
</html>
