<?php

/**
 * 管理端「订阅清洗网关」补丁。
 *
 * 1. 用 scripts/admin-risk-clean-module.js 整块替换 riskrulepage 模块：页面改名、规则表加
 *    「权重」列、表单加权重输入、页首加「待处理高风险订阅」区块（每处改动的说明见模块源码）。
 * 2. 侧栏菜单项「风控规则」→「订阅清洗网关」。
 * 3. 用户列表加「风险值」列（可排序）与对应的数值筛选字段。
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

// ---- 1. 模块替换 -----------------------------------------------------------
$modulePath = __DIR__ . '/admin-risk-clean-module.js';
$moduleSource = file_get_contents($modulePath);
if ($moduleSource === false
    || strpos($moduleSource, 'riskrulepage: function(e, t, n)') === false
    || substr($moduleSource, -2) !== ",\n") {
    fwrite(STDERR, "Admin cleaning gateway module source is invalid.\n");
    exit(1);
}

$moduleHead = '    riskrulepage: function(e, t, n) {';
$moduleStart = strpos($bundle, $moduleHead);
$moduleEnd = $moduleStart === false ? false : strpos($bundle, '    risktracepage: function(e, t, n) {', $moduleStart);
if ($moduleStart === false || $moduleEnd === false || $moduleEnd <= $moduleStart) {
    fwrite(STDERR, "Admin risk rule module boundary not found.\n");
    exit(1);
}
// 已经是我们这份源码（包含待处理区块标记）时原样跳过，重复部署不会把模块叠两次。
if (strpos($bundle, '待处理高风险订阅', $moduleStart) === false
    || strpos($bundle, 'risk_score', $moduleStart) === false) {
    $bundle = substr_replace($bundle, $moduleSource, $moduleStart, $moduleEnd - $moduleStart);
    $changed++;
}

// ---- 2. 侧栏菜单改名 -------------------------------------------------------
$menuOld = "                        title: \"风控规则\",\n                        type: \"item\",\n                        href: \"/risk/rule\",";
$menuNew = "                        title: \"订阅清洗网关\",\n                        type: \"item\",\n                        href: \"/risk/rule\",";
if (strpos($bundle, $menuNew) === false) {
    if (strpos($bundle, $menuOld) === false) {
        fwrite(STDERR, "Admin risk menu anchor not found.\n");
        exit(1);
    }
    $bundle = str_replace($menuOld, $menuNew, $bundle);
    $changed++;
}

// ---- 3. 用户列表：风险值列 + 筛选字段 --------------------------------------
// 幂等标记用注释里的「手工补丁：风险值列」：不能拿 risk_score 当标记 —— 上面刚换进去的
// 模块源码里就有这个字符串，会把这一节误判成已打过。
if (strpos($bundle, '手工补丁：风险值列') === false) {
    // 3a. 列：插在「风险」列之后。表头排序走 key，Ant Design 会把它当 columnKey 传给
    //     tableOnChange，后端 UserController::fetch 已支持 sort=risk_score。
    $columnAnchor = "                            title: e && e.reasons && e.reasons.length ? e.reasons.join(\"；\") : \"\"\n"
        . "                        }, r)\n"
        . "                    }\n"
        . "                }, {\n";
    if (strpos($bundle, $columnAnchor) === false) {
        fwrite(STDERR, "Admin user list risk column anchor not found.\n");
        exit(1);
    }
    $columnInsert = "                            title: e && e.reasons && e.reasons.length ? e.reasons.join(\"；\") : \"\"\n"
        . "                        }, r)\n"
        . "                    }\n"
        . "                }, {\n"
        . "                    // 手工补丁：风险值列（订阅清洗网关）。分数与徽标同源，排序由\n"
        . "                    // 服务端按同一表达式完成（sort=risk_score）。\n"
        . "                    title: \"风险值\",\n"
        . "                    key: \"risk_score\",\n"
        . "                    dataIndex: \"risk\",\n"
        . "                    sorter: !0,\n"
        . "                    render: e=>{\n"
        . "                        var score = e && null !== e.score && void 0 !== e.score ? Number(e.score) : null;\n"
        . "                        return null === score || isNaN(score) ? g.a.createElement(\"span\", {\n"
        . "                            className: \"text-muted\"\n"
        . "                        }, \"—\") : g.a.createElement(\"span\", {\n"
        . "                            style: {\n"
        . "                                fontWeight: 600\n"
        . "                            }\n"
        . "                        }, score + \"%\")\n"
        . "                    }\n"
        . "                }, {\n";
    $bundle = str_replace($columnAnchor, $columnInsert, $bundle);
    $changed++;

    // 3b. 筛选字段：插在「风险」筛选之后，条件只给数值比较（后端 applyRiskScoreFilter 的白名单）。
    $filterAnchor = "                        key: \"risk\",\n"
        . "                        title: \"风险\",\n"
        . "                        condition: [\"=\"],\n"
        . "                        type: \"select\",\n"
        . "                        options: [{\n"
        . "                            key: \"疑似内鬼\",\n"
        . "                            value: \"suspicious\"\n"
        . "                        }, {\n"
        . "                            key: \"待观察\",\n"
        . "                            value: \"pending\"\n"
        . "                        }, {\n"
        . "                            key: \"正常\",\n"
        . "                            value: \"normal\"\n"
        . "                        }]\n"
        . "                    }, {\n";
    if (strpos($bundle, $filterAnchor) === false) {
        fwrite(STDERR, "Admin user list risk filter anchor not found.\n");
        exit(1);
    }
    $filterInsert = substr($filterAnchor, 0, -strlen("                    }, {\n"))
        . "                    }, {\n"
        . "                        // 手工补丁：风险值筛选（数值比较，与徽标同一数据源）。\n"
        . "                        key: \"risk_score\",\n"
        . "                        title: \"风险值\",\n"
        . "                        condition: [\">=\", \">\", \"<\", \"<=\", \"=\"]\n"
        . "                    }, {\n";
    $bundle = str_replace($filterAnchor, $filterInsert, $bundle);
    $changed++;
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
