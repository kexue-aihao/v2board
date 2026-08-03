<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#355cc2">
    <title>订阅店铺</title>
    <script>
        /* 明暗盖章必须先于首帧：按「localStorage 显式选择 → 系统偏好」把 data-theme 写到
           根元素上，暗色令牌全部挂在 :root[data-theme="dark"] 下（见样式表 §2）。右上角
           #theme-toggle 的点击也在这里用事件委托处理——刻意不进 app.js：那份文件保持零改动。
           本脚本与 app.js 无关，所以关服分支（不输出 app.js）里明暗切换照常可用。
           键名与 /reseller 的 reseller_theme 隔离，与 /store 入口页共用（进店时明暗延续）。 */
        (function () {
            'use strict';
            var KEY = 'storefront_theme';
            var CHROME = { dark: '#171A1D', light: '#355cc2' };   // 移动端浏览器 chrome：暗色跟页底，亮色保持品牌色
            var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
            var manual = false;   // 本次会话内点过切换。localStorage 不可用时（隐私模式 setItem 抛错）
                                  // stored() 恒为 null，仅靠它守卫会让随后的系统偏好变化推翻用户的显式选择。
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
                // 让读屏/语音控制能感知当前处于哪一态（按钮是切换开关，不是普通动作）
                var toggle = document.getElementById('theme-toggle');
                if (toggle) toggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
            }
            apply(stored() || (media && media.matches ? 'dark' : 'light'));
            function followSystem(event) {
                if (!manual && !stored()) apply(event.matches ? 'dark' : 'light');
            }
            if (media && media.addEventListener) media.addEventListener('change', followSystem);
            else if (media && media.addListener) media.addListener(followSystem);
            // 首次 apply() 发生在 <head> 解析期，此时 <body> 里的 #theme-toggle 还不存在，
            // 所以 aria-pressed 补不上（markup 里预置的 false 在暗色首屏就是错的）。DOM 就绪后补一次。
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
           店铺销售页样式表 —— 采用 EZ 主题的设计语言，与 /reseller 同一套令牌与配方。
           本页无构建步骤，CSS 必须内联；配套 JS 是 public/storefront/app.js（原生 ES5，742 行）。

           ⚠ 修改前先读文件末尾的「JS 耦合契约」注释块。app.js 大量用 innerHTML 注入带类名的
             HTML、用 element.hidden 做互斥显示、并依赖若干结构位置；改类名或改嵌套会让功能
             静默失效（多数不报错）。
           ⚠ 与 /reseller 的三处刻意差异：① 卡片基型选择器合写 .dashboard-card, .section
             （app.js:378 注入的 #shared-section 只挂 class="section"，类名送不进注入 DOM）；
             ② 卡头用 .section-head + h2 + p.subtle（app.js:380 注入的就是这套）；
             ③ 主按钮与控件走**元素级** button / input,select 基线（注入的周期按钮与共享邀请
             表单全是裸元素，.btn 类族无法送达）。因此新增裸 <button> 会自动渲染成主色按钮，
             需要次要样式请显式挂 .secondary / .text-button。
           ═══════════════════════════════════════════════════════════════════════════ */

        /* ── 1. 令牌 · 亮色 ─────────────────────────────────────────────────────── */
        :root {
            /* 1.1 EZ 基础令牌：逐字取自 theme-src/ez/src/assets/styles/base/variables.scss:6-37。勿改数值。 */
            --background-color: #f5f7fa;
            /* --card-background 本页无消费点，保留声明纯为 EZ 片段可移植性（移过来的 EZ 代码用
               var(--card-background) 应得到 EZ 的原本行为，含暗色下的半透明）。本页毛玻璃走
               --card-background-rgb + .7，不透明兜底走 --surface。 */
            --card-background: #ffffff;
            --card-background-rgb: 255, 255, 255;           /* 毛玻璃配方：.theme-toggle */
            --text-color: #333333;
            --text-color-rgb: 51, 51, 51;                   /* ⚠ 暗色下翻转为 255,255,255：下面 4 个「明暗通用」令牌全靠这个反转，勿改 */
            --secondary-text-color: #666666;
            --border-color: #e8e8e8;
            --shadow-color: rgba(0, 0, 0, 0.1);             /* 被 --shadow-modal 消费，暗色下自动变 .3 */
            --success-color: #00B42A;  --success-background: #E8FFEA;  --success-color-rgb: 0, 180, 42;
            --warning-color: #FF7D00;  --warning-background: #FFF7E8;  --warning-color-rgb: 255, 125, 0;
            --error-color: #F53F3F;    --error-background: #FFECE8;    --error-color-rgb: 245, 63, 63;

            /* 1.2 主题色：固定 EZ 上游默认 #355cc2，不跟随后台主题配置（本页是平台级页面，无后端改动） */
            --theme-color: #355cc2;
            --theme-color-rgb: 53, 92, 194;
            --theme-on: #ffffff;                            /* 主色实底上的文字：6.07:1，明暗恒定 */
            --theme-ink: #355cc2;                           /* 可作文字/图形的主色。暗色下必须换，见 §2 */
            --theme-hover: #2d4ea5;                         /* 有意偏离 EZ：EZ 派生的 rgba(theme,.9) 叠白底得 #486ac8，反而变亮 */
            --theme-soft: rgba(var(--theme-color-rgb), .08);
            --theme-focus: rgba(var(--theme-color-rgb), .25);

            /* 1.3 面元阶梯。规则：暗色下面元一律不透明 hex；alpha 只允许出现在边框 / 阴影 /
                   焦点环 / 已知父级上的叠加层。 */
            --surface: #ffffff;
            --control-bg: #ffffff;
            --control-bg-hover: rgba(var(--text-color-rgb), .05);   /* 明暗通用（依赖 --text-color-rgb 反转） */
            --control-border: rgba(var(--text-color-rgb), .18);     /* 明暗通用 */
            --chip-bg: rgba(var(--text-color-rgb), .04);            /* 明暗通用；已知父级（--surface 卡片）上的叠加层 */

            /* 1.4 语义 ink 层。EZ 自己的 *-color on *-background 只有 2.41–3.25:1（文字不合规），
                   所以色底上的文字一律走 -ink 层保 4.5:1。
                   本页真正消费的只有 --success-ink 与 --error-ink（算术验证的对/错状态文字）；
                   其余 *-color / *-color-rgb / *-background / *-line / --warning-* 全无消费点，
                   保留是为了三组功能色成套（EZ API 对等：移过来的 EZ 片段能直接跑）。 */
            --success-ink: #007a1c;  --success-line: rgba(var(--success-color-rgb), .30);   /* on #E8FFEA = 5.24:1 */
            --warning-ink: #9a5b00;  --warning-line: rgba(var(--warning-color-rgb), .30);   /* on #FFF7E8 = 5.09:1 */
            --error-ink:   #c42b2b;  --error-line:   rgba(var(--error-color-rgb),   .30);   /* on #FFECE8 = 4.95:1 */

            /* 1.5 阴影（整条 box-shadow 值，不是颜色） */
            --shadow-card:  0 2px 10px rgba(0, 0, 0, .05);           /* = EZ .dashboard-card */
            --shadow-float: 0 4px 15px rgba(0, 0, 0, .10);           /* = EZ card:hover / 毛玻璃 */
            --shadow-modal: 0 22px 60px var(--shadow-color);

            /* 1.6 几何 / 运动 / 层级 */
            --radius-sm: 8px;                               /* EZ 控件圆角（吸收旧表全部 6px 和 7px） */
            --radius-card: 12px;                            /* EZ .dashboard-card（旧表 10px） */
            --control-h: 45px;                              /* EZ 控件高度 */
            --control-h-sm: 32px;                           /* 密集行内的小按钮，EZ 无此档 */
            --ease: .3s ease;
            --ease-fast: .2s ease;
            --z-brand: 110;
            --disabled-opacity: .58;

            /* 1.7 让 <option> 下拉、复选框和原生 confirm（app.js:408 移除成员确认）跟随明暗。
                   这是 CSS 规则无法替代的一项：缺了它，暗色下浅色文字会落在 OS 渲染的白底选项列表上。 */
            color-scheme: light;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                "Helvetica Neue", "PingFang SC", "Microsoft YaHei", "Noto Sans CJK SC", Arial, sans-serif;
        }

        /* ── 2. 令牌 · 暗色 ─────────────────────────────────────────────────────────
           挂在 :root[data-theme="dark"] 上而非裸 media query：<head> 顶部的内联脚本在首帧
           渲染前按「localStorage('storefront_theme') 显式选择 → 系统偏好」次序给根元素盖章。
           整页只有这一套明暗机制（EZ 是 body.dark-theme 与组件内裸 prefers-color-scheme
           双轨打架，手动选亮色时部分组件仍跟系统变暗；这里的暗色规则只认属性不认系统）。 */
        :root[data-theme="dark"] {
            color-scheme: dark;

            /* EZ variables.scss:40-49 逐字（EZ 只覆盖这 8 个，功能色四组不覆盖） */
            --background-color: #171A1D;
            --card-background: rgba(30, 30, 30, 0.8);
            --card-background-rgb: 30, 30, 30;
            --text-color: rgba(255, 255, 255, .9);
            --text-color-rgb: 255, 255, 255;
            --secondary-text-color: rgba(255, 255, 255, .6);
            --border-color: rgba(255, 255, 255, .1);
            --shadow-color: rgba(0, 0, 0, .3);

            /* 面元：不透明。#1e1e1e = EZ 自己 --card-background-rgb 去 alpha（Invite.vue 的 fallback 写的就是它） */
            --surface: #1e1e1e;
            --control-bg: #262626;                          /* = rgba(255,255,255,.04) over #1e1e1e，输入框比面板亮一档 */

            --theme-ink: #90a5dd;                           /* #355cc2 在 #1e1e1e 上只有 2.75:1；这个是 6.84:1。EZ 自己没处理 */
            --theme-hover: #4a70d4;                         /* 暗色下 hover 提亮而非压暗 */
            --theme-soft: rgba(var(--theme-color-rgb), .16);
            --theme-focus: rgba(var(--theme-color-rgb), .35);

            /* 语义底：不透明 hex，值 = rgba(<X-color-rgb>, .15) 合成到 #1e1e1e 的算术结果 */
            --success-background: #1a3520;  --success-ink: #00B42A;   /* 4.81:1 */
            --warning-background: #402c1a;  --warning-ink: #FF7D00;   /* 5.13:1 */
            --error-background:   #3e2323;  --error-ink:   #f87979;   /* 5.44:1 */

            --shadow-card:  0 2px 10px rgba(0, 0, 0, .15);
            --shadow-float: 0 4px 15px rgba(0, 0, 0, .30);

            --disabled-opacity: .65;                        /* .58 会把 rgba(255,255,255,.6) 压到 3.4:1 */
        }
        /* 无毛玻璃兜底见 §5 末尾 —— 必须放在 .theme-toggle 规则之后：同为 (0,1,0) 时源序决定胜负，
           放在这里会被后面的 rgba 底色压掉，成为死代码。 */

        /* ── 3. reset 与元素基线 ───────────────────────────────────────────────── */
        * { box-sizing: border-box; }
        /* ⚠ 勿删 !important：app.js 有 15+ 处 element.hidden 赋值做互斥显示，而这些元素的
             display 是 grid/flex，会盖掉 UA 的 [hidden]{display:none}。涉及
             #auth-section / #orders-section / #checkout-section / #subscription-section /
             #shared-section(注入) / 三个 form / #arithmetic-field / #recaptcha-field /
             #logout / #shared-owner-controls(注入)。 */
        [hidden] { display: none !important; }
        html { background: var(--background-color); }
        body { min-width: 320px; margin: 0; color: var(--text-color); background: var(--background-color); line-height: 1.5; -webkit-font-smoothing: antialiased; }
        button, input, select { font: inherit; }             /* ⚠ 字体栈进入表单控件的唯一通路 */
        /* ⚠ 元素级基线（不是类）：app.js 注入的按钮（:340 周期按钮、:382 共享邀请 submit）与
             输入框全是裸元素，类名送不进去，只有元素选择器能覆盖到它们。
             代价：blade 里新增的裸 <button> 也会是主色按钮——要次要样式请挂 .secondary。 */
        button { display: inline-flex; height: var(--control-h); align-items: center; justify-content: center; gap: 7px; padding: 0 18px; border: 1px solid transparent; border-radius: var(--radius-sm); color: var(--theme-on); background: var(--theme-color); font-size: 14px; font-weight: 600; white-space: nowrap; cursor: pointer; transition: color var(--ease), border-color var(--ease), background-color var(--ease); }
        button:hover:not(:disabled) { background: var(--theme-hover); }
        button.secondary { color: var(--text-color); border-color: var(--control-border); background: var(--control-bg); }
        button.secondary:hover:not(:disabled) { color: var(--theme-ink); border-color: var(--theme-color); background: var(--control-bg-hover); }
        /* .text-button 是链接形（app.js:441 注入的「移除」按钮），必须自己压掉上面的高度与内距 */
        button.text-button { height: auto; padding: 0; border: 0; color: var(--theme-ink); background: transparent; font-size: 13px; font-weight: 600; }
        /* hover 用下划线而不是变色：--theme-hover 在暗色是 #4a70d4，对 --surface #1e1e1e 只有
           3.62:1（正文需 4.5:1）。保持 --theme-ink 不变色、加下划线，两态都达标且反馈更明确。 */
        button.text-button:hover:not(:disabled) { color: var(--theme-ink); background: transparent; text-decoration: underline; }
        button:disabled, input:disabled, select:disabled { cursor: not-allowed; opacity: var(--disabled-opacity); }
        input, select { width: 100%; height: var(--control-h); padding: 0 12px; border: 1px solid var(--control-border); border-radius: var(--radius-sm); outline: 0; color: var(--text-color); background: var(--control-bg); font-weight: 400; transition: border-color var(--ease), background-color var(--ease), box-shadow var(--ease); }
        /* ⚠ 焦点边框用 --theme-ink 而非 --theme-color：因为上面 outline:0 拿掉了 UA 的焦点环，
             焦点指示器只剩这条边框 + 焦点晕，必须自己达 1.4.11 的 3:1。--theme-color #355cc2 对
             暗色 --control-bg #262626 只有 2.49:1；--theme-ink 暗色切 #90a5dd 是 6.21:1，亮色下
             两者同值，一条规则通吃明暗。 */
        input:focus, select:focus { border-color: var(--theme-ink); box-shadow: 0 0 0 2px var(--theme-focus); }
        input::placeholder { color: var(--secondary-text-color); }
        /* label 是「文字 + 控件（+ 说明）」的容器（旧表与 app.js 注入侧同构，勿改成 block） */
        label { display: grid; gap: 6px; color: var(--text-color); font-size: 13px; font-weight: 600; }
        h1, h2, h3 { margin: 0; }
        p { margin: 0; line-height: 1.7; }
        .subtle { color: var(--secondary-text-color); }

        /* ── 4. 提示条 #message ───────────────────────────────────────────────────
           ⚠ 与 /reseller 的 .toast 完全不同构，不能照搬：app.js:31-34 的 show() 只写
             textContent + **内联 style.color**（硬编码 #b42318 / #027a48），从不设 hidden、
             无自动消失、也不写 className。所以：
             · 不做 fixed 浮层（JS 不会收起它，会永久遮挡内容）
             · 不与内联色对抗（需要 !important，且 CSS 无类可辨成功/失败）
             · 暗色下改为给一块固定浅色底座，让那两个硬编码色可读：
               #b42318 on #f5f7fa = 6.11:1，#027a48 on #f5f7fa = 5.02:1（亮色 6.63 / 5.42）
           ⚠ #message 标签内必须零空白字符，否则空白文本节点会让 :empty 失效、空态留下空壳。 */
        #message { margin: 0 0 16px; font-size: 13px; line-height: 1.6; }
        /* ⚠ 空态只清外边距、**不用 display:none**：这是全站唯一的 role=status / aria-live 区，
             app.js 的每一条反馈（登录失败、API 报错、共享邀请链接、支付轮询状态）都走它。
             display:none 会把它从无障碍树里摘掉，而 show() 只写 textContent —— 「先脱树、再随
             同一次变更重新入树」正是读屏最不可能播报的情形，等于把 aria-live 抵消掉。
             空 div 无 padding/border 时高度本就是 0（可见态的 padding/border/背景全挂在
             :not(:empty) 上），所以清掉 16px 下边距即完全无占位，视觉与 display:none 一致。 */
        #message:empty { margin: 0; }
        #message:not(:empty) { padding: 10px 14px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--surface); box-shadow: var(--shadow-card); overflow-wrap: anywhere; }
        :root[data-theme="dark"] #message:not(:empty) { border-color: rgba(0, 0, 0, .12); background: #f5f7fa; }

        /* ── 5. 明暗切换按钮 ─────────────────────────────────────────────────────
           页面级 chrome，三个视图（销售页 / 关服页 / 入口页）之外常驻。点击由 <head> 内联
           脚本委托处理，app.js 对它零感知；不在 setButtonLoading 名单里，允许含 SVG 子节点。
           自带 height:auto 压掉 §3 的元素级按钮高度。 */
        /* ⚠ 选择器必须带 button 类型：元素级 button:hover:not(:disabled) 是 (0,2,1)，而
             .theme-toggle:hover 只有 (0,2,0)，会被压成主色实底 + --theme-ink 图标（实测 1.26:1
             亮 / 1.89:1 暗，图标基本消失）。button.theme-toggle:hover:not(:disabled) = (0,3,1)，
             无歧义胜出（不靠源序打平）。aria-pressed 由 <head> 盖章脚本的 apply() 维护。 */
        button.theme-toggle { position: fixed; z-index: var(--z-brand); top: 20px; right: 20px; display: grid; width: 42px; height: 42px; place-items: center; padding: 0; border: 1px solid var(--border-color); border-radius: 50%; color: var(--secondary-text-color); background: rgba(var(--card-background-rgb), .7); box-shadow: var(--shadow-float); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); transition: color var(--ease-fast), border-color var(--ease-fast); }
        button.theme-toggle:hover:not(:disabled) { color: var(--theme-ink); border-color: var(--theme-color); background: rgba(var(--card-background-rgb), .7); }
        .theme-toggle svg { width: 20px; height: 20px; }
        /* 兜底放在上面两条之后才生效（同 (0,1,1)/(0,3,1) 层级 + 源序在后）。两态都要兜住，
           否则无毛玻璃的浏览器上 hover 态又会漏回半透明。 */
        @supports not ((backdrop-filter: blur(4px)) or (-webkit-backdrop-filter: blur(4px))) {
            button.theme-toggle, button.theme-toggle:hover:not(:disabled) { background: var(--surface); }
        }
        /* 亮色态显示月亮（点击进入暗色），暗色态显示太阳 */
        .theme-toggle .icon-sun { display: none; }
        :root[data-theme="dark"] .theme-toggle .icon-sun { display: block; }
        :root[data-theme="dark"] .theme-toggle .icon-moon { display: none; }

        /* ── 6. 页头 ─────────────────────────────────────────────────────────────── */
        .content-area { padding: 84px 1rem 2rem; }           /* 84px 顶部：给 fixed 的 .theme-toggle（top:20 + 42）让位，页头永不与之重叠 */
        .container { max-width: 1200px; margin: 0 auto; padding: 0 15px; }
        .page-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 20px; margin-bottom: 24px; }
        .page-eyebrow { margin: 0 0 8px; color: var(--theme-ink); font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        .page-title { font-size: clamp(28px, 5vw, 44px); font-weight: 700; line-height: 1.15; }
        .page-subtitle { max-width: 690px; margin-top: 10px; color: var(--secondary-text-color); font-size: 14px; }
        .account-chip { display: flex; align-items: center; justify-content: flex-end; gap: 10px; color: var(--secondary-text-color); font-size: 13px; white-space: nowrap; }
        .account-chip button { height: var(--control-h-sm); padding: 0 12px; font-size: 13px; }

        /* ── 7. 布局骨架 ─────────────────────────────────────────────────────────── */
        .layout { display: flex; align-items: flex-start; gap: 25px; }
        .main-column { flex: 1; min-width: 0; }
        /* 380px 不是随意取的：减去卡片 20×2 padding 后是 340px，容得下 reCAPTCHA 的 304px widget（见 §9） */
        .side-column { flex: 0 0 380px; min-width: 0; }
        /* 渐进增强：登录后 app.js:114 给 #auth-section 打 hidden，此时右栏是 380px 空壳，收起让位主列。
           不支持 :has 的浏览器保留空列 = 改造前的行为，属「不增强」而非回退。 */
        .side-column:has(> #auth-section[hidden]) { display: none; }

        /* ── 8. 卡片基型 ─────────────────────────────────────────────────────────
           ⚠ .dashboard-card 与 .section 必须合写：app.js:378 注入的 #shared-section 只挂
             class="section"，拆开会让共享面板失去卡片外观。 */
        .dashboard-card, .section { margin-bottom: 24px; padding: 20px; border: 1px solid var(--border-color); border-radius: var(--radius-card); background: var(--surface); box-shadow: var(--shadow-card); transition: border-color var(--ease), box-shadow var(--ease); }
        .dashboard-card:hover, .section:hover { border-color: rgba(var(--theme-color-rgb), .3); box-shadow: var(--shadow-float); }
        /* 卡头系统用 .section-head（app.js:380 注入的就是这套；刻意不引入 /reseller 的
           .card-header/.card-title/.card-desc，否则同页会有两种卡头） */
        .section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
        .section-head h2 { font-size: 18px; font-weight: 600; }
        .section-head p { margin: 5px 0 0; font-size: 12px; line-height: 1.7; }

        /* ── 9. 认证卡与表单 ─────────────────────────────────────────────────────── */
        .auth-card { display: grid; gap: 18px; }
        .auth-head h2 { font-size: 18px; font-weight: 600; }
        .auth-head p { margin-top: 6px; font-size: 13px; }
        /* ⚠ .auth-form 类也挂在 app.js:381 注入的 #shared-invite-form 上，靠这条吃到间距 */
        .auth-form { display: grid; gap: 15px; }
        /* 登录/注册切换：EZ 胶囊 + 滑块的观感，滑块是纯 CSS 的（只有 2 个等宽 tab，位置是常量，
           不需要 EZ SlideTabsNav 那套 offsetWidth 量算）→ 零 JS 改动，app.js:308-311 已在切
           .is-active，兄弟选择器直接吃这个状态。底用不透明 --control-bg（它在卡片内部，不像
           /reseller 那个浮在页面背景上，不需要毛玻璃）。 */
        .auth-tabs { position: relative; display: grid; grid-template-columns: 1fr 1fr; padding: 5px; border: 1px solid var(--border-color); border-radius: 30px; background: var(--control-bg); }
        .auth-tab { position: relative; z-index: 2; height: 38px; padding: 6px 16px; border: 0; border-radius: 26px; color: var(--secondary-text-color); background: transparent; font-size: 14px; font-weight: 500; transition: color var(--ease); }
        .auth-tab:hover:not(:disabled), .auth-tab.is-active { color: var(--text-color); background: transparent; }
        /* 滑块是「哪个 tab 被选中」的主要指示器（1.4.11 的 UI 状态，需 3:1）。边框用 --theme-ink：
           --theme-color 对暗色 --control-bg 只有 2.49:1，--theme-ink 暗色切 #90a5dd 得 6.21:1。 */
        .slider-indicator { position: absolute; z-index: 1; top: 5px; bottom: 5px; left: 5px; width: calc(50% - 5px); border: 1px solid var(--theme-ink); border-radius: 26px; background: rgba(var(--theme-color-rgb), .1); box-shadow: 0 4px 15px rgba(var(--theme-color-rgb), .1); pointer-events: none; transition: transform .6s cubic-bezier(.25, .1, .25, 1); }
        .auth-tab[data-auth-mode="register"].is-active ~ .slider-indicator { transform: translateX(100%); }
        /* 渐进增强：2FA 态下藏起 tab。改造前点任一 tab 都会走 setAuthMode 清掉 challenge，
           属既有 UX 缺陷；不支持 :has 时 tab 可见 = 改造前的行为。 */
        .auth-card:has(#two-factor-form:not([hidden])) .auth-tabs { display: none; }
        .form-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .form-actions > button[type="submit"] { flex: 1; min-width: 120px; }
        .field-note { color: var(--secondary-text-color); font-size: 12px; font-weight: 400; line-height: 1.6; }
        /* 算术验证块：chip 底是「已知父级（--surface 卡片）上的叠加层」，按 §1.3 允许用 alpha */
        .security-field { display: grid; gap: 8px; padding: 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--chip-bg); font-size: 13px; }
        .security-expression { color: var(--text-color); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 15px; font-weight: 700; }
        .inline-row { display: flex; gap: 8px; }
        .inline-row input { min-width: 0; }
        .inline-row button { flex: 0 0 auto; padding: 0 14px; }
        /* ⚠ app.js:232 整体覆写 className = 'security-status' (+ ' is-good' | ' is-error')，
             所以此元素上不能依赖任何别的类，且基类与两个修饰类必须各自独立成立。
             min-height 防状态文字出现/消失时的跳动。 */
        .security-status { min-height: 18px; margin: 0; color: var(--secondary-text-color); font-size: 12px; }
        .security-status.is-good { color: var(--success-ink); }
        .security-status.is-error { color: var(--error-ink); }
        /* ⚠ 刻意没有 overflow:hidden：旧表那条会把 304px 的 reCAPTCHA widget 裁掉约 34px（不可滚、
             直接看不见）。容器整个生命周期只被 grecaptcha.render 一次（app.js:218），不能重建/移动。
             宽度账（实测，≤768 档）：视口 − content-area 10×2 − container 10×2 − 卡片边框 1×2
             − 卡片 padding 20×2（≤480 档收成 14×2）。所以 1440 得 338、768 得 671、375 得 305、
             360 只有 290 < 304 —— 窄屏必须给平移逃生口，见 §13 的 480 档。 */
        .captcha-wrap { min-height: 78px; }
        /* iframe 内容无法主题化 → 暗色下给它一块浅色基座，成为「有意为之的浅色岛」（同 #message 的决策）。
           :not([hidden]) 避免空态留下一条 12px 白边。#fff 是刻意的硬编码（reCAPTCHA 自身背景）。 */
        :root[data-theme="dark"] .captcha-wrap:not([hidden]) { width: fit-content; max-width: 100%; padding: 6px; border-radius: var(--radius-sm); background: #fff; }
        .two-factor-help { color: var(--secondary-text-color); font-size: 13px; line-height: 1.6; }

        /* ── 10. 内容零件（多数由 app.js 注入，类名不可改）───────────────────────── */
        .plans { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
        /* ⚠ app.js:338 注入 article.plan，结构固定为 h3 + p（第一个 p 是描述）+ .plan-actions；
             decorateSharedPlans(:358-364) 按 API 数组顺序索引对齐 #plans .plan，往「卡内第一个
             p」的 afterend 插 small.shared-plan-note。p 的 15px 下边距是那枚徽章 margin:-8px 的基准。 */
        .plan { display: flex; min-height: 190px; flex-direction: column; padding: 17px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--chip-bg); transition: border-color var(--ease-fast), background-color var(--ease-fast); }
        .plan:hover { border-color: rgba(var(--theme-color-rgb), .3); }
        .plan h3 { margin: 0 0 8px; font-size: 16px; font-weight: 600; }
        .plan p { min-height: 48px; margin: 0 0 15px; color: var(--secondary-text-color); font-size: 13px; }
        .plan-actions { display: flex; gap: 7px; flex-wrap: wrap; margin-top: auto; }
        .plan-actions button { height: var(--control-h-sm); padding: 0 12px; font-size: 12px; }
        .empty { grid-column: 1 / -1; padding: 22px 10px; color: var(--secondary-text-color); font-size: 12px; line-height: 1.7; text-align: center; }
        .checkout-row { display: flex; gap: 8px; }
        .checkout-row select { flex: 1; min-width: 0; }
        .checkout-row button { flex: 0 0 auto; }
        #trade-no { color: var(--text-color); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; }
        .order-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--border-color); font-size: 13px; }
        .order-row:last-child { border-bottom: 0; }
        /* ⚠ 必须保持后代选择器（不能改成 >）：app.js:441 往这个 span 里注入 button.text-button，
             app.js:605 注入的成员行也靠它拿样式。 */
        .order-row span { display: inline-flex; align-items: center; justify-content: flex-end; gap: 7px; flex-wrap: wrap; color: var(--secondary-text-color); text-align: right; }
        .subscription-summary { display: grid; gap: 11px; padding: 14px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: var(--theme-soft); }
        .subscription-summary strong { font-size: 15px; }
        .subscription-summary p { margin: 0; color: var(--secondary-text-color); font-size: 13px; }
        .subscription-meta { display: flex; gap: 7px 14px; flex-wrap: wrap; color: var(--secondary-text-color); font-size: 13px; }
        /* ⚠ #subscribe-url 必须是真实可聚焦的 <input readonly>：复制降级路径（app.js:487-490）
             走 focus() + select() + execCommand，换成 <code> 或设 pointer-events:none 会失效。 */
        .subscription-url { display: flex; align-items: stretch; gap: 8px; }
        .subscription-url input { min-width: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
        .subscription-url button { flex: 0 0 auto; }

        /* ── 11. 共享面板：夺回暗色控制权 ─────────────────────────────────────────
           ⚠ app.js:373-375 会往 document.head 追加一段硬编码浅色 <style>（白底 #f8fbff、
             浅蓝轨道 #e6edf5、主色 #246bce），位置在本表**之后**，同特异度下它胜出。
             对策：下面 5 条各加一个 :root 前缀提权到 (0,2,x)，并逐属性覆盖注入表设置的每个
             颜色。全表只有本段允许用 :root 前缀（其余保持特异度平坦，见 audit 断言）。
             app.js 若改动那段注入样式，本段需同步——audit 用注入字符串的 sha256 钉死。
             背景用 alpha 合规：两者都在 .section 的不透明 --surface 之内。 */
        :root .shared-progress { border-color: var(--border-color); background: var(--theme-soft); }
        :root .shared-progress > span { color: var(--secondary-text-color); }
        :root .shared-progress p { color: var(--secondary-text-color); }
        :root .shared-track { background: rgba(var(--text-color-rgb), .1); }
        /* 用 --theme-ink 而非 --theme-color：进度条是 1.4.11 的图形对象，#355cc2 对暗色轨道
           只有约 2.1:1；--theme-ink 暗色下切 #90a5dd 达标，亮色下两者同值，一条规则通吃明暗。 */
        :root .shared-track i { background: var(--theme-ink); }
        :root .shared-plan-note { display: block; margin: -8px 0 12px; color: var(--theme-ink); font-size: 12px; line-height: 1.5; }

        /* ── 12. 服务未开放页 ────────────────────────────────────────────────────── */
        /* margin-top 用 max(12vh, 84px)：矮视口下 12vh 会小于 fixed 切换按钮的占位（top 20 + 42），
           卡片右上角会被按钮压住。84px 与 .content-area 的顶部留白同源。 */
        .service-closed { display: grid; width: min(680px, calc(100% - 32px)); min-height: 260px; margin: max(12vh, 84px) auto 32px; padding: 42px 34px; place-items: center; border: 1px solid var(--border-color); border-radius: var(--radius-card); background: var(--surface); box-shadow: var(--shadow-modal); text-align: center; }
        .service-closed h1 { margin: 0 0 10px; font-size: 26px; font-weight: 700; }
        .service-closed p { max-width: 480px; color: var(--secondary-text-color); font-size: 14px; line-height: 1.8; }

        /* ── 13. 响应式（断点取 EZ 刻度 992 / 768 / 576 / 480）───────────────────── */
        @media (max-width: 992px) {
            /* 单列后 DOM 序即视觉序：主列（浏览套餐）在前，登录卡在后。
               ⚠ 必须把 align-items 从 flex-start 改回 stretch：横排时 flex-start 用于两列顶部
               对齐，但换成 column 方向后 align-items 作用于**横轴**，flex-start 会让两列收缩到
               内容宽（.main-column 会塌到约 336px，套餐网格退化成单列）。 */
            .layout { flex-direction: column; align-items: stretch; }
            .main-column, .side-column { flex: 0 0 auto; width: 100%; }
        }
        @media (max-width: 768px) {
            .content-area { padding: 78px 10px 2rem; }
            .container { max-width: 100%; padding: 0 10px; }
            .page-header { display: block; }
            .account-chip { justify-content: flex-start; margin-top: 15px; }
            .theme-toggle { top: 14px; right: 14px; }
        }
        @media (max-width: 576px) {
            .page-title { font-size: 26px; }
            .checkout-row { flex-direction: column; }
            .checkout-row button { width: 100%; }
        }
        @media (max-width: 480px) {
            /* 侧栏卡片内距收窄，给 reCAPTCHA 多让 12px */
            .auth-card { padding: 20px 14px; }
            /* reCAPTCHA widget 固定 304px，而这一档最宽也只能给到约 305px（375 设备）、
               360 设备实测只有 290px。所以整档都要平移逃生口，页面本身不横滚。
               ⚠ 不能用 overflow:hidden（旧表的做法）——那会让 widget 被静默裁掉且无法滚到。 */
            .captcha-wrap { overflow-x: auto; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { scroll-behavior: auto !important; transition: none !important; animation: none !important; }
        }
    </style>
</head>
@if (!$reseller_enabled)
<body>
<button id="theme-toggle" class="theme-toggle" type="button" aria-label="深色模式" aria-pressed="false">
    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
{{-- 刻意不加 role="status"：显式 role 会覆盖 <main> 的隐含 landmark 角色，页面就没有任何
     landmark 可供跳转，而这段是静态文案、不需要 live region 播报。 --}}
<main class="service-closed">
    <div>
        <h1>倒卖商服务暂未开放</h1>
        <p>当前暂未开放店铺销售和客户购买，请稍后再试。</p>
    </div>
</main>
</body>
@else
<body>
<button id="theme-toggle" class="theme-toggle" type="button" aria-label="深色模式" aria-pressed="false">
    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
<main class="content-area">
    <div class="container">
        <header class="page-header">
            <div>
                {{-- lang="en" 让中文语音合成不把英文小标题按拼音读（WCAG 3.1.2） --}}
                <p class="page-eyebrow" lang="en">Subscription Store</p>
                {{-- ⚠ #store-name / #store-description 被 app.js:321-322 用 textContent 覆写，
                     必须保持纯文本（textContent 的 setter 会清空全部子节点）。 --}}
                <h1 id="store-name" class="page-title">订阅店铺</h1>
                <p id="store-description" class="page-subtitle">正在加载店铺信息...</p>
            </div>
            <div class="account-chip">
                {{-- #account 同样是 textContent 覆写（app.js:116）；#logout 不在 setButtonLoading
                     名单里（app.js:643 直接绑 click），允许带图标。 --}}
                <span id="account">未登录</span>
                <button id="logout" class="secondary" type="button" hidden>退出登录</button>
            </div>
        </header>
        {{-- ⚠ 标签内不能有任何空白字符：#message:empty 的空态折叠依赖零子节点，
             而 app.js 的 show('') 只清 textContent。样式与取舍见样式表 §4。 --}}
        <div id="message" role="status" aria-live="polite"></div>
        <div class="layout">
            <div class="main-column">
                <section class="dashboard-card">
                    <div class="section-head"><div><h2>可售套餐</h2><p class="subtle">选择套餐周期后创建订单。</p></div></div>
                    {{-- ⚠ #plans 是 app.js:699 的委托监听容器（closest('[data-plan]')），id 必须在
                         注入按钮的祖先上；:338 会 innerHTML 重写它，所以不能预置任何子元素。 --}}
                    <div id="plans" class="plans"></div>
                </section>
                <section id="checkout-section" class="dashboard-card" hidden>
                    <div class="section-head"><div><h2>完成支付</h2><p class="subtle">订单号：<span id="trade-no"></span></p></div></div>
                    <div class="checkout-row">
                        {{-- #payment-method 由 app.js:604 注入 <option>；暗色下拉可读靠 §1.7 的 color-scheme。
                             #checkout 不受 setButtonLoading 管，可带图标。 --}}
                        <select id="payment-method" aria-label="支付方式"></select>
                        <button id="checkout" type="button">发起支付</button>
                    </div>
                </section>
                <section id="subscription-section" class="dashboard-card" hidden>
                    <div class="section-head"><div><h2>我的订阅</h2><p class="subtle">订阅开通后，可复制地址导入客户端。</p></div></div>
                    {{-- app.js:510 往这里注入 .subscription-summary（含 #subscribe-url 与
                         #copy-subscribe-url），blade 只留空容器。 --}}
                    <div id="subscription-summary"></div>
                </section>
                {{-- ⚠⚠ 本页最脆的锚点，两重契约：
                     ① installSharedPanel（app.js:371-372）找不到 #orders-section 就提前 return，
                        而 clearAuthentication（app.js:135）访问 #shared-section.hidden 时**没有
                        null 保护** → 缺了它，退出登录与 401 掉线处理直接 TypeError 全废；
                     ② 共享面板由 app.js:383 以 insertAdjacentElement('afterend') 插在本节点之后，
                        所以 blade **绝不能预声明 #shared-section**（否则 :370 提前 return，共享
                        面板的三个事件监听永不绑定，静默失效）。 --}}
                <section id="orders-section" class="dashboard-card" hidden>
                    <div class="section-head"><div><h2>我的订单</h2><p class="subtle">你在当前店铺的订单记录。</p></div></div>
                    <div id="orders"></div>
                </section>
                {{-- ↑ #shared-section（class="section"，靠 §8 的合写选择器吃到卡片基型）注入落点 --}}
            </div>
            <aside class="side-column">
                <section id="auth-section" class="dashboard-card auth-card">
                    <div class="auth-head">
                        {{-- ⚠ #auth-title / #auth-caption 被 app.js:156-157（2FA 态）与 :304-307
                             （切 tab）用 textContent 覆写，必须纯文本。 --}}
                        <h2 id="auth-title">登录账户</h2>
                        <p id="auth-caption" class="subtle">登录后即可购买套餐并查看订单。</p>
                    </div>
                    {{-- ⚠ data-auth-mode 与 is-active 原名保留（app.js:308-311 用 classList.toggle，
                         保留既有类，所以可以另加 .auth-tab）。.slider-indicator 必须排在两个
                         button 之后：纯 CSS 滑块靠 ~ 兄弟选择器读 .is-active。 --}}
                    <div class="auth-tabs" role="tablist" aria-label="账户操作">
                        <button class="auth-tab is-active" type="button" data-auth-mode="login" role="tab" aria-selected="true">登录</button>
                        <button class="auth-tab" type="button" data-auth-mode="register" role="tab" aria-selected="false">注册</button>
                        <span class="slider-indicator" aria-hidden="true"></span>
                    </div>
                    {{-- ⚠ 以下三个 form 各自恰好 1 个 button[type=submit] 且在 form 内
                         （app.js:616/655/686 用 event.target.querySelector 找它做 loading 态），
                         且必须纯文本（setButtonLoading 用 textContent 读写，会清空子节点）。 --}}
                    <form id="login-form" class="auth-form">
                        <label>邮箱<input name="email" type="email" placeholder="name@example.com" autocomplete="email" required></label>
                        <label>密码<input name="password" type="password" minlength="8" placeholder="至少 8 位" autocomplete="current-password" required></label>
                        <div class="form-actions"><button type="submit">登录</button></div>
                    </form>
                    <form id="register-form" class="auth-form" hidden>
                        <label>邮箱<input name="email" type="email" placeholder="name@example.com" autocomplete="email" required></label>
                        <label>设置密码<input name="password" type="password" minlength="8" placeholder="至少 8 位" autocomplete="new-password" required></label>
                        <label>确认密码<input name="password_confirmation" type="password" minlength="8" placeholder="再次输入密码" autocomplete="new-password" required></label>
                        <label id="invite-field">邀请码
                            {{-- required 由 app.js:285 经 form.elements.invite_code 动态托管；
                                 #invite-note 是 textContent 覆写目标（app.js:286）。 --}}
                            <input name="invite_code" placeholder="选填" autocomplete="off">
                            <span id="invite-note" class="field-note">没有邀请码可留空。</span>
                        </label>
                        <div id="arithmetic-field" class="security-field" hidden>
                            <strong>算术验证</strong>
                            <div id="arithmetic-expression" class="security-expression"></div>
                            <div class="inline-row">
                                {{-- aria-describedby 指向题面：app.js:245 只写它的 textContent，所以换题后
                                     描述自动更新，零 JS 改动。没有它读屏用户听不到要算的式子。 --}}
                                <input id="arithmetic-answer" inputmode="numeric" autocomplete="off" placeholder="输入答案"
                                       aria-label="算术验证答案" aria-describedby="arithmetic-expression">
                                {{-- #verify-arithmetic 受 setButtonLoading 管（app.js:262）→ 纯文本；
                                     #refresh-arithmetic 不受管，可带图标。
                                     后者刻意不加 aria-label：可见文字「换题」已足够，而 aria-label 若不
                                     包含可见文字会让语音控制用户念「换题」点不动它（WCAG 2.5.3）。 --}}
                                <button id="verify-arithmetic" class="secondary" type="button">验证</button>
                                <button id="refresh-arithmetic" class="secondary" type="button">换题</button>
                            </div>
                            {{-- ⚠ class 必须恰为 security-status：app.js:232 整体覆写 className --}}
                            <p id="arithmetic-status" class="security-status" aria-live="polite"></p>
                        </div>
                        {{-- ⚠ grecaptcha.render 的宿主（app.js:218），整个生命周期只 render 一次：
                             不能重建、不能移动、不能被会 innerHTML 重写的容器包裹。宽度见样式表 §9。 --}}
                        <div id="recaptcha-field" class="captcha-wrap" hidden></div>
                        <div class="form-actions"><button type="submit">注册并登录</button></div>
                    </form>
                    <form id="two-factor-form" class="auth-form" hidden>
                        <p class="two-factor-help">该账号已启用两步验证。请输入验证器代码，或使用恢复码继续登录。</p>
                        {{-- 两个 input 刻意都不带 required：二者其一即可，由 app.js:682 手工校验 --}}
                        <label>验证器代码<input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="000000"></label>
                        <label>恢复码<input name="recovery_code" autocomplete="off" placeholder="XXXX-XXXX-XXXX"></label>
                        <div class="form-actions">
                            <button type="submit">验证并登录</button>
                            <button id="back-to-login" class="secondary" type="button">返回登录</button>
                        </div>
                    </form>
                </section>
            </aside>
        </div>
    </div>
</main>
{{--
    ═══ JS 耦合契约（改本文件前必读；权威来源是 public/storefront/app.js）═══

    app.js 用 innerHTML / createElement 注入下列类名，样式必须继续存在且明暗两态可读：
      section · section-head · subtle · auth-form · form-actions · secondary · text-button
      plan · plan-actions · empty · order-row · subscription-summary · subscription-meta
      subscription-url · field-note · security-status + is-good / is-error · is-active
      shared-progress · shared-track · shared-plan-note
    以及这些标签选择器：.plan h3 · .plan p · .order-row span（后代！内含注入按钮）
      .order-row strong · .subscription-summary strong|p · select > option

    结构依赖（违反后多数静默失效，不报错）：
      · #orders-section 必须存在且 id 不改        → app.js:371-372（+ :135 无 null 保护）
      · blade 绝不能预声明 #shared-section        → app.js:370 会提前 return，三个监听不绑定
      · 套餐卡 h3 + p(第一个p) + .plan-actions    → app.js:358-364 徽章插位与索引对齐
      · 模块加载时缓存的 11 个 id 必须先于脚本存在 → app.js:19-29
        message auth-section account logout login-form register-form two-factor-form
        auth-title auth-caption arithmetic-answer arithmetic-status
      · #message 无 hidden、无类切换依赖、标签内零空白 → app.js:31-34 只写 textContent + 内联色
      · 三个 form 各恰好 1 个 form 内 button[type=submit] → app.js:616/655/686
      · 受 setButtonLoading 管的按钮必须纯文本（textContent 会清子节点）→ app.js:97-107
        名单：3 个 submit、#verify-arithmetic（注入侧还有共享邀请 submit / 轮换 / 移除）
        不受管、可带图标：#checkout #logout #copy-subscribe-url #refresh-arithmetic #back-to-login
      · textContent 覆写名单保持纯文本：#auth-title #auth-caption #account #store-name
        #store-description #trade-no #arithmetic-expression #arithmetic-status #invite-note
      · #arithmetic-status 的 class 恰为 security-status → app.js:232 整体覆写 className
      · #recaptcha-field 恰 1 次、是 #register-form 直接子级、只 render 一次 → app.js:218
      · 委托容器 id：#plans（data-plan）、#shared-members（注入侧，data-remove-shared-member）
      · 全部 name 属性不变：login(email,password) register(email,password,
        password_confirmation,invite_code) two-factor(code,recovery_code)
      · [hidden]{display:none!important} 与 .order-row span 的后代选择器不能动
      · **不得引入任何 href="#..." 或 hash 路由** → app.js:734 的 hashchange 是支付返回
        通道（#/order/<tradeNo>），任何 hash 变更都会跑一遍
      · window.STORE_SLUG 的 script 必须先于 app.js（app.js:6 用它组 sessionStorage 键）
      · <script src=app.js> 只能在 @else 分支输出：app.js 没有自我保护（不像 reseller 读
        body 的 data 属性），关服态缺锚点会直接 TypeError

    页面级明暗机制（与 app.js 无关）：
      · <head> 内联脚本盖章 data-theme + 委托 #theme-toggle + localStorage('storefront_theme')
      · 暗色令牌只挂 :root[data-theme="dark"]，全表不得出现裸 prefers-color-scheme 色彩规则
        （prefers-reduced-motion 不在此列）
      · 样式表 §11 是唯一允许用 :root 前缀提权的一段（对抗 app.js:373-375 的注入样式）
--}}
<script>window.STORE_SLUG = @json($slug);</script>
@php
    $storefrontAssetPath = public_path('storefront/app.js');
    $storefrontAssetVersion = is_file($storefrontAssetPath)
        ? filemtime($storefrontAssetPath) . '-' . substr(hash_file('sha256', $storefrontAssetPath), 0, 12)
        : config('app.version');
@endphp
<script src="/storefront/app.js?v={{ $storefrontAssetVersion }}"></script>
</body>
@endif
</html>
