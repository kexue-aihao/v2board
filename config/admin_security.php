<?php

return [
    // Only deployment configuration can exempt the UI deterrent. Authorization and
    // auditing are deliberately not configurable from the browser or site settings.
    'debug_exempt' => env('ADMIN_DEBUG_EXEMPT', false),
    'audit_key' => env('ADMIN_AUDIT_KEY'),
    'archive_disk' => env('ADMIN_AUDIT_ARCHIVE_DISK'),
    'roles' => [
        'super' => '超级管理员',
        'operations' => '运维管理员',
        'finance' => '财务管理员',
        'support' => '客服管理员',
        'marketing' => '运营管理员',
    ],
    'pages' => [
        // The dashboard is the first standalone entry in the original sidebar.
        ['仪表盘', '/dashboard', null, ['super']],
        ['系统配置', '/config/system', '设置', ['super', 'operations']],
        ['支付配置', '/config/payment', '设置', ['super', 'operations']],
        ['主题配置', '/config/theme', '设置', ['super', 'operations']],
        ['节点管理', '/server/manage', '服务器', ['super', 'operations']],
        ['权限组管理', '/server/group', '服务器', ['super', 'operations']],
        ['路由管理', '/server/route', '服务器', ['super', 'operations']],
        ['订阅管理', '/plan', '财务', ['super', 'marketing']],
        ['订单管理', '/order', '财务', ['super', 'finance']],
        ['优惠券管理', '/coupon', '财务', ['super', 'marketing']],
        ['礼品卡管理', '/giftcard', '财务', ['super', 'marketing']],
        ['用户管理', '/user', '用户', ['super']],
        ['公告管理', '/notice', '用户', ['super']],
        ['工单管理', '/ticket', '用户', ['super', 'support']],
        ['知识库管理', '/knowledge', '用户', ['super']],
        ['倒卖商管理', '/reseller', '渠道管理', ['super']],
        ['外部订阅源', '/external', '运营配置', ['super']],
        ['签到与娱乐', '/reward', '运营配置', ['super', 'marketing']],
        ['订阅溯源', '/risk/trace', '风控', ['super', 'operations']],
        ['订阅清洗网关', '/risk/gateway', '风控', ['super', 'operations']],
        ['多账号同 IP', '/risk/shared-ip', '风控', ['super', 'operations']],
        ['队列监控', '/queue', '指标', ['super']],
        ['管理员权限', '/security/administrators', '4A 安全审计', ['super']],
        ['安全审计', '/security/audit', '4A 安全审计', ['super']],
        ['账号安全', '/security/account', '账号', ['super', 'operations', 'finance', 'support', 'marketing']],
    ],
];
