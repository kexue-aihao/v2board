<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>店铺入口</title>
    <style>
        :root { font-family: Inter, ui-sans-serif, system-ui, sans-serif; color:#17202a; background:#f4f6f8; } * { box-sizing:border-box; }
        body { min-height:100vh; display:grid; place-items:center; margin:0; padding:20px; } main { width:min(520px,100%); padding:34px; border:1px solid #e1e7ef; border-radius:12px; background:#fff; box-shadow:0 12px 30px rgba(16,24,40,.08); }
        h1 { margin:0 0 10px; } p { color:#667085; line-height:1.6; } form { display:flex; gap:8px; margin-top:24px; } input { flex:1; min-width:0; padding:11px 12px; border:1px solid #d0d5dd; border-radius:6px; } button { padding:11px 16px; border:0; border-radius:6px; color:#fff; background:#2671d9; cursor:pointer; }
        #message { min-height:22px; color:#b42318; margin-top:12px; }
    </style>
</head>
<body><main><h1>进入店铺</h1><p>请输入倒卖商提供的店铺标识，进入专属套餐和支付页面。</p><form id="store-form"><input name="slug" placeholder="例如 demo-store" pattern="[a-z0-9][a-z0-9-]{2,31}" required><button>进入</button></form><div id="message"></div></main>
<script>document.getElementById('store-form').addEventListener('submit',function(e){e.preventDefault();var slug=e.target.slug.value.trim().toLowerCase();if(!/^[a-z0-9][a-z0-9-]{2,31}$/.test(slug)){document.getElementById('message').textContent='店铺标识格式不正确';return;}window.location.href='/store/'+encodeURIComponent(slug);});</script>
</body>
</html>
