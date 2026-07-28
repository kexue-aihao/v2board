<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root { font-family:system-ui,sans-serif; color:#17202a; background:#f5f7fa; } body { margin:0; } main { max-width:1100px; margin:auto; padding:30px 18px; }
        section { background:#fff; border:1px solid #e4e7ec; border-radius:8px; padding:18px; margin:14px 0; } h1,h2 { margin-top:0; }
        input,textarea { width:100%; box-sizing:border-box; padding:9px; border:1px solid #d0d5dd; border-radius:6px; margin:5px 0 10px; } button { border:0; border-radius:6px; color:#fff; background:#246bce; padding:9px 13px; cursor:pointer; margin:3px; } button.secondary { background:#667085; }
        table { width:100%; border-collapse:collapse; } th,td { text-align:left; padding:9px; border-bottom:1px solid #eaecf0; } .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:12px; } .muted { color:#667085; } #message { color:#b42318; min-height:24px; }
    </style>
</head>
<body><main>
    <h1>倒卖商管理</h1><div id="message"></div>
    <section id="login"><h2>登录</h2><form id="login-form"><input name="email" type="email" placeholder="邮箱" required><input name="password" type="password" placeholder="密码" required><button>登录</button></form><h2>申请成为倒卖商</h2><form id="register-form"><input name="email" type="email" placeholder="邮箱" required><input name="password" type="password" minlength="8" placeholder="密码（至少 8 位）" required><input name="store_slug" placeholder="店铺标识，例如 demo-store" required><input name="store_name" placeholder="店铺名称" required><button type="submit" class="secondary">提交申请</button></form><p class="muted">倒卖商账号必须先经过管理员审核。</p></section>
    <div id="panel" hidden>
        <section><h2>店铺设置</h2><form id="store-form"><input name="store_slug" placeholder="店铺标识" required><input name="store_name" placeholder="店铺名称" required><textarea name="store_description" placeholder="店铺说明"></textarea><button>保存店铺</button></form></section>
        <section><h2>平台套餐模板</h2><div id="templates" class="grid"></div></section>
        <section><h2>我的销售套餐</h2><form id="plan-form"><input type="hidden" name="id"><input name="base_plan_id" placeholder="基础套餐 ID" required><input name="name" placeholder="展示名称" required><textarea name="content" placeholder="展示内容"></textarea><div class="grid"><input name="month_price" type="number" min="1" placeholder="月付价格（分）"><input name="quarter_price" type="number" min="1" placeholder="季付价格（分）"><input name="half_year_price" type="number" min="1" placeholder="半年价格（分）"><input name="year_price" type="number" min="1" placeholder="年付价格（分）"><input name="onetime_price" type="number" min="1" placeholder="一次性价格（分）"></div><button>保存套餐</button></form><div id="plans"></div></section>
        <section><h2>支付配置</h2><form id="payment-form"><select name="driver" id="payment-driver" required></select><input name="name" placeholder="支付名称" required><textarea name="config_json" placeholder="支付配置 JSON"></textarea><label><input type="checkbox" name="enabled" value="1"> 启用</label><button>保存支付</button></form><div id="payments"></div></section>
        <section><h2>客户和订单</h2><button id="load-customers" class="secondary">刷新客户</button><button id="load-orders" class="secondary">刷新订单</button><div id="audit"></div></section>
    </div>
</main><script src="/reseller/app.js"></script></body></html>
