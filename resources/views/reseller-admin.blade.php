<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #182230; background: #f4f6f8; }
        * { box-sizing: border-box; } body { margin: 0; } button, input, select { font: inherit; }
        .shell { min-height: 100vh; display: grid; grid-template-columns: 240px minmax(0, 1fr); }
        .sidebar { padding: 28px 20px; color: #d9e2f0; background: #162333; }
        .brand { display: flex; align-items: center; gap: 10px; margin-bottom: 42px; font-weight: 700; color: #fff; }
        .brand-mark { width: 32px; height: 32px; display: grid; place-items: center; border-radius: 8px; color: #14202f; background: #8ed1c5; font-weight: 800; }
        .nav-label { margin: 0 0 12px; color: #8ea0b8; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
        .nav-item { display: block; padding: 10px 12px; border-radius: 7px; color: #e8eef7; background: rgba(255,255,255,.08); }
        .main { min-width: 0; padding: 34px clamp(18px, 4vw, 52px); }
        .topbar { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 28px; }
        h1, h2, p { margin-top: 0; } h1 { margin-bottom: 8px; font-size: 28px; letter-spacing: 0; } h2 { margin-bottom: 5px; font-size: 17px; }
        .subtle { color: #64748b; } .status { color: #027a48; font-size: 13px; }
        .btn { border: 1px solid transparent; border-radius: 6px; padding: 9px 13px; color: #fff; background: #2671d9; cursor: pointer; }
        .btn:hover { background: #1f5fb8; } .btn.muted { color: #344054; border-color: #d0d5dd; background: #fff; } .btn.warn { background: #b54708; }
        .stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
        .stat, .panel { border: 1px solid #e1e7ef; border-radius: 10px; background: #fff; box-shadow: 0 2px 8px rgba(16,24,40,.03); }
        .stat { padding: 18px 20px; } .stat-label { color: #667085; font-size: 13px; } .stat-value { margin-top: 8px; font-size: 26px; font-weight: 700; }
        .panel { margin-bottom: 18px; overflow: hidden; } .panel-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding: 18px 20px; border-bottom: 1px solid #eaecf0; }
        .panel-body { padding: 18px 20px; } .toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .table-wrap { overflow-x: auto; } table { width: 100%; min-width: 720px; border-collapse: collapse; } th, td { padding: 13px 20px; text-align: left; border-bottom: 1px solid #eef1f4; white-space: nowrap; } th { color:#667085; font-size:12px; font-weight:600; background:#fafbfc; }
        .pill { display:inline-flex; padding:4px 8px; border-radius:999px; color:#344054; background:#eef2f6; font-size:12px; } .pill.pending { color:#9a6700; background:#fff4cc; } .pill.active { color:#027a48; background:#dcfae6; } .pill.suspended { color:#b42318; background:#fee4e2; }
        .template-grid, .driver-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 12px; } .template, .driver { padding: 15px; border: 1px solid #e1e7ef; border-radius: 8px; }
        .template-title { display:flex; justify-content:space-between; gap:8px; font-weight:600; } .template p, .driver p { margin: 8px 0 0; color:#667085; font-size:13px; }
        .driver label { display:flex; align-items:center; gap:8px; margin-top:10px; font-size:13px; } .driver input { width:16px; height:16px; }
        .empty { padding: 22px 0; color:#667085; text-align:center; } #message { min-height: 22px; margin-bottom: 12px; }
        @media (max-width: 760px) { .shell { display:block; } .sidebar { padding:16px 18px; } .brand { margin-bottom:16px; } .nav-item { display:inline-block; } .main { padding:24px 16px; } .stats { grid-template-columns:1fr; } .topbar { display:block; } .topbar .toolbar { margin-top:14px; } }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar"><div class="brand"><span class="brand-mark">R</span><span>Reseller 管理</span></div><p class="nav-label">平台管理</p><span class="nav-item">倒卖商审批</span></aside>
    <main class="main">
        <div class="topbar"><div><h1>倒卖商审批</h1><p class="subtle">审核店铺账号，发布可销售套餐，并管理支付驱动白名单。</p></div><div class="toolbar"><button id="refresh" class="btn muted">刷新数据</button><button id="logout" class="btn muted">退出页面</button></div></div>
        <div id="message" class="status"></div>
        <section class="stats"><div class="stat"><div class="stat-label">待审核账号</div><div id="pending-count" class="stat-value">-</div></div><div class="stat"><div class="stat-label">已启用店铺</div><div id="active-count" class="stat-value">-</div></div><div class="stat"><div class="stat-label">已发布模板</div><div id="template-count" class="stat-value">-</div></div></section>
        <section class="panel"><div class="panel-head"><div><h2>账号审批</h2><p class="subtle">通过后倒卖商才能登录并销售。</p></div></div><div class="table-wrap"><table><thead><tr><th>账号</th><th>店铺</th><th>注册时间</th><th>状态</th><th>操作</th></tr></thead><tbody id="accounts"></tbody></table></div></section>
        <section class="panel"><div class="panel-head"><div><h2>基础套餐模板</h2><p class="subtle">倒卖商只能选择已发布的基础套餐。</p></div></div><div class="panel-body"><form id="template-form" class="toolbar"><input name="base_plan_id" type="number" placeholder="基础套餐 ID" required><select name="enabled"><option value="1">发布</option><option value="0">撤下</option></select><input name="sort" type="number" value="0" placeholder="排序"><button class="btn">保存模板</button></form><div id="templates" class="template-grid" style="margin-top:16px"></div></div></section>
        <section class="panel"><div class="panel-head"><div><h2>支付驱动白名单</h2><p class="subtle">只允许已安装且被管理员勾选的支付驱动。</p></div><button id="save-drivers" class="btn">保存白名单</button></div><div id="drivers" class="panel-body driver-grid"></div></section>
    </main>
</div>
<script>window.ADMIN_API_PREFIX = '/api/v1/{{ $secure_path }}';</script>
<script src="/reseller-admin/app.js"></script>
</body>
</html>
