<?php

namespace App\Services;

/** Human-readable actions are signed with new records; old records stay intact. */
class SecurityAuditDescription
{
    private const RESOURCES = [
        'V1\\Admin\\UserController' => '用户资料', 'V1\\Admin\\ConfigController' => '系统配置',
        'V1\\Admin\\PaymentController' => '支付配置', 'V1\\Admin\\ThemeController' => '主题配置',
        'V1\\Admin\\PlanController' => '订阅套餐', 'V1\\Admin\\OrderController' => '订单',
        'V1\\Admin\\CouponController' => '优惠券', 'V1\\Admin\\GiftcardController' => '礼品卡',
        'V1\\Admin\\NoticeController' => '公告', 'V1\\Admin\\KnowledgeController' => '知识库',
        'V1\\Admin\\TicketController' => '工单', 'V1\\Staff\\TicketController' => '工单',
        'V1\\Admin\\RewardController' => '签到与娱乐配置', 'V1\\Admin\\RateController' => '动态倍率配置',
        'V1\\Admin\\ResellerController' => '倒卖商配置', 'V1\\Admin\\ExternalSourceController' => '外部订阅源',
        'V1\\Admin\\RiskTraceController' => '订阅溯源记录', 'V1\\Admin\\RiskSharedIpController' => '多账号同 IP 记录',
        'V1\\Admin\\SubscribeCleanGatewayController' => '订阅清洗网关', 'V1\\Admin\\SystemController' => '系统运行信息',
        'V1\\Admin\\StatController' => '统计数据', 'V1\\Admin\\Server\\ManageController' => '节点',
        'V1\\Admin\\Server\\GroupController' => '权限组', 'V1\\Admin\\Server\\RouteController' => '路由',
        'V1\\Admin\\Server\\TrojanController' => 'Trojan 节点', 'V1\\Admin\\Server\\VmessController' => 'Vmess 节点',
        'V1\\Admin\\Server\\VlessController' => 'Vless 节点', 'V1\\Admin\\Server\\ShadowsocksController' => 'Shadowsocks 节点',
        'V1\\Admin\\Server\\HysteriaController' => 'Hysteria 节点', 'V1\\Admin\\Server\\TuicController' => 'Tuic 节点',
        'V1\\Admin\\Server\\AnyTLSController' => 'AnyTLS 节点', 'V1\\Admin\\Server\\V2nodeController' => 'V2node 节点',
        'V1\\Admin\\SecurityController' => '安全审计', 'V1\\User\\UserController' => '自身账号',
        'V1\\User\\TwoFactorController' => '二步验证', 'V1\\Passport\\AuthController' => '管理员登录',
    ];
    private const VERBS = [
        'fetch' => '查看', 'detail' => '查看详情：', 'save' => '保存', 'update' => '修改',
        'drop' => '删除', 'show' => '切换启用状态：', 'sort' => '调整排序：', 'copy' => '复制',
        'generate' => '批量生成', 'reply' => '回复', 'close' => '关闭', 'export' => '导出',
        'history' => '查看历史：', 'rules' => '查看规则：', 'config' => '查看配置：', 'saveConfig' => '保存配置：',
        'options' => '查看选项：', 'summary' => '查看概览：', 'accounts' => '查看账号：', 'stores' => '查看店铺：',
        'review' => '审核', 'reviewLogs' => '查看审核记录：', 'templates' => '查看模板：', 'saveTemplate' => '保存模板：',
        'paymentDrivers' => '查看支付方式：', 'savePaymentDrivers' => '保存支付方式：', 'orders' => '查看订单：',
        'getStat' => '查看', 'getStatRecord' => '查看历史：', 'getRanking' => '查看排行：', 'getOverride' => '查看概览：',
        'getOrder' => '查看订单统计：', 'getServerLastRank' => '查看昨日节点排行：', 'getServerTodayRank' => '查看今日节点排行：',
        'getUserTodayRank' => '查看今日用户排行：', 'getUserLastRank' => '查看昨日用户排行：', 'getStatUser' => '查看用户统计：',
    ];
    private const ACTIONS = [
        'V1\\Admin\\UserController@resetSecret' => '重置用户订阅链接与 UUID',
        'V1\\Admin\\UserController@resetPassword' => '重置用户密码',
        'V1\\Admin\\UserController@telegramUnbind' => '解绑用户 Telegram 账号',
        'V1\\Admin\\UserController@telegramInfo' => '查看用户 Telegram 绑定',
        'V1\\Admin\\UserController@getUserInfoById' => '查看用户编辑资料',
        'V1\\Admin\\UserController@setInviteUser' => '修改用户邀请人',
        'V1\\Admin\\UserController@setPrimarySubscription' => '修改用户主订阅',
        'V1\\Admin\\UserController@revokeSubscription' => '撤销用户订阅',
        'V1\\Admin\\UserController@subscribeRequests' => '查看用户订阅请求记录',
        'V1\\Admin\\UserController@clearSubscribeAudit' => '清理用户订阅请求记录',
        'V1\\Admin\\UserController@dumpCSV' => '导出用户列表',
        'V1\\Admin\\UserController@sendMail' => '向用户发送邮件',
        'V1\\Admin\\UserController@ban' => '批量停用用户',
        'V1\\Admin\\OrderController@update' => '修改订单佣金状态',
        'V1\\Admin\\PlanController@update' => '修改套餐显示与续费状态',
        'V1\\Admin\\UserController@allDel' => '批量删除用户',
        'V1\\Admin\\UserController@delUser' => '删除用户',
        'V1\\Admin\\UserController@subscriptionCleanup' => '扫描或删除无有效订阅的用户',
        'V1\\Admin\\ConfigController@getEmailTemplate' => '查看邮件模板',
        'V1\\Admin\\ConfigController@getThemeTemplate' => '查看主题模板',
        'V1\\Admin\\ConfigController@testSendMail' => '发送测试邮件',
        'V1\\Admin\\ConfigController@setTelegramWebhook' => '设置 Telegram 回调地址',
        'V1\\Admin\\ThemeController@getThemes' => '查看可用主题',
        'V1\\Admin\\ThemeController@getThemeConfig' => '查看主题配置',
        'V1\\Admin\\ThemeController@saveThemeConfig' => '保存主题配置',
        'V1\\Admin\\PaymentController@getPaymentMethods' => '查看支付方式',
        'V1\\Admin\\PaymentController@getPaymentForm' => '查看支付配置表单',
        'V1\\Admin\\OrderController@paid' => '确认订单支付', 'V1\\Admin\\OrderController@reconcile' => '补单',
        'V1\\Admin\\OrderController@cancel' => '取消订单', 'V1\\Admin\\OrderController@assign' => '分配订单',
        'V1\\Admin\\KnowledgeController@getCategory' => '查看知识库分类',
        'V1\\Admin\\ResellerController@resetPassword' => '重置倒卖商密码',
        'V1\\Admin\\ExternalSourceController@saveSource' => '保存外部订阅源',
        'V1\\Admin\\ExternalSourceController@dropSource' => '删除外部订阅源',
        'V1\\Admin\\ExternalSourceController@refreshSource' => '刷新外部订阅源',
        'V1\\Admin\\RateController@saveRule' => '保存动态倍率时段规则',
        'V1\\Admin\\RateController@dropRule' => '删除动态倍率时段规则',
        'V1\\Admin\\RateController@saveSettings' => '保存动态倍率全局设置',
        'V1\\Admin\\RateController@savePolicy' => '保存动态倍率场景策略',
        'V1\\Admin\\RateController@policyOptions' => '查看动态倍率场景选项',
        'V1\\Admin\\RateController@dropPolicy' => '删除动态倍率场景策略',
        'V1\\Admin\\RateController@previewBinding' => '预览节点倍率策略绑定',
        'V1\\Admin\\RateController@applyBinding' => '修改节点倍率策略绑定',
        'V1\\Admin\\RateController@explain' => '查看订阅倍率计算依据',
        'V1\\Admin\\Server\\ManageController@getNodes' => '查看节点列表',
        'V1\\Admin\\Server\\ManageController@previewHostReplacement' => '预览批量替换节点地址',
        'V1\\Admin\\Server\\ManageController@replaceHost' => '批量替换节点地址',
        'V1\\Admin\\Server\\ManageController@copyNodes' => '批量复制节点',
        'V1\\Admin\\Server\\ManageController@previewRename' => '预览批量修改节点名称',
        'V1\\Admin\\Server\\ManageController@applyRename' => '批量修改节点名称',
        'V1\\Admin\\Server\\ManageController@previewRate' => '预览批量修改节点倍率',
        'V1\\Admin\\Server\\ManageController@applyRate' => '批量修改节点倍率',
        'V1\\Admin\\Server\\ManageController@previewServerPort' => '预览批量修改节点服务端口',
        'V1\\Admin\\Server\\ManageController@applyServerPort' => '批量修改节点服务端口',
        'V1\\Admin\\Server\\ManageController@previewPorts' => '预览批量修改节点端口',
        'V1\\Admin\\Server\\ManageController@applyPorts' => '批量修改节点端口',
        'V1\\Admin\\Server\\ManageController@inspectTlsFields' => '查看节点 TLS 字段',
        'V1\\Admin\\Server\\ManageController@previewTlsFields' => '预览批量修改节点 TLS 字段',
        'V1\\Admin\\Server\\ManageController@applyTlsFields' => '批量修改节点 TLS 字段',
        'V1\\Admin\\Server\\ManageController@deleteNodes' => '批量删除节点',
        'V1\\Admin\\Server\\ManageController@previewProtocolSettings' => '预览批量修改节点协议设置',
        'V1\\Admin\\Server\\ManageController@applyProtocolSettings' => '批量修改节点协议设置',
        'V1\\Admin\\RiskTraceController@lookup' => '查询订阅溯源',
        'V1\\Admin\\RiskTraceController@reveal' => '查看订阅溯源敏感信息',
        'V1\\Admin\\SubscribeCleanGatewayController@block' => '阻断订阅请求',
        'V1\\Admin\\SubscribeCleanGatewayController@release' => '解除订阅请求阻断',
        'V1\\Admin\\SubscribeCleanGatewayController@riskPending' => '查看待处理风险账号',
        'V1\\Admin\\SubscribeCleanGatewayController@handleRisk' => '标记风险账号已处理',
        'V1\\Admin\\SystemController@getSystemStatus' => '查看系统状态',
        'V1\\Admin\\SystemController@getQueueWorkload' => '查看队列负载',
        'V1\\Admin\\SystemController@getQueueStats' => '查看队列统计',
        'V1\\Admin\\SystemController@getSystemLog' => '查看系统日志',
        'V1\\Admin\\SecurityController@bootstrap' => '读取管理员身份与菜单',
        'V1\\Admin\\SecurityController@asset' => '加载管理员页面资源',
        'V1\\Admin\\SecurityController@administrators' => '查看管理员账号',
        'V1\\Admin\\SecurityController@assignRole' => '修改管理员身份',
        'V1\\Admin\\SecurityController@audit' => '查询安全审计记录',
        'V1\\Admin\\SecurityController@auditDetail' => '查看安全审计操作详情',
        'V1\\Admin\\SecurityController@verifyAudit' => '校验安全审计完整性',
        'V1\\Admin\\SecurityController@exportAudit' => '导出安全审计记录',
        'V1\\Admin\\SecurityController@planOptions' => '查看订单套餐选项',
        'V1\\Admin\\SecurityController@groupOptions' => '查看套餐权限组选项',
        'V1\\Admin\\SecurityController@planConfig' => '查看套餐货币设置',
        'V1\\Admin\\SecurityController@logout' => '退出后台登录',
        'V1\\User\\UserController@info' => '查看自身账号信息',
        'V1\\User\\UserController@checkLogin' => '检查登录状态',
        'V1\\User\\UserController@changePassword' => '修改自身密码',
        'V1\\User\\UserController@getActiveSession' => '查看自身登录会话',
        'V1\\User\\UserController@removeActiveSession' => '撤销自身登录会话',
        'V1\\User\\TwoFactorController@status' => '查看二步验证状态',
        'V1\\User\\TwoFactorController@setup' => '开始绑定二步验证器',
        'V1\\User\\TwoFactorController@confirm' => '确认绑定二步验证器',
        'V1\\User\\TwoFactorController@disable' => '关闭二步验证',
        'V1\\User\\TwoFactorController@regenerateRecoveryCodes' => '重新生成二步验证恢复码',
        'V1\\Passport\\AuthController@login' => '管理员登录',
        'V1\\Passport\\AuthController@adminLogin' => '管理员登录',
        'V1\\Passport\\AuthController@adminVerify2fa' => '管理员登录二步验证',
        'V1\\Passport\\AuthController@verify2fa' => '管理员登录二步验证',
        'V1\\Passport\\AuthController@adminSetup2fa' => '管理员首次绑定二步验证器',
        'V1\\Passport\\AuthController@setup2fa' => '管理员首次绑定二步验证器',
        'V1\\Passport\\AuthController@adminConfirmSetup2fa' => '确认管理员首次二步验证绑定',
        'V1\\Passport\\AuthController@confirmSetup2fa' => '确认管理员首次二步验证绑定',
    ];
    private const EVENTS = [
        'authentication.login' => '管理员登录', 'authorization.denied' => '拒绝后台操作',
        'security.bootstrap' => '初始化管理员权限与安全审计', 'administrator.role' => '修改管理员身份',
        'audit.verify' => '校验安全审计完整性', 'audit.export' => '导出安全审计记录', 'audit.archive' => '归档安全审计记录',
        'theme.change' => '保存主题配置', 'reward.rules.read' => '查看签到与娱乐规则', 'reward.rules.change' => '修改签到与娱乐规则',
        'job.queued' => '提交后台任务', 'job.begin' => '开始执行后台任务', 'job.changes' => '记录后台任务数据变更',
        'job.finish' => '完成后台任务', 'job.denied' => '停止执行已失去权限的后台任务',
        'request.begin' => '开始后台操作', 'request.finish' => '完成后台操作', 'business.changes' => '记录业务数据变更',
    ];

    public static function action(string $action): string
    {
        $action = str_replace('App\\Http\\Controllers\\', '', ltrim($action, '\\'));
        if (isset(self::ACTIONS[$action])) return self::ACTIONS[$action];
        [$controller, $method] = array_pad(explode('@', $action, 2), 2, '');
        $subject = self::RESOURCES[$controller] ?? '后台操作';
        if (preg_match('/^V1\\\\Admin\\\\Server\\\\(Trojan|Vmess|Vless|Shadowsocks|Hysteria|Tuic|AnyTLS|V2node)Controller$/', $controller, $match)) $subject = $match[1] . ' 节点';
        if (isset($match[1]) && $method === 'update') return '修改' . $subject . '显示状态';
        return (self::VERBS[$method] ?? '执行') . $subject;
    }

    public static function describe(string $event, array $details = [], string $action = ''): string
    {
        if (!empty($details['business'])) {
            $description = SecurityAuditBusiness::describe($details['business']);
            if (strpos($event, 'job.') === 0) $description = (self::EVENTS[$event] ?? '后台任务') . '：' . $description;
            return $description;
        }
        $action = $details['action'] ?? $action;
        $description = self::EVENTS[$event] ?? '记录后台事件';
        if (in_array($event, ['request.begin', 'request.finish', 'authorization.denied', 'business.changes'], true) && $action) {
            $prefix = ['request.begin' => '开始：', 'request.finish' => '', 'authorization.denied' => '拒绝：', 'business.changes' => '记录数据变更：'][$event];
            $description = $prefix . self::action($action);
        } elseif (strpos($event, 'job.') === 0 && $action) {
            $description .= '：' . self::action($action);
        }
        if ($event === 'administrator.role') {
            $roles = config('admin_security.roles', []);
            $before = $details['before']['role'] ?? null;
            $after = $details['after']['role'] ?? null;
            $description .= '：' . ($roles[$before] ?? '普通用户') . ' → ' . ($roles[$after] ?? '普通用户');
        }
        $target = $details['target_id'] ?? ($details['input']['id'] ?? null);
        if (is_scalar($target) && ctype_digit((string)$target)) $description .= '（ID ' . $target . '）';
        return $description;
    }

    public static function row($row): array
    {
        $data = (array)$row;
        $payload = json_decode($data['payload'], true) ?: [];
        $data['description'] = $payload['description'] ?? self::describe($data['event'], $payload['details'] ?? []);
        $data['role_label'] = config('admin_security.roles.' . ($data['role'] ?? ''), '未认证 / 系统');
        $data['result_label'] = ['success' => '成功', 'failure' => '失败', 'denied' => '拒绝', 'pending' => '执行意图'][$data['result']] ?? '未知';
        $business = $payload['details']['business'] ?? null;
        $data['business'] = $business;
        $data['detail_version'] = $business['version'] ?? 0;
        if ($business) $data['result_label'] = ['success' => '成功', 'failure' => '失败', 'denied' => '拒绝', 'pending' => '结果待确认', 'partial' => '部分完成', 'no_change' => '无变化', 'queued' => '任务已提交'][$business['state'] ?? ''] ?? $data['result_label'];
        return $data;
    }

    public static function legacySearchTerms(string $keyword): array
    {
        $terms = [];
        foreach (self::ACTIONS + self::EVENTS + self::RESOURCES as $key => $label) {
            if (mb_strpos($label, $keyword) !== false) $terms[] = $key;
        }
        foreach (self::VERBS as $method => $label) {
            if (mb_strpos($label, $keyword) !== false) $terms[] = '@' . $method;
        }
        foreach (self::RESOURCES as $controller => $subject) {
            foreach (self::VERBS as $method => $verb) {
                if (mb_strpos($subject, $keyword) === false && mb_strpos($verb, $keyword) === false
                    && mb_strpos($verb . $subject, $keyword) !== false) $terms[] = $controller . '@' . $method;
            }
        }
        return array_unique($terms);
    }

    public static function searchPattern(string $text): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
    }
}
