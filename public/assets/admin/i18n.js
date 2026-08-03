/**
 * 管理端运行时覆盖翻译层（fork 手写资产，与 custom.css 同类，就地维护）。
 *
 * 原理：管理端 UI 是无源码的上游编译产物（umi.js），无法做传统 i18n。
 * 本层在 DOM 层做字典替换——MutationObserver 回调在 paint 前执行，翻译新增
 * 文本节点与 placeholder/title/aria-label 属性；失败模式=没翻到的串保持中文，
 * 永不报错白屏。zh-CN（源语言，默认）下不挂观察器（DOM 零开销）；网络层
 * 补丁所有语言都装、头值=当前 UI 语言（BCP 47），后端 Language 中间件即按
 * UI 语言出接口报错。bundle 一个字节不改，补丁锚点不受影响。
 *
 * 语言集与全站对齐（8 语，见 resources/lang/ 与 signature 主题），标签遵循
 * BCP 47。字典按语种拆文件 i18n.<code>.js：本引擎是 <body> 内第一个解析中
 * 脚本，document.write 注入的同源脚本同步阻塞加载，保证 vendors/umi 执行前
 * 字典就位（无闪中文）；write 被拦时回退异步注入，到货后全量补翻一遍。
 *
 * 切换器：#page-header 右侧按钮组首位注入下拉（样式与暗色模式按钮同配方，
 * 跟随 header 明暗），登录页等无 header 场景回退右下角胶囊。React 更新右侧
 * 容器时以它自己的节点做锚定，外来首子节点通常存活；1.5s 巡检兜底重插。
 *
 * 字典生产管线：php scripts/extract-admin-i18n.php 抽取三份 bundle 的中文
 * 字面量骨架 → 翻译 → 生成各语种 i18n.<code>.js（含 dict 与 rules 两段）。
 * bundle 打补丁或换版后重跑抽取脚本 diff 出新串增补；漏翻只显示中文，不会坏。
 *
 * 已知边界（接受，勿当 bug 修）：
 * - 用户数据恰好整串等于字典键会被误翻（如套餐名就叫「删除」）——纯外观；
 * - React 把一句话拆成多个兄弟文本节点时只翻中的部分——按 QA 结果往
 *   规则表或字典补条目；
 * - TEXTAREA 内容与 input value 不翻（用户输入），placeholder 翻；
 * - fa-IR 仅文本按 Unicode bidi 呈现，整体布局保持 LTR（编译产物无源码，
 *   无法安全翻转 OneUI 布局）。
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'v2board_admin_locale';
    // BCP 47 注册表，与全站 8 语对齐；菜单文案用语种原生名（i18n 惯例）
    var LOCALES = [
        ['zh-CN', '简体中文'],
        ['zh-TW', '繁體中文'],
        ['en-US', 'English'],
        ['ja-JP', '日本語'],
        ['ko-KR', '한국어'],
        ['vi-VN', 'Tiếng Việt'],
        ['ru-RU', 'Русский'],
        ['fa-IR', 'فارسی']
    ];
    var locale = 'zh-CN';
    try { locale = window.localStorage.getItem(STORAGE_KEY) || 'zh-CN'; } catch (e) { /* 隐私模式下保持默认 */ }
    (function () {
        for (var i = 0; i < LOCALES.length; i++) if (LOCALES[i][0] === locale) return;
        locale = 'zh-CN'; // 表外值一律回源语言
    })();

    var hop = Object.prototype.hasOwnProperty;

    // —— 顶栏语言下拉：所有语言下都渲染（否则中文态没有切换入口）——
    function buildMenu(pill) {
        var menu = document.createElement('div');
        menu.className = 'dropdown-menu dropdown-menu-right p-0';
        menu.style.minWidth = '11rem';
        if (pill) menu.style.cssText += 'position:absolute;right:0;bottom:36px;top:auto;left:auto;';
        for (var i = 0; i < LOCALES.length; i++) {
            (function (code, label) {
                var item = document.createElement('a');
                item.href = 'javascript:void(0)';
                item.className = 'dropdown-item py-2' + (code === locale ? ' active' : '');
                item.setAttribute('lang', code);
                var text = document.createElement('span');
                text.textContent = label;
                item.appendChild(text);
                if (code === locale) {
                    var check = document.createElement('i');
                    check.className = 'fa fa-check ml-2';
                    item.appendChild(check);
                }
                item.onclick = function () {
                    try { window.localStorage.setItem(STORAGE_KEY, code); } catch (e) {}
                    window.location.reload();
                };
                menu.appendChild(item);
            })(LOCALES[i][0], LOCALES[i][1]);
        }
        return menu;
    }

    // 文档级关单监听只挂一次；巡检重挂切换器时换掉当前关闭器即可（防监听器随重挂累积）
    var activeCloser = null;
    document.addEventListener('click', function () {
        if (activeCloser) activeCloser();
    });
    function wireDropdown(btn, menu, baseClass) {
        var open = false;
        function close() {
            if (!open) return;
            open = false;
            btn.setAttribute('aria-expanded', 'false');
            menu.className = baseClass;
        }
        btn.onclick = function (ev) {
            ev.stopPropagation();
            open = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            menu.className = baseClass + (open ? ' show' : '');
            activeCloser = close;
        };
    }

    // 头部形态：与暗色模式按钮同配方（umi.js: 'dark'===header ? 'btn btn-primary mr-1' : 'btn mr-1'）
    function buildSwitcher() {
        var wrap = document.createElement('div');
        wrap.id = 'v2-i18n-switch';
        wrap.className = 'dropdown d-inline-block';
        var headerDark = false;
        try { headerDark = window.settings && window.settings.theme && window.settings.theme.header === 'dark'; } catch (e) {}
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = headerDark ? 'btn btn-primary mr-1' : 'btn mr-1';
        btn.title = 'Language / 语言';
        btn.setAttribute('aria-haspopup', 'true');
        btn.setAttribute('aria-expanded', 'false');
        var icon = document.createElement('i');
        icon.className = 'fa fa-fw fa-language';
        btn.appendChild(icon);
        var menu = buildMenu(false);
        wireDropdown(btn, menu, 'dropdown-menu dropdown-menu-right p-0');
        wrap.appendChild(btn);
        wrap.appendChild(menu);
        return wrap;
    }

    // 无 header 场景（登录页）的右下角胶囊形态
    function buildPill() {
        var wrap = document.createElement('div');
        wrap.id = 'v2-i18n-switch';
        wrap.style.cssText = 'position:fixed;right:14px;bottom:14px;z-index:2147483000;';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.title = 'Language / 语言';
        btn.setAttribute('aria-haspopup', 'true');
        btn.setAttribute('aria-expanded', 'false');
        btn.style.cssText = 'min-width:36px;height:28px;padding:0 10px;border-radius:14px;' +
            'border:1px solid rgba(0,0,0,.18);background:rgba(255,255,255,.94);color:#333;' +
            'font:13px/26px Arial,Helvetica,sans-serif;cursor:pointer;box-shadow:0 1px 4px rgba(0,0,0,.15);';
        var icon = document.createElement('i');
        icon.className = 'fa fa-fw fa-language';
        btn.appendChild(icon);
        var menu = buildMenu(true);
        wireDropdown(btn, menu, menu.className);
        wrap.appendChild(btn);
        wrap.appendChild(menu);
        return wrap;
    }

    function mountSwitcher() {
        var existing = document.getElementById('v2-i18n-switch');
        var header = document.getElementById('page-header');
        if (header) {
            // 右侧容器特征：content-header 的末子元素、内含 .dropdown（暗色模式按钮常驻）
            var ch = header.querySelector('.content-header');
            var right = null;
            if (ch && ch.children.length) {
                var cand = ch.children[ch.children.length - 1];
                if (cand && cand.querySelector && cand.querySelector('.dropdown')) right = cand;
            }
            if (right) {
                if (existing && existing.parentNode === right) return;
                if (existing && existing.parentNode) existing.parentNode.removeChild(existing); // 胶囊→头部形态迁移
                right.insertBefore(buildSwitcher(), right.firstChild);
                return;
            }
            if (existing) return; // header 在但右容器未就绪（spinner 期），下轮巡检再试
        }
        if (existing) return;
        if (document.body) document.body.appendChild(buildPill());
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountSwitcher);
    } else {
        mountSwitcher();
    }
    setInterval(mountSwitcher, 1500); // React 重渲染丢节点 / 路由切换的兜底巡检

    // —— 网络层（所有语言都装）：同源 /api/ 请求补 Content-Language=当前 UI 语言，
    // 后端 Language 中间件即按 UI 语言出报错；zh-CN 也要显式锁定，否则英文浏览器的
    // Accept-Language 会让中文 UI 配上非中文接口报错 ——
    function isApiUrl(url) {
        try {
            var u = new URL(url, window.location.href);
            return u.origin === window.location.origin && u.pathname.indexOf('/api/') === 0;
        } catch (e) { return false; }
    }

    var origFetch = window.fetch;
    if (origFetch) {
        window.fetch = function (input, init) {
            try {
                // 只处理字符串 URL 形态；Request 对象形态不动（覆写 init.headers 会
                // 整体替换 Request 自带头，可能吞掉鉴权头——宁可漏补不可破坏请求）
                if (typeof input === 'string' && isApiUrl(input)) {
                    init = init || {};
                    if (init.headers instanceof Headers) {
                        if (!init.headers.has('Content-Language')) init.headers.set('Content-Language', locale);
                    } else {
                        var h = {};
                        if (init.headers) for (var k in init.headers) if (hop.call(init.headers, k)) h[k] = init.headers[k];
                        var found = false;
                        for (var k2 in h) if (k2.toLowerCase() === 'content-language') { found = true; break; }
                        if (!found) h['Content-Language'] = locale;
                        init.headers = h;
                    }
                }
            } catch (e) { /* 补头失败不拦请求 */ }
            return origFetch.call(this, input, init);
        };
    }

    var xhrOpen = XMLHttpRequest.prototype.open;
    var xhrSend = XMLHttpRequest.prototype.send;
    var xhrSetHeader = XMLHttpRequest.prototype.setRequestHeader;
    XMLHttpRequest.prototype.open = function (method, url) {
        this.__v2i18nApi = isApiUrl(url);
        this.__v2i18nHasCL = false;
        return xhrOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
        // XHR 同名 setRequestHeader 是「追加」语义，必须防重复
        if (String(name).toLowerCase() === 'content-language') this.__v2i18nHasCL = true;
        return xhrSetHeader.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function () {
        if (this.__v2i18nApi && !this.__v2i18nHasCL) {
            try { xhrSetHeader.call(this, 'Content-Language', locale); } catch (e) { /* open 前 send 等异常态 */ }
        }
        return xhrSend.apply(this, arguments);
    };

    if (locale === 'zh-CN') return; // 源语言：切换器+网络层即全部，DOM 零开销退出

    // 注意：html lang 在 apply 钩子里、字典确认到货后才盖章——字典 404 时整页
    // 仍是中文，提前声明成 ja-JP 会让浏览器选错汉字字形、读屏器选错语音。

    // —— 字典与规则（按语种拆文件，i18n.<code>.js 调用 apply 钩子送达）——
    var DICT = {};
    var REGEX = [];
    var CJK = /[一-鿿]/;

    // 22 条模板规则的「模式」在引擎（各语种共用），替换文由字典文件按 id 提供；
    // 这些是 bundle 里 "前缀"+变量+"后缀" 拼成单文本节点的模板，碎片词条永远匹配不上
    var RULE_PATTERNS = [
        ['total_items', /^共 (\d+) 条(?:数据)?$/],
        ['per_page', /^(\d+) 条\/页$/],
        ['page_n', /^第 (\d+) 页$/],
        ['secs_ago_few', /^几秒前$/],
        ['secs_ago', /^(\d+) 秒前$/],
        ['min_ago_1', /^1 分钟前$/],
        ['mins_ago', /^(\d+) 分钟前$/],
        ['hour_ago_1', /^1 小时前$/],
        ['hours_ago', /^(\d+) 小时前$/],
        ['day_ago_1', /^1 天前$/],
        ['days_ago', /^(\d+) 天前$/],
        ['months_ago', /^(\d+) 个月前$/],
        ['years_ago', /^(\d+) 年前$/],
        ['del_rule', /^确定要删除规则「(.+?)」吗？已判定的历史周期不受影响，除非重算。$/],
        ['del_user', /^确定要删除(.+?)的用户信息吗？$/],
        ['reset_sec', /^确定要重置(.+?)的安全信息吗？$/],
        ['gen_pwd', /^将为 (.+?) 生成一个 64 位随机密码（大小写字母 \+ 数字），原密码立即失效。$/],
        ['recalc_title', /^重算 (.+?) 的历史周期$/],
        ['audit_title', /^订阅审计 - (.+?)（风险：(.+?)）$/],
        ['risk_stats', /^客户端下载订阅配置的来源；请求 (\d+) 次，UA (\d+) 种，IP (\d+) 个，地区 (\d+)，国家 (\d+)$/],
        ['ticket_hdr', /^工单 #(\d+) · 用户 #(\d+)$/],
        ['recalc_done', /^订阅 (\d+) 个，重算周期 (\d+) 个。$/]
    ];
    // 日期补零规则各语种共用（面板机器格式化到处是 YYYY-MM-DD，不能混出 2026-8-3）
    function pad2(n) { return ('0' + n).slice(-2); }
    var DATE_RULES = [
        [/^(\d{4})年(\d{1,2})月(\d{1,2})日$/, function (_, y, m, d) { return y + '-' + pad2(m) + '-' + pad2(d); }],
        [/^(\d{4})年(\d{1,2})月$/, function (_, y, m) { return y + '-' + pad2(m); }]
    ];

    // 字典文件到货钩子（引擎先于 document.write 注入的脚本执行完毕，定义时序天然满足）
    window.__v2AdminI18nApply = function (payload) {
        if (!payload || payload.locale !== locale) return;
        document.documentElement.lang = locale; // 字典确认到货才声明语言
        DICT = payload.dict || {};
        var rules = payload.rules || {};
        REGEX = [];
        for (var i = 0; i < RULE_PATTERNS.length; i++) {
            var rep = rules[RULE_PATTERNS[i][0]];
            if (typeof rep === 'string') REGEX.push([RULE_PATTERNS[i][1], rep]);
        }
        REGEX = REGEX.concat(DATE_RULES);
        initialPass(); // 异步兜底路径下字典晚到：此刻全量补翻
    };

    function translateString(raw) {
        if (!raw || !CJK.test(raw)) return null;
        if (hop.call(DICT, raw)) return DICT[raw];
        var m = raw.match(/^(\s*)([\s\S]*?)(\s*)$/);
        var core = m[2];
        if (core !== raw && hop.call(DICT, core)) return m[1] + DICT[core] + m[3];
        // antd Button 对「恰好两个汉字」的无图标按钮渲染时中间插一个空格（取 消/删 除），
        // 用去空格后的原词重查一次（范围一-龥与 antd 自己的正则一致）
        var pair = core.match(/^([一-龥]) ([一-龥])$/);
        if (pair && hop.call(DICT, pair[1] + pair[2])) return m[1] + DICT[pair[1] + pair[2]] + m[3];
        for (var i = 0; i < REGEX.length; i++) {
            if (REGEX[i][0].test(core)) return m[1] + core.replace(REGEX[i][0], REGEX[i][1]) + m[3];
        }
        return null;
    }

    // 切换器自身的菜单文案是各语种原生名（简体中文/日本語…），恰好都是字典键——
    // 不豁免的话引擎会把自己的菜单翻掉（en 下变 Simplified Chinese/Japanese），
    // 迷路的管理员认不出自己的语言项，原生名的意义尽失。
    function insideSwitcher(n) {
        for (; n; n = n.parentNode) if (n.id === 'v2-i18n-switch') return true;
        return false;
    }

    var SKIP_PARENT = { TEXTAREA: 1, SCRIPT: 1, STYLE: 1, NOSCRIPT: 1 };
    function translateTextNode(node) {
        // 观察器路径的文本节点不经 walk() 的标签过滤（变更记录直接给文本节点），
        // 必须在此二次守卫，否则 textarea 默认值（用户输入区）会被改写
        var p = node.parentNode;
        if (p && SKIP_PARENT[p.nodeName]) return;
        if (insideSwitcher(p)) return;
        var t = translateString(node.nodeValue);
        if (t !== null && t !== node.nodeValue) node.nodeValue = t;
        // 写入触发的 characterData 变更再进 translateString 时已无 CJK，天然终止，无回环
    }

    var ATTRS = ['placeholder', 'title', 'aria-label'];
    function translateElementAttrs(el) {
        if (!el.getAttribute) return;
        if (insideSwitcher(el)) return;
        for (var i = 0; i < ATTRS.length; i++) {
            var v = el.getAttribute(ATTRS[i]);
            if (v) {
                var t = translateString(v);
                if (t !== null && t !== v) el.setAttribute(ATTRS[i], t);
            }
        }
    }

    function walk(root) {
        if (root.nodeType === 3) { translateTextNode(root); return; }
        if (root.nodeType !== 1) return;
        if (root.id === 'v2-i18n-switch') return; // 切换器子树整体豁免
        var name = root.nodeName;
        if (name === 'SCRIPT' || name === 'STYLE' || name === 'NOSCRIPT') return;
        translateElementAttrs(root);
        if (name === 'TEXTAREA') return; // 用户输入内容不动，placeholder 已翻
        for (var child = root.firstChild; child; child = child.nextSibling) walk(child);
    }

    // 挂 documentElement：antd 的 Modal/Dropdown/Notification portal 直挂 body，天然覆盖
    var observer = new MutationObserver(function (mutations) {
        for (var i = 0; i < mutations.length; i++) {
            var m = mutations[i];
            if (m.type === 'characterData') translateTextNode(m.target);
            else if (m.type === 'attributes') translateElementAttrs(m.target);
            else for (var j = 0; j < m.addedNodes.length; j++) walk(m.addedNodes[j]);
        }
    });
    observer.observe(document.documentElement, {
        childList: true, subtree: true, characterData: true,
        attributes: true, attributeFilter: ATTRS
    });

    // 首过兜底（观察器理论上已覆盖 React 全部渲染，双保险成本≈0）
    function initialPass() { if (document.body) walk(document.body); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialPass);
    } else {
        initialPass();
    }
    window.addEventListener('load', initialPass);

    // —— 装载本语种字典：locale 已过注册表白名单，URL 不可注入 ——
    (function () {
        var v = '';
        try {
            var cs = document.currentScript;
            if (cs && cs.src) {
                var qi = cs.src.indexOf('?');
                if (qi > -1) v = cs.src.slice(qi);
            }
        } catch (e) { /* 无 currentScript 场景：不带版本参数 */ }
        var src = '/assets/admin/i18n.' + locale + '.js' + v;
        var wrote = false;
        if (document.readyState === 'loading') {
            try {
                // 解析阶段 document.write 注入同源脚本=同步阻塞，umi 执行前字典必然就位；
                // 解析结束后 write 会清空文档，绝不能走这条路（readyState 守卫）
                document.write('<script src="' + src + '"><\/script>');
                wrote = true;
            } catch (e) { /* 拦截或异常：走异步兜底 */ }
        }
        if (!wrote) {
            var s = document.createElement('script');
            s.src = src;
            (document.head || document.documentElement).appendChild(s);
        }
    })();
})();
