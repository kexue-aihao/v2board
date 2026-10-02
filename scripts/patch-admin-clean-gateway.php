<?php

/**
 * 管理端「订阅清洗网关」补丁（本文件由脚本生成，锚点取自真实产物，勿手改）。
 *
 * 这一版把原来的两个页面并成一个栏目：
 *   1. 用 scripts/admin-clean-gateway-module.js 整块替换 riskgatewaypage 模块；
 *   2. 删掉 riskrulepage 模块与它的 /risk/rule 路由、侧栏菜单项；
 *   3. 侧栏 /risk/gateway 菜单项改名为「订阅清洗网关」；
 *   4. 用户列表删掉「风险」「风险值」两列与两个筛选项；
 *   5. 用户审计抽屉删掉「重算该用户历史周期」按钮、recomputeUserRisk 方法，
 *      以及标题与摘要里的风险判定（判定引擎已整体删除）。
 *
 * 全部按锚点替换，可重复执行：每处都先判断「已经打过」就跳过，锚点找不到则报错退出，
 * 绝不写出半截产物。多行锚点要求 LF 行尾 —— 仓库与部署目标都是 LF，Windows 工作副本是
 * CRLF，在那种副本上跑会静默失配，所以这里直接拒绝而不是改坏文件。
 */

$bundlePath = __DIR__ . '/../public/assets/admin/umi.js';
$bundle = file_get_contents($bundlePath);
if ($bundle === false) {
    fwrite(STDERR, "Unable to read admin bundle.\n");
    exit(1);
}

if (strpos($bundle, "\r\n") !== false) {
    fwrite(STDERR, "Admin bundle has CRLF line endings; run this patch on the LF checkout.\n");
    exit(1);
}

$changed = 0;

// ---- 1. 逐处文案与结构替换 -------------------------------------------------
$replacements = [
    'route_rule' => [
        '        }, {
            path: "/risk/rule",
            exact: !0,
            component: n("riskrulepage").default
',
        ''
    ],
    'menu_rule_item' => [
        '                    }, {
                        title: "订阅清洗网关",
                        type: "item",
                        href: "/risk/rule",
                        icon: o.a.createElement("i", {
                            className: "nav-main-link-icon si si-shield"
                        })
',
        ''
    ],
    'menu_gateway_title' => [
        '                        title: "订阅风控网关",
                        type: "item",
                        href: "/risk/gateway",',
        '                        title: "订阅清洗网关",
                        type: "item",
                        href: "/risk/gateway",'
    ],
    'userlist_risk_columns' => [
        '                }, {
                    title: "风险",
                    dataIndex: "risk",
                    key: "risk",
                    render: e=>{
                        var t = e && e.status,
                            n = "suspicious" === t ? "red" : "normal" === t ? "green" : "orange",
                            r = "suspicious" === t ? "疑似内鬼" : "normal" === t ? "正常" : "待观察";
                        return g.a.createElement(h["a"], {
                            color: n,
                            title: e && e.reasons && e.reasons.length ? e.reasons.join("；") : ""
                        }, r)
                    }
                }, {
                    title: "风险值",
                    key: "risk_score",
                    dataIndex: "risk",
                    sorter: !0,
                    render: e=>{
                        var score = e && null !== e.score && void 0 !== e.score ? Number(e.score) : null;
                        return null === score || isNaN(score) ? g.a.createElement("span", {
                            className: "text-muted"
                        }, "—") : g.a.createElement("span", {
                            style: {
                                fontWeight: 600
                            }
                        }, score + "%")
                    }
',
        ''
    ],
    'userlist_risk_filters' => [
        '                    }, {
                        // 手工补丁：风险徽标过滤。选项值与 summaryForUser 的三态一致，
                        // 后端在 UserController::applyRiskFilter 里翻成同语义 EXISTS。
                        key: "risk",
                        title: "风险",
                        condition: ["="],
                        type: "select",
                        options: [{
                            key: "疑似内鬼",
                            value: "suspicious"
                        }, {
                            key: "待观察",
                            value: "pending"
                        }, {
                            key: "正常",
                            value: "normal"
                        }]
                    }, {
                        key: "risk_score",
                        title: "风险值",
                        condition: [">=", ">", "<", "<=", "="]
',
        ''
    ],
    'drawer_recompute_button' => [
        ', g.a.createElement(auditButton, {
                                // 重算只改写判定、不删证据，比清空轻一档。用 dashed 和实心
                                // danger 拉开视觉差，两个按钮不会被误点。
                                type: "dashed",
                                size: "small",
                                icon: "reload",
                                style: {
                                    marginLeft: 8
                                },
                                onClick: function() {
                                    t.recomputeUserRisk(e, auditModal)
                                }
                            }, "重算该用户历史周期")',
        ''
    ],
    'drawer_recompute_method' => [
        '            // 单用户重算：调完阈值先拿一个用户验证，比全站刷一遍安全得多（爆炸半径小、
            // 一秒完成）。后端对单用户是同步跑完的（直接返回 done:true），所以这里没有
            // 全站入口那种游标循环，也不能带 restart。
            recomputeUserRisk(user, auditModal) {
                var t = this
                  , warning = ["重算会用当前规则重新判定所有已完成周期，覆盖此前的判定结果。", "若审计证据已被保留期清理，重算结果可能低于当初的真实值，原本「疑似内鬼」的周期可能被改为「正常」。", "节点连接记录按 last_seen_at 清理，历史周期的连接指标尤其容易失真。", "此操作不可撤销。"];
                p["a"].confirm({
                    title: "重算 " + user.email + " 的历史周期",
                    width: 560,
                    content: g.a.createElement("div", null, warning.map(function(line, index) {
                        return g.a.createElement("p", {
                            key: index,
                            className: index === warning.length - 1 ? "mb-0 font-w600" : "mb-2"
                        }, line)
                    })),
                    okText: "开始重算",
                    okType: "danger",
                    cancelText: "取消",
                    onOk() {
                        // 返回 Promise，让确定按钮保持 loading 直到请求结束。
                        return Object(n("t3Un")["b"])("/" + window.settings.secure_path + "/risk/rule/recompute", {
                            user_id: user.id
                        }).then(function(res) {
                            if (200 !== res.code) return;
                            var counts = res.data || {};
                            // 用户列表的风险列读的就是判定结果，不刷新会留着旧徽章。
                            t.props.dispatch({
                                type: "user/fetch"
                            }),
                            auditModal && auditModal.destroy(),
                            p["a"].success({
                                title: "重算完成",
                                content: "订阅 " + (counts.subscriptions || 0) + " 个，重算周期 " + (counts.cycles || 0) + " 个。"
                            }),
                            // 弹窗标题里的风险判定也要重新读一遍。
                            t.subscribeRequests(user)
                        }).catch(function() {
                            p["a"].error({
                                title: "请求失败",
                                content: "重算失败，请稍后重试"
                            })
                        })
                    }
                })
            }
',
        ''
    ],
    'drawer_risk_summary' => [
        '                      , s = r.risk || {}
',
        ''
    ],
    'drawer_risk_locals' => [
        '                      , l = r.summary || {}
                      , c = "suspicious" === s.status ? "疑似内鬼" : "normal" === s.status ? "正常" : "待观察";',
        '                      , l = r.summary || {};'
    ],
    'drawer_risk_title' => [
        'title: "订阅审计 - " + e.email + "（风险：" + c + "）",',
        'title: "订阅审计 - " + e.email,'
    ],
    'drawer_risk_region' => [
        ' + " 个，地区 " + (s.region_count || 0) + "，国家 " + (s.country_count || 0))',
        ' + " 个")'
    ],
    'drawer_risk_comment' => [
        '// （s 是 r.risk、u 是格式化函数……），所以 Button 必须另起一个',
        '// （u 是格式化函数……），所以 Button 必须另起一个'
    ],
    'sharedip_comment' => [
        '与 riskrulepage / risktracepage 同一路数：不建 dva model，数据访问直接',
        '与 risktracepage 同一路数：不建 dva model，数据访问直接'
    ],
];
foreach ($replacements as $id => $pair) {
    list($from, $to) = $pair;
    $at = strpos($bundle, $from);
    if ($at === false) {
        // 找不到 = 这一处已经打过（补丁的产物本身就是「它不在了」），跳过。
        continue;
    }
    if (strpos($bundle, $from, $at + 1) !== false) {
        fwrite(STDERR, "Anchor {$id} is ambiguous in the admin bundle.\n");
        exit(1);
    }
    $bundle = substr_replace($bundle, $to, $at, strlen($from));
    $changed++;
}

// ---- 2. 模块整块替换与删除 -------------------------------------------------
/**
 * 模块边界：从 marker 起，到下一个「四个空格 + 模块名 + : function(e, t, n) {」为止。
 * 用边界扫描而不是写死下一个模块名 —— 倒卖商/娱乐两个补丁也会往产物末尾加模块，
 * 顺序随它们的执行情况变化，写死名字会让本补丁在没打过那两个补丁的产物上失配。
 */
function clean_gateway_module_range($bundle, $marker) {
    $start = strpos($bundle, $marker);
    if ($start === false) {
        return null;
    }
    if (!preg_match('/
    [A-Za-z_$][\w$]*: function\(e, t, n\) \{/', $bundle, $matches, PREG_OFFSET_CAPTURE, $start + strlen($marker))) {
        fwrite(STDERR, "Module boundary not found after {$marker}.\n");
        exit(1);
    }
    return [$start, $matches[0][1] + 1];
}

$modulePath = __DIR__ . '/admin-clean-gateway-module.js';
$moduleSource = file_get_contents($modulePath);
if ($moduleSource === false
    || strpos($moduleSource, 'riskgatewaypage: function(e, t, n) {') !== 0
    || substr($moduleSource, -2) !== ",\n") {
    fwrite(STDERR, "Admin cleaning gateway module source is invalid.\n");
    exit(1);
}

$gatewayModule = "    riskgatewaypage: function(e, t, n) {";
$gatewayRange = clean_gateway_module_range($bundle, $gatewayModule);
if ($gatewayRange === null) {
    fwrite(STDERR, "Admin cleaning gateway module not found.\n");
    exit(1);
}
if (strpos(substr($bundle, $gatewayRange[0], $gatewayRange[1] - $gatewayRange[0]), 'SubscribeCleanGatewayPage') === false) {
    $bundle = substr_replace($bundle, $moduleSource, $gatewayRange[0], $gatewayRange[1] - $gatewayRange[0]);
    $changed++;
}

$ruleModule = "    riskrulepage: function(e, t, n) {";
$ruleRange = clean_gateway_module_range($bundle, $ruleModule);
if ($ruleRange !== null) {
    $bundle = substr_replace($bundle, "", $ruleRange[0], $ruleRange[1] - $ruleRange[0]);
    $changed++;
}

// ---- 3. 终检：该走的一点不留，该在的一个不少 --------------------------------
$mustBeGone = [
    'href: "/risk/rule"',
    'riskrulepage',
    'title: "订阅风控网关"',
    '/risk/rule/recompute',
    '手工补丁：风险值列',
    'dataIndex: "risk"',
    'key: "risk_score"',
    'SubscriptionRiskService',
    'applyRiskFilter',
];
foreach ($mustBeGone as $needle) {
    if (strpos($bundle, $needle) !== false) {
        fwrite(STDERR, "Cleaning gateway patch left \"{$needle}\" in the admin bundle.\n");
        exit(1);
    }
}
$mustExist = [
    'title: "订阅清洗网关"',
    'href: "/risk/gateway"',
    'SubscribeCleanGatewayPage',
    '/risk/gateway/export',
    'risktracepage',
    'risksharedippage',
    'resellerpage',
    'rewardpage',
    'component: n("d1ca").default',
];
foreach ($mustExist as $needle) {
    if (strpos($bundle, $needle) === false) {
        fwrite(STDERR, "Cleaning gateway patch broke the admin bundle: missing \"{$needle}\".\n");
        exit(1);
    }
}

if ($changed === 0) {
    fwrite(STDOUT, "Admin cleaning gateway patch already applied.\n");
    exit(0);
}

if (file_put_contents($bundlePath, $bundle) === false) {
    fwrite(STDERR, "Unable to write admin bundle.\n");
    exit(1);
}
fwrite(STDOUT, "Admin cleaning gateway patch applied ({$changed} section(s)).\n");
