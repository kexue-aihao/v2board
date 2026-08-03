<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#355cc2">
    <title>{{ $title }}</title>
    <script>
        /* 明暗盖章必须先于首帧：按「localStorage 显式选择 → 系统偏好」把 data-theme 写到
           根元素上，暗色令牌全部挂在 :root[data-theme="dark"] 下（见样式表 §2）。右上角
           #theme-toggle 的点击也在这里用事件委托处理——刻意不进 app.js：那份文件保持零改动
           （缓存指纹只跟它的 mtime 变，HTML 与本内联脚本同文件、天然同批到达，无错配窗口）。 */
        (function () {
            'use strict';
            var KEY = 'reseller_theme';
            var CHROME = { dark: '#171A1D', light: '#355cc2' };   // 移动端浏览器 chrome：暗色跟页底，亮色保持品牌色（与 EZ dashboard.blade.php 一致）
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
            }
            apply(stored() || (media && media.matches ? 'dark' : 'light'));
            function followSystem(event) {
                if (!manual && !stored()) apply(event.matches ? 'dark' : 'light');
            }
            if (media && media.addEventListener) media.addEventListener('change', followSystem);
            else if (media && media.addListener) media.addListener(followSystem);
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
           倒卖商工作区样式表 —— 采用 EZ 主题的设计语言（令牌值 / split 分栏 / 卡片基型）。
           本页无构建步骤，CSS 必须内联；配套 JS 是 public/assets/reseller/app.js（原生 ES5）。

           ⚠ 修改前先读文件末尾的「JS 耦合契约」注释块。app.js 会用 innerHTML 注入带类名的
             HTML，并依赖若干结构关系；改类名或改嵌套会让功能静默失效。
           ═══════════════════════════════════════════════════════════════════════════ */

        /* ── 1. 令牌 · 亮色 ─────────────────────────────────────────────────────── */
        :root {
            /* 1.1 EZ 基础令牌：逐字取自 theme-src/ez/src/assets/styles/base/variables.scss:6-37。勿改数值。 */
            --background-color: #f5f7fa;
            /* --card-background 本页无消费点，保留声明纯为 EZ 片段可移植性（移过来的 EZ 代码用
               var(--card-background) 应得到 EZ 的原本行为，含暗色下的半透明）。本页自己的毛玻璃
               走 --card-background-rgb + .7，不透明兜底走 --surface。 */
            --card-background: #ffffff;
            --card-background-rgb: 255, 255, 255;           /* 毛玻璃配方：.site-logo / .auth-tabs / .slide-tabs-wrapper */
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
            --theme-ink: #355cc2;                           /* 可作文字的主色（链接 / eyebrow / 数字）。暗色下必须换，见 §2 */
            --theme-hover: #2d4ea5;                         /* 有意偏离 EZ：EZ 派生的 rgba(theme,.9) 叠白底得 #486ac8，反而变亮 */
            --theme-soft: rgba(var(--theme-color-rgb), .08);
            --theme-focus: rgba(var(--theme-color-rgb), .25);

            /* 1.3 面元阶梯。规则：暗色下面元一律不透明 hex；alpha 只允许出现在边框 / 阴影 / 焦点环 /
                   已知父级上的叠加层。EZ 的 --card-background: rgba(30,30,30,.8) 在本页会失控——
                   .toast 是 position:fixed，半透明底 + 13px 文字直接不可读；密码块更是四层嵌套。 */
            --surface: #ffffff;
            --control-bg: #ffffff;
            --control-bg-hover: rgba(var(--text-color-rgb), .05);   /* 明暗通用（依赖 --text-color-rgb 反转） */
            --control-border: rgba(var(--text-color-rgb), .18);     /* 明暗通用 */

            /* 1.4 语义 ink 层。EZ 自己的 *-color on *-background 只有 2.41–3.25:1（文字不合规），
                   所以色底上的文字一律走 -ink 层保 4.5:1。
                   注意连「图形角色」也走 -ink：--success-color #00B42A 在白面板上只有 2.78:1，
                   同样够不上 1.4.11 的 3:1，所以状态圆点填充用的是 --success-ink（亮 5.9 / 暗 6.0）。
                   --success-color / --warning-color / --error-color 因此在本页没有消费点，
                   保留声明纯粹是为了 EZ 片段的可移植性（四组功能色是成套 API）。 */
            --success-ink: #007a1c;  --success-line: rgba(var(--success-color-rgb), .30);   /* on #E8FFEA = 5.24:1 */
            --warning-ink: #9a5b00;  --warning-line: rgba(var(--warning-color-rgb), .30);   /* on #FFF7E8 = 5.09:1 */
            --error-ink:   #c42b2b;  --error-line:   rgba(var(--error-color-rgb),   .30);   /* on #FFECE8 = 4.95:1 */
            --neutral-ink: var(--secondary-text-color);
            --neutral-background: rgba(var(--text-color-rgb), .07);  /* 明暗通用 */
            --status-dot-off: rgba(var(--text-color-rgb), .55);      /* 明暗通用；.55 才够 3:1（.35 亮色下只有 1.98:1） */

            /* 1.5 阴影（整条 box-shadow 值，不是颜色） */
            --shadow-card:  0 2px 10px rgba(0, 0, 0, .05);           /* = EZ .dashboard-card */
            --shadow-float: 0 4px 15px rgba(0, 0, 0, .10);           /* = EZ card:hover / 毛玻璃 */
            --shadow-modal: 0 22px 60px var(--shadow-color);

            /* 1.6 几何 / 运动 / 层级 */
            --radius-sm: 8px;                               /* EZ 控件圆角（吸收旧表全部 5px 和 6px） */
            --radius-card: 12px;                            /* EZ .dashboard-card */
            --radius-pill: 999px;
            --control-h: 45px;                              /* EZ 控件高度 */
            --control-h-sm: 32px;                           /* 密集行内的小按钮，EZ 无此档 */
            --ease: .3s ease;
            --ease-fast: .2s ease;
            --z-shell: 1; --z-nav: 10; --z-brand: 110; --z-toast: 120;
            --disabled-opacity: .58;

            /* 1.7 让 <option> 下拉、number 步进器、复选框和原生 confirm/prompt 跟随明暗。
                   这是 CSS 规则无法替代的一项：缺了它，暗色下浅色文字会落在 OS 渲染的白底选项列表上。 */
            color-scheme: light;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                "Helvetica Neue", "PingFang SC", "Microsoft YaHei", "Noto Sans CJK SC", Arial, sans-serif;
        }

        /* ── 2. 令牌 · 暗色 ─────────────────────────────────────────────────────────
           挂在 :root[data-theme="dark"] 上而非裸 media query：<head> 顶部的内联脚本在首帧
           渲染前按「localStorage('reseller_theme') 显式选择 → 系统偏好」次序给 <html> 盖章，
           右上角 #theme-toggle 负责切换并持久化；未显式选择时监听系统偏好变化实时跟随。
           整页只有这一套明暗机制——EZ 是 body.dark-theme 与组件内裸 prefers-color-scheme
           双轨打架（手动选亮色时部分组件仍跟系统变暗），这里的暗色规则只认属性不认系统，
           不存在那个问题。不给无 JS 场景留 media 兜底是刻意的：本页离开 JS 本就不可用
           （app.js 渲染一切）。 */
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
        /* 无毛玻璃时的兜底走 --surface（明暗都不透明），刻意不用 --card-background：这 4 个都是
           position:fixed 的悬浮件，滚动内容从它们下方穿过——不属于 §1.3 里「已知父级上的叠加层」
           那种可以用 alpha 的情形，而 --card-background 在暗色是 EZ 原值 rgba(30,30,30,.8)，
           20% 的透出会让 13-14px 导航文字出现鬼影。 */
        @supports not ((backdrop-filter: blur(4px)) or (-webkit-backdrop-filter: blur(4px))) {
            .site-logo, .auth-tabs, .slide-tabs-wrapper, .theme-toggle { background: var(--surface); }
        }

        /* ── 3. reset 与元素基线 ───────────────────────────────────────────────── */
        * { box-sizing: border-box; }
        /* ⚠ 勿删 !important：app.js 靠 element.hidden 做 6 处互斥显示，而这些元素的 display 是
             flex/grid/inline-flex，会盖掉 UA 的 [hidden]{display:none}。
             涉及 #auth-shell / #workspace / .form-stack / #approval-banner /
             #payment-cancel-edit / #shared-groups / #shared-group-members-N。 */
        [hidden] { display: none !important; }
        html { background: var(--background-color); }
        body { min-width: 320px; margin: 0; color: var(--text-color); background: var(--background-color); line-height: 1.5; -webkit-font-smoothing: antialiased; }
        button, input, select, textarea { font: inherit; }   /* ⚠ 字体栈进入表单控件的唯一通路 */
        button { cursor: pointer; }
        /* UA 的 2px groove 边框 + min-inline-size:min-content 会把 7 列价格网格顶出 flex 列 */
        fieldset { min-width: 0; margin: 0; padding: 0; border: 0; }
        a { color: var(--theme-ink); }
        /* 同时覆盖两种禁用路径：fieldset[data-sale-control] 整体禁用（app.js:196）与
           直接禁用 <select>（app.js:239/373，不经 fieldset，旧表这一路完全没有视觉反馈）。 */
        button:disabled, input:disabled, select:disabled, textarea:disabled { cursor: not-allowed; opacity: var(--disabled-opacity); }

        /* ── 4. 全局提示 · .toast（app.js:22 整体重写 className，基类与修饰类须各自独立成立）── */
        .toast { position: fixed; z-index: var(--z-toast); top: 20px; right: 20px; max-width: min(420px, calc(100vw - 40px)); padding: 12px 15px; border: 1px solid; border-radius: var(--radius-sm); box-shadow: var(--shadow-float); font-size: 13px; line-height: 1.6; }
        .toast-success { color: var(--success-ink); border-color: var(--success-line); background: var(--success-background); }
        .toast-error { color: var(--error-ink); border-color: var(--error-line); background: var(--error-background); }

        /* 明暗切换按钮（页面级 chrome，常驻两个视图之外）。点击由 <head> 内联脚本委托处理，
           app.js 对它零感知；不在 setButtonLoading 名单里，所以允许包含 SVG 子节点。
           z 用 --z-brand：被 toast(120) 短暂压住可接受（toast 会自动消失）。 */
        .theme-toggle { position: fixed; z-index: var(--z-brand); top: 20px; right: 20px; display: grid; width: 42px; height: 42px; place-items: center; padding: 0; border: 1px solid var(--border-color); border-radius: 50%; color: var(--secondary-text-color); background: rgba(var(--card-background-rgb), .7); box-shadow: var(--shadow-float); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); transition: color var(--ease-fast), border-color var(--ease-fast); }
        .theme-toggle:hover { color: var(--theme-ink); border-color: var(--theme-color); }
        .theme-toggle svg { width: 20px; height: 20px; }
        /* 亮色态显示月亮（点击进入暗色），暗色态显示太阳 */
        .theme-toggle .icon-sun { display: none; }
        :root[data-theme="dark"] .theme-toggle .icon-sun { display: block; }
        :root[data-theme="dark"] .theme-toggle .icon-moon { display: none; }

        /* ── 5. 登录壳 · EZ split 分栏 ─────────────────────────────────────────── */
        /* 用 fixed+inset 而非 EZ 的 html,body{height:100%} 链：本页三个视图（服务未开放页 /
           登录壳 / 工作台）互斥显示，引入高度链会让工作台的长文档滚动变脆（尤其 iOS 100vh）。
           fixed 元素被 [hidden] 隐藏后不占位、不遮挡，与另两个普通块级视图零冲突。 */
        .auth-split-container { position: fixed; z-index: var(--z-shell); inset: 0; display: flex; overflow: hidden; }
        .auth-split-left { position: relative; display: flex; flex: 1; min-width: 500px; align-items: center; justify-content: center; padding: 96px 48px; background: var(--theme-color); }
        .left-content-overlay { position: absolute; z-index: 1; inset: 0; background: rgba(0, 0, 0, .2); }
        /* 主题色叠 20% 黑遮罩后合成 rgb(42,74,155)，下面的配色都按这个底算的对比度 */
        .white { color: #fff; text-shadow: 0 2px 4px rgba(0, 0, 0, .3); }
        .site-name { position: absolute; z-index: 2; top: 30px; left: 30px; display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 700; }
        .site-mark { display: grid; width: 34px; height: 34px; place-items: center; border-radius: var(--radius-sm); color: var(--theme-color); background: #fff; font-size: 15px; font-weight: 800; text-shadow: none; }
        .greeting-text { position: absolute; z-index: 2; bottom: 30px; left: 30px; font-size: 1.25rem; font-weight: 600; }
        .left-content { position: relative; z-index: 2; width: 100%; max-width: 420px; }
        .left-title { margin: 0 0 14px; color: #fff; font-size: clamp(28px, 3.4vw, 38px); font-weight: 700; line-height: 1.2; text-shadow: 0 2px 4px rgba(0, 0, 0, .3); }
        .left-subtitle { margin: 0; color: rgba(255, 255, 255, .88); font-size: 14px; line-height: 1.8; text-shadow: 0 2px 4px rgba(0, 0, 0, .3); }
        .left-steps { display: grid; gap: 10px; margin: 32px 0 0; padding: 24px 0 0; border-top: 1px solid rgba(255, 255, 255, .2); list-style: none; }
        .left-step { display: flex; gap: 10px; align-items: center; padding: 10px 12px; border: 1px solid rgba(255, 255, 255, .18); border-radius: var(--radius-card); background: rgba(255, 255, 255, .1); color: rgba(255, 255, 255, .92); font-size: 13px; line-height: 1.5; backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
        /* 徽章刻意不用 EZ 的毛玻璃配方：那样主题色数字只有 3.6:1，且暗色下 --card-background-rgb
           翻成 30,30,30 而左栏底色仍是主题色（EZ 的 leftSideStyles 与明暗无关），玻璃片会反转成深色。
           实心白底 + 主题色数字（6.0:1）在明暗两态都成立。 */
        .step-badge { display: grid; flex: 0 0 22px; width: 22px; height: 22px; place-items: center; border-radius: 50%; color: var(--theme-color); background: #fff; box-shadow: 0 2px 6px rgba(0, 0, 0, .25); font-size: 12px; font-weight: 800; }
        .auth-split-right { display: flex; flex: .8; min-width: 320px; max-width: 520px; flex-direction: column; justify-content: center; overflow-y: auto; background: var(--background-color); }
        /* margin:auto 而非 EZ 的 margin:0 auto —— auth margin 优先于 justify-content 吸收剩余空间，
           空间不足时归零、内容从顶部开始。EZ 桌面端只写了水平居中，内容超高时会从顶部裁掉且滚不到。 */
        .auth-form-container { width: 100%; max-width: 420px; margin: auto; padding: 40px; }
        .auth-header { margin-bottom: 2rem; text-align: center; }
        .auth-title { margin: 0 0 .5rem; color: var(--text-color); font-size: 1.75rem; font-weight: 700; line-height: 1.3; }
        .auth-subtitle { margin: 0; color: var(--secondary-text-color); font-size: 1rem; line-height: 1.6; }

        /* 登录 / 申请入驻切换：EZ 胶囊 + 滑块的观感，但滑块是纯 CSS 的。
           只有 2 个等宽 tab，滑块位置是常量，不需要 EZ SlideTabsNav 那套 offsetLeft/offsetWidth 量算，
           因此零 JS 改动——app.js:396 已经在切 .is-active，兄弟选择器直接吃这个状态。
           几何：容器左右各 5px padding，1fr 1fr 每列 = 50% - 5px，与滑块宽精确相等；
                 translateX(100%) 后左边缘落在 50%，右边缘落在 100% - 5px。 */
        .auth-tabs { position: relative; display: grid; grid-template-columns: 1fr 1fr; margin-bottom: 1.5rem; padding: 5px; border: 1px solid var(--border-color); border-radius: 30px; background: rgba(var(--card-background-rgb), .7); box-shadow: var(--shadow-float); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
        .auth-tab { position: relative; z-index: 2; min-height: 38px; padding: 6px 16px; border: 0; border-radius: 26px; color: var(--secondary-text-color); background: transparent; font-size: 14px; font-weight: 500; transition: color var(--ease); }
        .auth-tab:hover, .auth-tab.is-active { color: var(--text-color); }
        .slider-indicator { position: absolute; z-index: 1; top: 5px; bottom: 5px; left: 5px; width: calc(50% - 5px); border: 1px solid var(--theme-color); border-radius: 26px; background: rgba(var(--theme-color-rgb), .1); box-shadow: 0 4px 15px rgba(var(--theme-color-rgb), .1); pointer-events: none; transition: transform .6s cubic-bezier(.25, .1, .25, 1); }
        .auth-tab[data-auth-tab="register"].is-active ~ .slider-indicator { transform: translateX(100%); }

        .auth-footnote { margin: 8px 0 0; color: var(--secondary-text-color); font-size: 12px; line-height: 1.7; }
        .registration-credentials { margin: 0 0 20px; padding: 16px; border: 1px solid var(--success-line); border-radius: var(--radius-sm); color: var(--success-ink); background: var(--success-background); }
        .registration-credentials strong, .registration-credentials small { display: block; }
        .registration-credentials strong { margin-bottom: 4px; font-size: 13px; }
        .registration-credentials small { margin-bottom: 10px; font-size: 12px; line-height: 1.6; opacity: .9; }
        .registration-password { display: block; padding: 10px; border: 1px solid var(--success-line); border-radius: var(--radius-sm); color: var(--text-color); background: var(--control-bg); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-size: 12px; line-height: 1.7; word-break: break-all; user-select: all; }
        .registration-credentials .btn { margin-top: 10px; }

        /* ── 6. 表单控件（EZ .form-control 配方）───────────────────────────────── */
        .form-stack { display: grid; gap: 15px; }
        .field { display: grid; gap: 6px; color: var(--text-color); font-size: 13px; font-weight: 600; }
        .field input, .field select, .field textarea { width: 100%; height: var(--control-h); padding: 0 12px; border: 1px solid var(--control-border); border-radius: var(--radius-sm); outline: 0; color: var(--text-color); background: var(--control-bg); font-weight: 400; transition: border-color var(--ease), background-color var(--ease), box-shadow var(--ease); }
        .field textarea { height: auto; min-height: 96px; padding: 12px; line-height: 1.6; resize: vertical; }
        .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--theme-color); box-shadow: 0 0 0 2px var(--theme-focus); }
        .field input::placeholder, .field textarea::placeholder { color: var(--secondary-text-color); }
        .field-help { color: var(--secondary-text-color); font-size: 12px; font-weight: 400; line-height: 1.6; }
        .input-with-icon { position: relative; }
        .input-with-icon .input-icon { position: absolute; top: 50%; left: 12px; width: 20px; height: 20px; color: var(--secondary-text-color); pointer-events: none; transform: translateY(-50%); }
        .field .input-with-icon input { padding-left: 40px; }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .two-col-prices { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
        .payment-fields { display: grid; gap: 15px; }
        .payment-fields .empty-state { padding: 12px; border: 1px dashed var(--control-border); border-radius: var(--radius-sm); }
        /* .payment-field —— app.js:282 注入 class="field payment-field" 的预留钩子。本表刻意不给它
           独立规则（视觉完全走 .field）。它在 CSS 里搜不到不是遗漏，勿删 JS 侧那行。 */
        .field-check { display: flex; align-items: center; gap: 8px; }
        .field-check input { flex: 0 0 18px; width: 18px; height: 18px; margin: 0; padding: 0; accent-color: var(--theme-color); }

        .btn { display: inline-flex; height: var(--control-h); align-items: center; justify-content: center; gap: 7px; padding: 0 18px; border: 1px solid transparent; border-radius: var(--radius-sm); font-size: 14px; font-weight: 600; white-space: nowrap; transition: color var(--ease), border-color var(--ease), background-color var(--ease); }
        .btn-primary { color: var(--theme-on); background: var(--theme-color); }
        .btn-primary:hover:not(:disabled) { background: var(--theme-hover); }
        .btn-quiet { color: var(--text-color); border-color: var(--control-border); background: var(--control-bg); }
        .btn-quiet:hover:not(:disabled) { color: var(--theme-ink); border-color: var(--theme-color); background: var(--control-bg-hover); }
        .btn-block { width: 100%; }
        .form-actions { display: flex; align-items: center; gap: 9px; flex-wrap: wrap; }
        .form-actions .hint { color: var(--secondary-text-color); font-size: 12px; font-weight: 400; }

        /* ── 7. 工作台外壳 ─────────────────────────────────────────────────────── */
        /* ⚠ .site-logo 与 .slide-tabs-container 必须留在 #workspace 内部：fixed 元素在
             display:none 的祖先下不渲染，放在里面才能随登出态一起消失。
           ⚠ 不要给 #workspace 加 transform / filter / backdrop-filter，否则它会成为这两个
             fixed 子元素的包含块，导航会跟着文档滚。 */
        .site-logo { position: fixed; z-index: var(--z-brand); top: 20px; left: 20px; display: flex; align-items: center; gap: 10px; padding: 6px 16px 6px 6px; border: 1px solid var(--border-color); border-radius: var(--radius-pill); color: var(--text-color); background: rgba(var(--card-background-rgb), .7); box-shadow: var(--shadow-float); font-size: 14px; font-weight: 700; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
        /* 玻璃片上白底徽章读不出来，这里反转成主题色底 + 白字（同样 6.07:1） */
        .site-logo .site-mark { width: 28px; height: 28px; color: var(--theme-on); background: var(--theme-color); font-size: 13px; }
        .slide-tabs-container { position: fixed; z-index: var(--z-nav); top: 20px; left: 50%; transform: translateX(-50%); }
        .slide-tabs-wrapper { display: inline-block; overflow: hidden; padding: 5px; border: 1px solid var(--border-color); border-radius: 30px; background: rgba(var(--card-background-rgb), .7); box-shadow: var(--shadow-float); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
        .slide-tabs-nav { display: flex; gap: 2px; }
        .nav-item { display: flex; height: 34px; align-items: center; justify-content: center; padding: 0 16px; border: 0; border-radius: 26px; color: var(--secondary-text-color); background: transparent; font-size: 14px; font-weight: 500; white-space: nowrap; text-decoration: none; transition: color var(--ease-fast), background-color var(--ease-fast); }
        .nav-item:hover:not(:disabled) { color: var(--text-color); background: var(--theme-soft); }
        .content-area { padding: 80px 1rem 2rem; }
        .container { max-width: 1200px; margin: 0 auto; padding: 0 15px; }
        .page-header { margin-bottom: 20px; }
        .page-eyebrow { margin: 0 0 6px; color: var(--theme-ink); font-size: 11px; font-weight: 700; letter-spacing: .12em; }
        .page-title { margin: 0; font-size: 25px; font-weight: 700; }
        .page-subtitle { margin: 6px 0 0; color: var(--secondary-text-color); font-size: 13px; }

        /* ── 8. 面元 · EZ 卡片基型（全表只定义一次；EZ 是散落 15 个视图各自复制一份）── */
        .dashboard-card { margin-bottom: 24px; padding: 20px; border: 1px solid var(--border-color); border-radius: var(--radius-card); background: var(--surface); box-shadow: var(--shadow-card); transition: border-color var(--ease), box-shadow var(--ease); }
        /* 暗色下阴影几乎不可见，层次改由 border-color 承担（EZ 卡片 hover 的做法） */
        .dashboard-card:hover, .stats-card:hover { border-color: rgba(var(--theme-color-rgb), .3); box-shadow: var(--shadow-float); }
        .card-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
        .card-title { margin: 0; font-size: 18px; font-weight: 600; }
        .card-desc { margin: 0 0 15px; color: var(--secondary-text-color); font-size: 12px; line-height: 1.7; }
        .content-wrapper { display: flex; align-items: flex-start; gap: 25px; }
        .left-column { flex: 1.15; min-width: 0; }
        .right-column { flex: .85; min-width: 0; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 20px; margin-bottom: 24px; }
        .stats-card { padding: 20px; border: 1px solid var(--border-color); border-radius: var(--radius-card); background: var(--surface); box-shadow: var(--shadow-card); transition: border-color var(--ease), box-shadow var(--ease); }
        .stats-label { margin-bottom: 8px; color: var(--secondary-text-color); font-size: 12px; }
        .stats-value { font-size: 15px; font-weight: 600; word-break: break-all; }
        .stats-meta { margin-top: 8px; color: var(--secondary-text-color); font-size: 12px; word-break: break-all; }
        .stats-note { color: var(--secondary-text-color); font-size: 12px; line-height: 1.7; }

        /* ── 9. 语义反馈（app.js 注入）──────────────────────────────────────────── */
        /* .approval-banner 基类只有 border 宽度与样式、没有颜色；颜色全靠 -warning/-ready 修饰类 */
        .approval-banner { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; padding: 14px 16px; border: 1px solid; border-radius: var(--radius-card); }
        .approval-banner strong, .approval-banner span { display: block; }
        .approval-banner strong { margin-bottom: 3px; font-size: 13px; }
        .approval-banner span { font-size: 12px; line-height: 1.6; }
        .approval-banner-warning { color: var(--warning-ink); border-color: var(--warning-line); background: var(--warning-background); }
        .approval-banner-ready { color: var(--success-ink); border-color: var(--success-line); background: var(--success-background); }
        .status-pill { display: inline-flex; min-height: 24px; align-items: center; padding: 0 10px; border-radius: var(--radius-pill); font-size: 12px; font-weight: 600; }
        .status-active { color: var(--success-ink); background: var(--success-background); }
        .status-pending { color: var(--warning-ink); background: var(--warning-background); }
        .status-rejected { color: var(--error-ink); background: var(--error-background); }
        /* statusClass() 对未知状态回落到 status-neutral，所以它必须有完整样式而不只是兜底名 */
        .status-suspended, .status-neutral { color: var(--neutral-ink); background: var(--neutral-background); }
        /* 文字色与 :before 圆点填充是两种角色，但都得达标：圆点编码启停状态（1.4.11 要 3:1），
           所以填充用 -ink 而不是 EZ 原值——#00B42A 在白面板上只有 2.78:1。 */
        .status-dot { color: var(--secondary-text-color); font-size: 12px; }
        .status-dot:before { display: inline-block; width: 7px; height: 7px; margin-right: 5px; border-radius: 50%; background: var(--status-dot-off); content: ''; }
        .status-dot.is-on { color: var(--success-ink); }
        .status-dot.is-on:before { background: var(--success-ink); }

        /* ── 10. 内容零件（app.js 注入）─────────────────────────────────────────── */
        .data-list, .template-list { display: grid; gap: 8px; }
        .template-option { display: flex; align-items: center; justify-content: space-between; gap: 10px; width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: var(--text-color); background: var(--control-bg); font-size: 13px; font-weight: 600; text-align: left; transition: border-color var(--ease-fast), background-color var(--ease-fast); }
        .template-option:hover { border-color: var(--theme-color); background: var(--theme-soft); }
        /* ⚠ 必须保持后代选择器（不能改成 >）：app.js:605 往 #shared-group-members-N 注入的裸
             <span> 全靠这条继承拿到样式，改成直接子代会让整块成员列表静默失去样式。 */
        .template-option span, .list-row span { display: block; margin-top: 3px; color: var(--secondary-text-color); font-size: 12px; font-weight: 400; }
        .list-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 13px; }
        /* <em> 里除了文字还会被塞进按钮（app.js:587/604），所以是 flex 不是纯右对齐文本 */
        .list-row em { display: flex; align-items: center; justify-content: flex-end; gap: 7px; flex-wrap: wrap; color: var(--secondary-text-color); font-size: 12px; font-style: normal; text-align: right; }
        .payment-row-actions { display: flex; align-items: center; justify-content: flex-end; gap: 7px; flex-wrap: wrap; }
        /* height 而非 min-height：min-height 压不住 .btn 的 height:45px */
        .payment-row-actions .btn, .list-row em .btn, .list-row span .btn { height: var(--control-h-sm); padding: 0 12px; font-size: 13px; }
        .empty-state { padding: 22px 10px; color: var(--secondary-text-color); font-size: 12px; line-height: 1.7; text-align: center; }
        .audit-stat { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 13px; }
        .audit-stat span { color: var(--theme-ink); font-weight: 700; }
        /* 顶部固定胶囊会遮住 app.js:379 的 scrollIntoView 目标 */
        #payment-form { scroll-margin-top: 110px; }

        /* ── 11. 服务未开放页 ──────────────────────────────────────────────────── */
        .service-closed { display: grid; width: min(680px, calc(100% - 32px)); min-height: 260px; margin: 12vh auto 32px; padding: 42px 34px; place-items: center; border: 1px solid var(--border-color); border-radius: var(--radius-card); background: var(--surface); box-shadow: var(--shadow-modal); text-align: center; }
        .service-closed h1 { margin: 0 0 10px; font-size: 26px; font-weight: 700; }
        .service-closed p { max-width: 480px; margin: 0; color: var(--secondary-text-color); font-size: 14px; line-height: 1.8; }

        /* ── 12. 响应式（断点取 EZ 的 992 / 769↔768 / 576 / 480；EZ 自己 768 处 min 与 max 重叠，这里改 769 避免打架）── */
        @media (min-width: 993px) {
            .auth-header { text-align: left; }
        }
        /* 矮屏防溢出：阈值取 850 而非 EZ Login 的 700，因为本页右栏要承载注册表单 + 三字段 + 长脚注 */
        @media (min-width: 993px) and (max-height: 850px) {
            .auth-split-right { justify-content: flex-start; }
        }
        @media (max-width: 992px) {
            /* 降级回文档流，规避移动键盘与 100vh 问题 */
            .auth-split-container { position: relative; inset: auto; min-height: 100vh; min-height: 100dvh; flex-direction: column; overflow-y: auto; }
            .auth-split-left { display: none; }
            .auth-split-right { flex: 1; width: 100%; max-width: none; padding: 40px 0; overflow-y: visible; }
        }
        @media (min-width: 769px) {
            .content-area { padding: 90px 2rem 2rem; }
        }
        @media (min-width: 1200px) {
            .content-area { padding: 90px 4rem 2rem; }
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .content-area { padding: 78px 10px 78px; }   /* 底部留白让位固定底部导航条 */
            .container { max-width: 100%; padding: 0 10px; }
            .content-wrapper { flex-direction: column; gap: 20px; }
            .site-logo { top: 14px; left: 14px; }
            .theme-toggle { top: 14px; right: 14px; }
            /* EZ 的移动端做法：胶囊从顶部居中改为底部固定，动作落进拇指区 */
            .slide-tabs-container { top: auto; bottom: 20px; width: 92%; max-width: 450px; }
            .slide-tabs-wrapper { display: block; width: 100%; padding: 3px; border-radius: 20px; }
            .slide-tabs-nav { justify-content: space-around; }
            .nav-item { flex: 1; height: 44px; padding: 0 8px; font-size: 13px; }
            .page-title { font-size: 22px; }
            .approval-banner { display: block; }
            .stats-grid { gap: 12px; margin-bottom: 20px; }
        }
        @media (max-width: 576px) {
            .auth-split-right { padding: 20px 0; }
            .auth-form-container { padding: 30px 20px; }
            .auth-title { font-size: 1.5rem; }
            .two-col:not(.two-col-prices) { grid-template-columns: 1fr; }
        }
        @media (max-width: 480px) {
            .slide-tabs-container { bottom: 12px; width: 94%; }
            .slide-tabs-wrapper { border-radius: 18px; }
            .nav-item { padding: 0 6px; font-size: 12px; }
            .toast { top: 12px; right: 12px; left: 12px; max-width: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { scroll-behavior: auto !important; transition: none !important; animation: none !important; }
        }
    </style>
</head>
{{-- ⚠ data-reseller-enabled 必须留在 <body> 上：app.js:10 读 document.body.dataset.resellerEnabled，
     读不到就在 :17 提前 return，整页 JS 全死。 --}}
<body data-reseller-enabled="{{ $reseller_enabled ? '1' : '0' }}">
<div id="message" class="toast" hidden role="status" aria-live="polite"></div>
{{-- 明暗切换：页面级按钮，三个视图（登录壳 / 工作台 / 服务未开放页）都可用。点击由
     <head> 内联脚本委托处理并写入 localStorage('reseller_theme')；app.js 不感知此按钮。 --}}
<button id="theme-toggle" class="theme-toggle" type="button" aria-label="切换深浅色模式">
    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
@if (!$reseller_enabled)
<main class="service-closed" role="status">
    <div>
        <h1>倒卖商服务暂未开放</h1>
        <p>当前暂未开放倒卖商账号注册、登录和店铺销售，请稍后再试。</p>
    </div>
</main>
@endif
<section id="auth-shell" class="auth-split-container" @if (!$reseller_enabled) hidden @endif>
    <div class="auth-split-left">
        <div class="left-content-overlay"></div>
        <div class="site-name white"><span class="site-mark">R</span><span>倒卖工作区</span></div>
        <div class="left-content">
            <h2 class="left-title">把你的销售店铺管理得更清楚。</h2>
            <p class="left-subtitle">从平台允许的基础套餐开始，设置展示价格与收款方式，统一管理店铺运营状态。</p>
            <ol class="left-steps">
                <li class="left-step"><span class="step-badge">1</span><span class="step-text">注册账号和店铺，等待管理员分别审核</span></li>
                <li class="left-step"><span class="step-badge">2</span><span class="step-text">选择已发布的基础套餐并设置销售价格</span></li>
                <li class="left-step"><span class="step-badge">3</span><span class="step-text">配置白名单支付驱动后开放销售页</span></li>
            </ol>
        </div>
        <div class="greeting-text white">渠道运营工作区</div>
    </div>
    <div class="auth-split-right">
        <div class="auth-form-container">
            {{-- ⚠ #auth-heading / #auth-caption 必须保持纯文本：app.js:401-402 用 textContent 覆写，
                 setter 会清空全部子节点，塞进去的装饰元素首次切 tab 就没了。 --}}
            <div class="auth-header">
                <h1 id="auth-heading" class="auth-title">登录倒卖商工作台</h1>
                <p id="auth-caption" class="auth-subtitle">使用已审核的账号继续管理你的店铺。</p>
            </div>
            {{-- .slider-indicator 必须排在两个 button 之后：CSS 用 ~ 兄弟选择器读 .is-active 驱动滑块 --}}
            <div class="auth-tabs" role="tablist" aria-label="倒卖商认证">
                <button class="auth-tab is-active" type="button" data-auth-tab="login" role="tab" aria-selected="true">登录</button>
                <button class="auth-tab" type="button" data-auth-tab="register" role="tab" aria-selected="false">申请入驻</button>
                <span class="slider-indicator" aria-hidden="true"></span>
            </div>
            <div id="registration-credentials" class="registration-credentials" hidden role="status" aria-live="polite"></div>
            <form id="login-form" class="form-stack">
                <label class="field">邮箱
                    <span class="input-with-icon">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7.5 8.5 6 8.5-6"/></svg>
                        <input name="email" type="email" autocomplete="email" placeholder="name@example.com" required>
                    </span>
                </label>
                <label class="field">密码
                    <span class="input-with-icon">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                        <input name="password" type="password" autocomplete="current-password" placeholder="请输入密码" required>
                    </span>
                </label>
                {{-- ⚠ 提交按钮必须是 form 内的 button[type=submit]（app.js:411 用 event.target.querySelector 找它做
                     loading 态），且必须纯文本（app.js:176-186 的 setButtonLoading 用 textContent 读写）。 --}}
                <button class="btn btn-primary btn-block" type="submit">登录工作台</button>
            </form>
            <form id="register-form" class="form-stack" hidden>
                <label class="field">登录邮箱
                    <span class="input-with-icon">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7.5 8.5 6 8.5-6"/></svg>
                        <input name="email" type="email" autocomplete="email" placeholder="仅用于登录" required>
                    </span>
                    <span class="field-help">邮箱仅用于登录，不用于接收通知。</span>
                </label>
                <div class="two-col">
                    <label class="field">店铺 Slug
                        <span class="input-with-icon">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M16 12v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-5.5 8.3"/></svg>
                            <input name="store_slug" pattern="[a-z0-9][a-z0-9-]{2,31}" placeholder="demo-store" required>
                        </span>
                        <span class="field-help">仅使用小写字母、数字和连字符</span>
                    </label>
                    <label class="field">店铺名称
                        <span class="input-with-icon">
                            <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21h18"/><path d="M5 21V9l7-4 7 4v12"/><path d="M10 21v-6h4v6"/></svg>
                            <input name="store_name" maxlength="128" placeholder="我的订阅店" required>
                        </span>
                    </label>
                </div>
                <button class="btn btn-primary btn-block" type="submit">提交入驻申请</button>
                <p class="auth-footnote">提交后系统会生成一组 64 位随机密码并仅显示一次。请立即保存；账号审核和店铺审核相互独立，两者都通过后店铺才会对客户开放销售。</p>
            </form>
        </div>
    </div>
</section>
<section id="workspace" class="workspace" hidden>
    {{-- ⚠ 品牌片与胶囊必须留在 #workspace 内（fixed 元素在 display:none 祖先下不渲染，
         这样登出态不会露出「刷新 / 退出」），且不要给 #workspace 加 transform/filter。 --}}
    <div class="site-logo"><span class="site-mark">R</span><span>倒卖工作区</span></div>
    <nav class="slide-tabs-container" aria-label="工作台操作">
        <div class="slide-tabs-wrapper">
            <div class="slide-tabs-nav">
                <a id="store-url-link" class="nav-item" href="/store" target="_blank" rel="noreferrer">打开销售页</a>
                <button id="refresh-workspace" class="nav-item" type="button">刷新</button>
                <button id="logout" class="nav-item" type="button">退出</button>
            </div>
        </div>
    </nav>
    <div class="content-area">
        <div class="container">
            <header class="page-header">
                <p class="page-eyebrow">CHANNEL OPERATIONS</p>
                <h1 class="page-title">倒卖商工作台</h1>
                <p class="page-subtitle">先确认审核状态，再开始配置销售内容。</p>
            </header>
            {{-- hidden 是安全的：app.js:200/204 两个分支都会写 banner.hidden = false --}}
            <div id="approval-banner" class="approval-banner" role="status" hidden></div>
            <section class="stats-grid" aria-label="审核状态">
                <div class="stats-card">
                    <div class="stats-label">登录账号</div>
                    <div id="account-email" class="stats-value">-</div>
                    <div id="account-status" class="stats-meta"></div>
                </div>
                <div class="stats-card">
                    <div class="stats-label">店铺审核</div>
                    <div id="store-status" class="stats-value">-</div>
                    <div id="store-url" class="stats-meta">-</div>
                </div>
                <div class="stats-card">
                    <div class="stats-label">审核备注</div>
                    <div id="review-note" class="stats-note">-</div>
                </div>
            </section>
            <div class="content-wrapper">
                <div class="left-column">
                    <section class="dashboard-card">
                        <div class="card-header"><h2 class="card-title">店铺信息</h2></div>
                        <p class="card-desc">客户将通过店铺 Slug 访问你的销售页面。</p>
                        <form id="store-form" class="form-stack">
                            <div class="two-col">
                                <label class="field">店铺 Slug<input name="store_slug" pattern="[a-z0-9][a-z0-9-]{2,31}" required></label>
                                <label class="field">店铺名称<input name="store_name" maxlength="128" required></label>
                            </div>
                            <label class="field">店铺介绍<textarea name="store_description" placeholder="向客户介绍你的服务内容"></textarea></label>
                            <div class="form-actions">
                                <button class="btn btn-primary" type="submit">保存店铺信息</button>
                                <span class="hint">公开地址：<span id="store-url-inline">/store/{slug}</span></span>
                            </div>
                        </form>
                    </section>
                    <section class="dashboard-card">
                        <div class="card-header"><h2 class="card-title">销售套餐</h2></div>
                        <p class="card-desc">节点、流量和设备限制继承管理员发布的基础套餐。基础套餐、节点能力和平台权限由管理员统一控制，你只需管理展示信息、价格和收款配置。</p>
                        <fieldset data-sale-control>
                            <form id="plan-form" class="form-stack">
                                <input type="hidden" name="id">
                                <div class="two-col">
                                    <label class="field">基础套餐<select id="plan-template-select" name="base_plan_id" required><option value="">请选择管理员已上架套餐</option></select></label>
                                    <label class="field">展示名称<input name="name" placeholder="例如：高速月付" required></label>
                                </div>
                                <label class="field">展示内容<textarea name="content" placeholder="面向客户展示的套餐说明"></textarea></label>
                                {{-- ⚠ 下面这个 div 必须是 #plan-form 内「第 2 个」.two-col，且必须是 form 的直接子元素：
                                     app.js:113 执行 form.insertBefore(共享人数字段, grids[1])。
                                     · 包一层 wrapper → insertBefore 抛 NotFoundError，整个 IIFE 在 :675 中断，
                                       共享群组面板消失 + 自动登录失效；
                                     · 把两个 .two-col 合并或改名 → app.js:97 的 grids.length<2 提前 return，
                                       「共享人数」字段静默消失，planBody() 默认填 1，倒卖商永久无法创建共享套餐。 --}}
                                <div class="two-col two-col-prices">
                                    <label class="field">月付（元）<input name="month_price" type="number" min="0.01" step="0.01" inputmode="decimal"></label>
                                    <label class="field">季付（元）<input name="quarter_price" type="number" min="0.01" step="0.01" inputmode="decimal"></label>
                                    <label class="field">半年付（元）<input name="half_year_price" type="number" min="0.01" step="0.01" inputmode="decimal"></label>
                                    <label class="field">年付（元）<input name="year_price" type="number" min="0.01" step="0.01" inputmode="decimal"></label>
                                    <label class="field">两年付（元）<input name="two_year_price" type="number" min="0.01" step="0.01" inputmode="decimal"></label>
                                    <label class="field">三年付（元）<input name="three_year_price" type="number" min="0.01" step="0.01" inputmode="decimal"></label>
                                    <label class="field">一次性（元）<input name="onetime_price" type="number" min="0.01" step="0.01" inputmode="decimal"></label>
                                </div>
                                <div class="form-actions">
                                    <button class="btn btn-primary" type="submit">保存销售套餐</button>
                                    <span class="hint">至少设置一个大于 0 元的周期价格</span>
                                </div>
                            </form>
                        </fieldset>
                        <div id="plans" class="data-list" style="margin-top:15px"></div>
                    </section>
                </div>
                <div class="right-column">
                    <section class="dashboard-card">
                        <div class="card-header"><h2 class="card-title">可用基础套餐</h2></div>
                        <p class="card-desc">管理员发布后才会显示。</p>
                        <div id="templates" class="template-list"></div>
                    </section>
                    <section class="dashboard-card">
                        <div class="card-header"><h2 class="card-title">支付配置</h2></div>
                        <p class="card-desc">配置字段会按照管理员支付配置中的驱动定义生成，密钥由系统加密保存。</p>
                        <fieldset data-sale-control>
                            <form id="payment-form" class="form-stack">
                                <input type="hidden" name="id">
                                <label class="field">支付驱动<select id="payment-driver" name="driver" required aria-describedby="payment-driver-help"></select><span id="payment-driver-help" class="field-help">选择驱动后填写对应的支付参数。</span></label>
                                <label class="field">支付名称<input name="name" placeholder="例如：支付宝" required></label>
                                <div id="payment-fields" class="payment-fields" aria-live="polite"><div class="empty-state">请选择支付驱动加载配置字段。</div></div>
                                <label class="field field-check"><input name="enabled" type="checkbox" value="1">启用此支付方式</label>
                                <div class="form-actions">
                                    <button id="payment-save" class="btn btn-primary" type="submit">保存支付配置</button>
                                    <button id="payment-cancel-edit" class="btn btn-quiet" type="button" hidden>取消编辑</button>
                                </div>
                            </form>
                        </fieldset>
                        <div id="payments" class="data-list" style="margin-top:15px"></div>
                    </section>
                    <section class="dashboard-card">
                        <div class="card-header"><h2 class="card-title">销售概览</h2></div>
                        <p class="card-desc">按需读取当前店铺客户和订单数量。</p>
                        {{-- ⚠ 这个 .form-actions 必须与 #audit 同父、且是该父元素子树内唯一的一个：
                             app.js:561 用 audit.parentElement.querySelector('.form-actions') 找它来挂载
                             「共享群组」按钮，找不到就在 :562 提前 return，整块面板静默消失。
                             同理别给 #audit 加包裹层（:574 会往同一个父元素 append #shared-groups）。 --}}
                        <div class="form-actions">
                            <button id="load-customers" class="btn btn-quiet" type="button">读取客户数</button>
                            <button id="load-orders" class="btn btn-quiet" type="button">读取订单数</button>
                        </div>
                        <div id="audit" class="data-list" style="margin-top:12px"><div class="empty-state">点击上方按钮查看统计。</div></div>
                    </section>
                </div>
            </div>
        </div>
    </div>
</section>
{{--
    ═══ JS 耦合契约（改本文件前必读；权威来源是 public/assets/reseller/app.js）═══

    app.js 用 innerHTML 注入下列类名，样式必须继续存在且在明暗两态下可读：
      toast / toast-success / toast-error · field / field-help / payment-field
      registration-password · btn btn-quiet · approval-banner + -warning / -ready
      status-pill + status-active / -pending / -rejected / -suspended / -neutral
      template-option · empty-state · list-row · payment-row-actions
      status-dot + is-on · audit-stat · data-list

    结构依赖（违反后多数是静默失效，不报错）：
      · #plan-form 内恰好 2 个 .two-col，第 2 个是 form 直接子元素   → app.js:96-97, 113
      · #audit 的父元素内有且仅有 1 个 .form-actions                 → app.js:561, 574
      · data-sale-control 在 <fieldset> 上（靠 :disabled 向后代传播） → app.js:195
      · is-active + data-auth-tab 原名保留                            → app.js:394-398
      · 每个 form 内恰好 1 个 button[type=submit] 且在 form 内        → app.js:411/429/444/463
      · 受 setButtonLoading 管的按钮必须纯文本（textContent 会清子节点）→ app.js:176-186
        名单：4 个 submit、#payment-save、#load-customers、#load-orders、#refresh-workspace
        （#logout 与 #payment-cancel-edit 不走它，可以带图标）
      · #auth-heading / #auth-caption 保持纯文本                      → app.js:401-402
      · data-reseller-enabled 留在 <body> 上                          → app.js:10, 17
      · 全部 name 属性不变（FormData / form.elements）
      · [hidden]{display:none!important} 与 .list-row span 的后代选择器不能动

    页面级明暗机制（与 app.js 无关）：
      · <head> 内联脚本盖章 data-theme + 委托 #theme-toggle 点击 + localStorage('reseller_theme')
      · 暗色令牌只挂 :root[data-theme="dark"]，全表不得出现裸 prefers-color-scheme 色彩规则
        （prefers-reduced-motion 不在此列）
--}}
@php ($resellerAssetVersion = is_file(public_path('assets/reseller/app.js')) ? filemtime(public_path('assets/reseller/app.js')) : config('app.version'))
<script src="/assets/reseller/app.js?v={{ $resellerAssetVersion }}"></script>
{{-- 落地页「入驻申请」深链：/reseller#register 直达申请 tab。上面的 app.js 是同步加载，
     执行到这里 tab 点击监听（app.js:405-407）已绑定（服务开启时），合成一次点击即可复用
     showAuthTab 全套切换逻辑。app.js 全文不读 location.hash，锚点不撞任何既有通道。
     两个守卫（对抗核查修正）：
     · auth-shell hidden 时跳过点击 —— 覆盖两种情形：关服分支（[data-auth-tab] 其实仍在
       DOM，:438 只是给 #auth-shell 加 hidden；此时 app.js:17 因 serviceEnabled=false 提前
       return、监听未绑，点了也无效，但跳过更干净）；已存 token 时 boot() 同步隐藏 auth-shell
       直入工作区（若 token 随后失效弹回，用户看到的是默认登录 tab，与「请重新登录」提示一致）。
     · replaceState 一次性消费锚点 —— 否则 hash 在本标签页所有后续整页加载中重放合成点击：
       登出（app.js:646 用 location.reload()）本应回登录 tab 却被拽回申请 tab；用户手动切回
       登录 tab 后按 F5 也会被拽回。 --}}
<script>
if (location.hash === '#register') {
    var resellerRegisterTab = document.querySelector('[data-auth-tab="register"]');
    var resellerAuthShell = document.getElementById('auth-shell');
    if (resellerRegisterTab && resellerAuthShell && !resellerAuthShell.hidden) resellerRegisterTab.click();
    if (window.history && history.replaceState) history.replaceState(null, '', location.pathname + location.search);
}
</script>
</body>
</html>
