<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#355cc2">
    <title>店铺入口</title>
    <script>
        /* 与 storefront.blade.php 同一份盖章脚本、同一个 localStorage 键（storefront_theme），
           这样从入口页进店铺时明暗选择无缝延续。与 /reseller 的 reseller_theme 隔离。 */
        (function () {
            'use strict';
            var KEY = 'storefront_theme';
            var CHROME = { dark: '#171A1D', light: '#355cc2' };
            var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
            var manual = false;   // localStorage 不可用时（隐私模式 setItem 抛错）stored() 恒为 null，
                                  // 仅靠它守卫会让系统偏好变化推翻用户的显式选择。
            function stored() {
                try {
                    var value = localStorage.getItem(KEY);
                    return value === 'light' || value === 'dark' ? value : null;
                } catch (error) { return null; }
            }
            function apply(theme) {
                document.documentElement.dataset.theme = theme;
                var meta = document.querySelector('meta[name="theme-color"]');
                if (meta) meta.setAttribute('content', CHROME[theme]);
                var toggle = document.getElementById('theme-toggle');
                if (toggle) toggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
            }
            apply(stored() || (media && media.matches ? 'dark' : 'light'));
            function followSystem(event) {
                if (!manual && !stored()) apply(event.matches ? 'dark' : 'light');
            }
            if (media && media.addEventListener) media.addEventListener('change', followSystem);
            else if (media && media.addListener) media.addListener(followSystem);
            // 首次 apply() 在 <head> 解析期跑，#theme-toggle 尚未存在 → aria-pressed 补不上，DOM 就绪后补一次
            document.addEventListener('DOMContentLoaded', function () {
                var toggle = document.getElementById('theme-toggle');
                if (toggle) toggle.setAttribute('aria-pressed', document.documentElement.dataset.theme === 'dark' ? 'true' : 'false');
            });
            document.addEventListener('click', function (event) {
                if (!event.target.closest || !event.target.closest('#theme-toggle')) return;
                var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                manual = true;
                try { localStorage.setItem(KEY, next); } catch (error) {}
                apply(next);
            });
        }());
    </script>
    <style>
        /* ═══════════════════════════════════════════════════════════════════════════
           店铺入口页 —— EZ 设计语言，令牌层与 storefront.blade.php / reseller.blade.php 同源。
           本页唯一的 JS 是文末那段内联提交脚本，它依赖 #store-form、input[name="slug"]、
           #message 三处；除此之外无外部契约。
           ═══════════════════════════════════════════════════════════════════════════ */

        /* ── 1. 令牌 · 亮色（EZ variables.scss:6-37 逐字 + 本页需要的派生项）───────── */
        :root {
            --background-color: #f5f7fa;
            --card-background-rgb: 255, 255, 255;           /* 毛玻璃配方：.theme-toggle */
            --text-color: #333333;
            --text-color-rgb: 51, 51, 51;                   /* ⚠ 暗色下翻转为 255,255,255：下面两个「明暗通用」令牌靠它反转 */
            --secondary-text-color: #666666;
            --border-color: #e8e8e8;
            --shadow-color: rgba(0, 0, 0, 0.1);

            --theme-color: #355cc2;
            --theme-color-rgb: 53, 92, 194;
            --theme-on: #ffffff;                            /* 主色实底上的文字：6.07:1，明暗恒定 */
            --theme-ink: #355cc2;                           /* 可作文字的主色；暗色下必须换，见 §2 */
            --theme-hover: #2d4ea5;
            --theme-focus: rgba(var(--theme-color-rgb), .25);

            --surface: #ffffff;
            --control-bg: #ffffff;
            --control-border: rgba(var(--text-color-rgb), .18);     /* 明暗通用 */
            --error-ink: #c42b2b;                           /* on --surface = 4.95:1（暗色见 §2） */

            /* 本页只有一张卡片，用 --shadow-modal；--shadow-float 供 .theme-toggle 毛玻璃用。
               销售页那份令牌表里的其余项（功能色成套、--shadow-card、--chip-bg 等）本页无消费点，
               刻意不声明——入口页只留真正用到的，避免死令牌。 */
            --shadow-float: 0 4px 15px rgba(0, 0, 0, .10);
            --shadow-modal: 0 22px 60px var(--shadow-color);

            --radius-sm: 8px;
            --radius-card: 12px;
            --control-h: 45px;
            --ease: .3s ease;
            --ease-fast: .2s ease;
            --z-brand: 110;
            --disabled-opacity: .58;

            color-scheme: light;                            /* 原生控件与滚动条跟随明暗 */
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                "Helvetica Neue", "PingFang SC", "Microsoft YaHei", "Noto Sans CJK SC", Arial, sans-serif;
        }

        /* ── 2. 令牌 · 暗色（挂属性，由 <head> 盖章脚本驱动；整页只有这一套机制）──── */
        :root[data-theme="dark"] {
            color-scheme: dark;
            --background-color: #171A1D;
            --card-background-rgb: 30, 30, 30;
            --text-color: rgba(255, 255, 255, .9);
            --text-color-rgb: 255, 255, 255;
            --secondary-text-color: rgba(255, 255, 255, .6);
            --border-color: rgba(255, 255, 255, .1);
            --shadow-color: rgba(0, 0, 0, .3);
            --surface: #1e1e1e;                             /* 面元不透明：= EZ --card-background-rgb 去 alpha */
            --control-bg: #262626;
            --theme-ink: #90a5dd;                           /* #355cc2 在 #1e1e1e 上只有 2.75:1；这个是 6.84:1 */
            --theme-hover: #4a70d4;
            --theme-focus: rgba(var(--theme-color-rgb), .35);
            --error-ink: #f87979;                           /* on #1e1e1e = 7.0:1 */
            --shadow-float: 0 4px 15px rgba(0, 0, 0, .30);
            --disabled-opacity: .65;
        }
        /* 无毛玻璃兜底见 §4 末尾 —— 必须放在 .theme-toggle 规则之后，否则同特异度被源序压掉。 */

        /* ── 3. reset 与元素基线 ───────────────────────────────────────────────── */
        * { box-sizing: border-box; }
        [hidden] { display: none !important; }
        html { background: var(--background-color); }
        body { min-height: 100vh; min-height: 100dvh; display: grid; margin: 0; padding: 20px; place-items: center; color: var(--text-color); background: var(--background-color); line-height: 1.5; -webkit-font-smoothing: antialiased; }
        button, input { font: inherit; }
        button { display: inline-flex; height: var(--control-h); align-items: center; justify-content: center; gap: 7px; padding: 0 18px; border: 1px solid transparent; border-radius: var(--radius-sm); color: var(--theme-on); background: var(--theme-color); font-size: 14px; font-weight: 600; white-space: nowrap; cursor: pointer; transition: background-color var(--ease); }
        button:hover:not(:disabled) { background: var(--theme-hover); }
        button:disabled { cursor: not-allowed; opacity: var(--disabled-opacity); }
        input { width: 100%; height: var(--control-h); padding: 0 12px; border: 1px solid var(--control-border); border-radius: var(--radius-sm); outline: 0; color: var(--text-color); background: var(--control-bg); transition: border-color var(--ease), box-shadow var(--ease); }
        /* 焦点边框用 --theme-ink：上面 outline:0 拿掉了 UA 焦点环，指示器只剩这条边框，必须自己
           达 3:1。--theme-color 对暗色 --control-bg #262626 只有 2.49:1，--theme-ink 是 6.21:1。 */
        input:focus { border-color: var(--theme-ink); box-shadow: 0 0 0 2px var(--theme-focus); }
        input::placeholder { color: var(--secondary-text-color); }
        h1 { margin: 0; }
        p { margin: 0; line-height: 1.7; }

        /* ── 4. 明暗切换按钮（与销售页同款；自带 height:auto 压掉元素级按钮高度）──── */
        /* ⚠ 必须带 button 类型：元素级 button:hover:not(:disabled) 是 (0,2,1)，压过
             .theme-toggle:hover 的 (0,2,0)，会把按钮 hover 成主色实底、图标掉到 1.26:1。 */
        button.theme-toggle { position: fixed; z-index: var(--z-brand); top: 20px; right: 20px; display: grid; width: 42px; height: 42px; place-items: center; padding: 0; border: 1px solid var(--border-color); border-radius: 50%; color: var(--secondary-text-color); background: rgba(var(--card-background-rgb), .7); box-shadow: var(--shadow-float); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); transition: color var(--ease-fast), border-color var(--ease-fast); }
        button.theme-toggle:hover:not(:disabled) { color: var(--theme-ink); border-color: var(--theme-color); background: rgba(var(--card-background-rgb), .7); }
        .theme-toggle svg { width: 20px; height: 20px; }
        @supports not ((backdrop-filter: blur(4px)) or (-webkit-backdrop-filter: blur(4px))) {
            button.theme-toggle, button.theme-toggle:hover:not(:disabled) { background: var(--surface); }
        }
        .theme-toggle .icon-sun { display: none; }
        :root[data-theme="dark"] .theme-toggle .icon-sun { display: block; }
        :root[data-theme="dark"] .theme-toggle .icon-moon { display: none; }

        /* ── 5. 入口卡片（EZ 卡片基型）───────────────────────────────────────────── */
        .entry-shell { width: min(520px, 100%); }
        .entry-card { padding: 34px; border: 1px solid var(--border-color); border-radius: var(--radius-card); background: var(--surface); box-shadow: var(--shadow-modal); }
        .page-eyebrow { margin: 0 0 8px; color: var(--theme-ink); font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        .page-title { margin: 0 0 10px; font-size: 28px; font-weight: 700; line-height: 1.2; }
        .subtle { color: var(--secondary-text-color); font-size: 14px; }
        .entry-form { display: flex; gap: 8px; margin-top: 24px; }
        .entry-form input { flex: 1; min-width: 0; }
        .entry-form button { flex: 0 0 auto; }
        /* 内联脚本只写一种固定错误文案、且不写内联色（不像销售页的 show()），所以可以全令牌化。
           :empty 折叠依赖零子节点 → 标签内不能有空白字符。 */
        .entry-message { margin-top: 12px; color: var(--error-ink); font-size: 13px; }
        /* ⚠ 空态只清外边距、不用 display:none：这是 role=status / aria-live 区，display:none 会把它
             从无障碍树里摘掉，而内联脚本只写 textContent，「脱树后随同一次变更重新入树」是读屏最不
             可能播报的情形。空 div 高度本就是 0，清掉 12px 上边距即完全无占位。 */
        .entry-message:empty { margin-top: 0; }

        /* ── 6. 服务未开放页 ────────────────────────────────────────────────────── */
        /* body 是 place-items:center 的 grid，卡片天然居中；margin-top 给 fixed 切换按钮让位，
           避免矮视口下按钮压在卡片右上角。 */
        .service-closed { width: min(680px, 100%); margin-top: 42px; padding: 42px 34px; border: 1px solid var(--border-color); border-radius: var(--radius-card); background: var(--surface); box-shadow: var(--shadow-modal); text-align: center; }
        .service-closed h1 { margin: 0 0 10px; font-size: 26px; font-weight: 700; }
        .service-closed p { color: var(--secondary-text-color); font-size: 14px; line-height: 1.8; }

        /* ── 7. 响应式 ──────────────────────────────────────────────────────────── */
        @media (max-width: 576px) {
            body { padding: 14px; }
            .entry-card { padding: 24px 20px; }
            .page-title { font-size: 24px; }
            .entry-form { flex-direction: column; }
            .entry-form button { width: 100%; }
            .theme-toggle { top: 14px; right: 14px; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { scroll-behavior: auto !important; transition: none !important; animation: none !important; }
        }
    </style>
</head>
<body>
<button id="theme-toggle" class="theme-toggle" type="button" aria-label="深色模式" aria-pressed="false">
    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
@if (!$reseller_enabled)
{{-- 刻意不加 role="status"：显式 role 会覆盖 <main> 的隐含 landmark 角色，而这段是静态文案。 --}}
<main class="service-closed">
    <h1>倒卖商服务暂未开放</h1>
    <p>当前暂未开放店铺销售和客户购买，请稍后再试。</p>
</main>
@else
<main class="entry-shell">
    <section class="entry-card">
        <p class="page-eyebrow" lang="en">Subscription Store</p>
        <h1 class="page-title">进入店铺</h1>
        <p class="subtle">请输入倒卖商提供的店铺标识，进入专属套餐和支付页面。</p>
        {{-- ⚠ #store-form 与 input[name="slug"] 被文末内联脚本消费（e.target.slug.value），
             pattern 与 name 原样保留。
             已知行为（沿袭改造前，未改）：原生 pattern 校验先于 submit 事件跑，所以坏 slug 由浏览器
             气泡拦下、下面 #message 的错误分支实际不可达；且大写输入（如 Demo-Store）会被 pattern
             直接拒绝，而不会被脚本的 trim+toLowerCase 归一化。这里只补 title 让原生气泡说清规则；
             要让脚本独占校验（从而接受大写）需去掉 pattern，属行为变更，留给站长定夺。 --}}
        <form id="store-form" class="entry-form">
            <input name="slug" placeholder="例如 demo-store" pattern="[a-z0-9][a-z0-9-]{2,31}" required
                   aria-label="店铺标识" title="3–32 位小写字母、数字或连字符，且以字母或数字开头">
            <button type="submit">进入</button>
        </form>
        {{-- ⚠ 标签内零空白字符：:empty 空态折叠依赖零子节点 --}}
        <div id="message" class="entry-message" role="status" aria-live="polite"></div>
    </section>
</main>
<script>document.getElementById('store-form').addEventListener('submit',function(e){e.preventDefault();var slug=e.target.slug.value.trim().toLowerCase();if(!/^[a-z0-9][a-z0-9-]{2,31}$/.test(slug)){document.getElementById('message').textContent='店铺标识格式不正确';return;}window.location.href='/store/'+encodeURIComponent(slug);});</script>
@endif
</body>
</html>
