<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SchemaUpgradeService
{
    private const MIGRATIONS = [
        'subscription_schema' => 'subscription_schema_v1',
        'risk_audit_schema' => 'risk_audit_schema_v1',
        'subscribe_gateway_schema' => 'subscribe_gateway_schema_v1',
        'subscribe_access_summary_schema' => 'subscribe_access_summary_schema_v1',
        'subscribe_account_risk_schema' => 'subscribe_account_risk_schema_v1',
        'ip_location_cache_schema' => 'ip_location_cache_schema_v1',
        'ip_location_enrichment_schema' => 'ip_location_enrichment_schema_v1',
        'node_connection_log_schema' => 'node_connection_log_schema_v1',
        'token_history_schema' => 'token_history_schema_v1',
        'password_policy_schema' => 'password_policy_schema_v1',
        'reseller_schema' => 'reseller_schema_v1',
        'reseller_approval_schema' => 'reseller_approval_schema_v1',
        'reseller_shared_subscription_schema' => 'reseller_shared_subscription_schema_v1',
        'oauth_identity_schema' => 'oauth_identity_schema_v1',
        'telegram_binding_schema' => 'telegram_binding_schema_v1',
        'ip_account_link_schema' => 'ip_account_link_schema_v1',
        'balance_log_schema' => 'balance_log_schema_v1',
        'payment_attempt_schema' => 'payment_attempt_schema_v1',
        'order_client_ip_schema' => 'order_client_ip_schema_v1',
        'traffic_reward_schema' => 'traffic_reward_schema_v1',
        'traffic_reward_signed_bytes_schema' => 'traffic_reward_signed_bytes_schema_v1',
        'traffic_reward_native_entrypoint_schema' => 'traffic_reward_native_entrypoint_schema_v1',
        'telegram_login_link_schema' => 'telegram_login_link_schema_v1',
        'telegram_registration_schema' => 'telegram_registration_schema_v1',
        'telegram_registration_invite_schema' => 'telegram_registration_invite_schema_v1',
        'two_factor_schema' => 'two_factor_schema_v1',
        'server_tls_pin_schema' => 'server_tls_pin_schema_v1',
        'dynamic_rate_schema' => 'dynamic_rate_schema_v1'
    ];

    public function run(): array
    {
        $this->ensureMigrationTable();
        $this->guardAgainstLegacySchema();

        $result = [];
        foreach (self::MIGRATIONS as $version => $checksum) {
            $applied = DB::table('v2_schema_migrations')
                ->where('version', $version)
                ->first();

            if ($applied) {
                if ((string) $applied->checksum !== hash('sha256', $checksum)) {
                    throw new RuntimeException("Schema migration checksum mismatch: {$version}");
                }
            }

            $this->apply($version);

            if (!$applied) {
                DB::table('v2_schema_migrations')->insert([
                    'version' => $version,
                    'checksum' => hash('sha256', $checksum),
                    'applied_at' => time()
                ]);
                $result[] = ['version' => $version, 'status' => 'applied'];
            } else {
                $result[] = ['version' => $version, 'status' => 'verified'];
            }
        }

        return $result;
    }

    private function apply(string $version): void
    {
        switch ($version) {
            case 'subscription_schema':
                $this->applySubscriptionSchema();
                return;
            case 'risk_audit_schema':
                $this->applySubscribeAuditSchema();
                return;
            case 'subscribe_gateway_schema':
                $this->applySubscribeGatewaySchema();
                return;
            case 'subscribe_access_summary_schema':
                $this->applySubscribeAccessSummarySchema();
                return;
            case 'subscribe_account_risk_schema':
                $this->applySubscribeAccountRiskSchema();
                return;
            case 'ip_location_cache_schema':
                $this->applyIpLocationCacheSchema();
                return;
            case 'ip_location_enrichment_schema':
                $this->applyIpLocationEnrichmentSchema();
                return;
            case 'node_connection_log_schema':
                $this->applyNodeConnectionLogSchema();
                return;
            case 'token_history_schema':
                $this->applyTokenHistorySchema();
                return;
            case 'password_policy_schema':
                $this->applyPasswordPolicySchema();
                return;
            case 'reseller_schema':
                $this->applyResellerSchema();
                return;
            case 'reseller_approval_schema':
                $this->applyResellerApprovalSchema();
                return;
            case 'reseller_shared_subscription_schema':
                $this->applyResellerSharedSubscriptionSchema();
                return;
            case 'oauth_identity_schema':
                $this->applyOAuthIdentitySchema();
                return;
            case 'telegram_binding_schema':
                $this->applyTelegramBindingSchema();
                return;
            case 'ip_account_link_schema':
                $this->applyIpAccountLinkSchema();
                return;
            case 'balance_log_schema':
                $this->applyBalanceLogSchema();
                return;
            case 'payment_attempt_schema':
                $this->applyPaymentAttemptSchema();
                return;
            case 'order_client_ip_schema':
                $this->applyOrderClientIpSchema();
                return;
            case 'traffic_reward_schema':
                $this->applyTrafficRewardSchema();
                return;
            case 'traffic_reward_signed_bytes_schema':
                $this->applyTrafficRewardSignedBytesSchema();
                return;
            case 'traffic_reward_native_entrypoint_schema':
                $this->applyTrafficRewardNativeEntrypointSchema();
                return;
            case 'telegram_login_link_schema':
                $this->applyTelegramLoginLinkSchema();
                return;
            case 'telegram_registration_schema':
                $this->applyTelegramRegistrationSchema();
                return;
            case 'telegram_registration_invite_schema':
                $this->applyTelegramRegistrationInviteSchema();
                return;
            case 'two_factor_schema':
                $this->applyTwoFactorSchema();
                return;
            case 'server_tls_pin_schema':
                $this->applyServerTlsPinSchema();
                return;
            case 'risk_score_schema':
                $this->applyRiskScoreSchema();
                return;
            case 'dynamic_rate_schema':
                $this->applyDynamicRateSchema();
                return;
        }

        throw new RuntimeException("Unknown schema migration: {$version}");
    }

    /**
     * 动态倍率：时段/节点规则、峰值判定参数、每用户状态台账。
     *
     * 状态表同时承担两件事：判定用的滚动状态（high / burst）与管理页看到的实时台账。
     * 刻意不把滚动状态另存一份到 Redis —— 重启 Redis 不该让「已经持续跑了 4 分钟」
     * 这件事归零；Redis 里只放热路径要用的倍率查找表，丢了顶多回落 1.0，下一轮自动重建。
     */
    private function applyDynamicRateSchema(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_rate_rule` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `scope` varchar(16) NOT NULL DEFAULT 'global',
            `node_type` varchar(24) NOT NULL DEFAULT '',
            `node_id` int(11) NOT NULL DEFAULT '0',
            `weekdays` varchar(32) NOT NULL DEFAULT '1,2,3,4,5,6,7',
            `start_minute` smallint(6) NOT NULL DEFAULT '0',
            `end_minute` smallint(6) NOT NULL DEFAULT '1440',
            `multiplier` decimal(6,3) NOT NULL DEFAULT '1.000',
            `enabled` tinyint(1) NOT NULL DEFAULT '1',
            `remark` varchar(255) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `scope_node` (`scope`,`node_type`,`node_id`),
            KEY `enabled` (`enabled`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'scope' => "varchar(16) NOT NULL DEFAULT 'global'",
            'node_type' => "varchar(24) NOT NULL DEFAULT ''",
            'node_id' => "int(11) NOT NULL DEFAULT '0'",
            'weekdays' => "varchar(32) NOT NULL DEFAULT '1,2,3,4,5,6,7'",
            'start_minute' => "smallint(6) NOT NULL DEFAULT '0'",
            'end_minute' => "smallint(6) NOT NULL DEFAULT '1440'",
            'multiplier' => "decimal(6,3) NOT NULL DEFAULT '1.000'",
            'enabled' => "tinyint(1) NOT NULL DEFAULT '1'",
            'remark' => 'varchar(255) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_rate_rule', $column, $definition);
        }
        // 取规则时永远是 WHERE enabled = 1，再按作用域挑；这张表很小，索引只是兜底。
        $this->ensureIndex('v2_rate_rule', 'scope_node', ['scope', 'node_type', 'node_id']);

        // 峰值判定参数。列名不叫 key/value：key 是 MySQL 保留字，裸写一次就得处处加反引号。
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_rate_setting` (
            `setting_key` varchar(64) NOT NULL,
            `setting_value` varchar(255) NOT NULL DEFAULT '',
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'setting_key' => 'varchar(64) NOT NULL',
            'setting_value' => "varchar(255) NOT NULL DEFAULT ''",
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_rate_setting', $column, $definition);
        }

        // 每用户当前状态。high / burst 是判定用的滚动计数，multiplier 是这一轮定下来的
        // 动态倍率，rate_bps 给管理页看实时速率。
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_rate_state` (
            `user_id` int(11) NOT NULL,
            `multiplier` decimal(6,3) NOT NULL DEFAULT '1.000',
            `rate_bps` bigint(20) NOT NULL DEFAULT '0',
            `high` int(11) NOT NULL DEFAULT '0',
            `burst` int(11) NOT NULL DEFAULT '0',
            `state` varchar(16) NOT NULL DEFAULT 'normal',
            `sampled_at` bigint(20) NOT NULL DEFAULT '0',
            `computed_at` bigint(20) NOT NULL DEFAULT '0',
            PRIMARY KEY (`user_id`),
            KEY `multiplier` (`multiplier`),
            KEY `computed_at` (`computed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'multiplier' => "decimal(6,3) NOT NULL DEFAULT '1.000'",
            'rate_bps' => "bigint(20) NOT NULL DEFAULT '0'",
            'high' => "int(11) NOT NULL DEFAULT '0'",
            'burst' => "int(11) NOT NULL DEFAULT '0'",
            'state' => "varchar(16) NOT NULL DEFAULT 'normal'",
            'sampled_at' => "bigint(20) NOT NULL DEFAULT '0'",
            'computed_at' => "bigint(20) NOT NULL DEFAULT '0'"
        ] as $column => $definition) {
            $this->ensureColumn('v2_rate_state', $column, $definition);
        }
        // 管理页默认按「正在叠加」筛，以及「最近一轮有样本」排序。
        $this->ensureIndex('v2_rate_state', 'multiplier', ['multiplier']);
        $this->ensureIndex('v2_rate_state', 'computed_at', ['computed_at']);
    }

    private function ensureMigrationTable(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_schema_migrations` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `version` varchar(80) NOT NULL,
            `checksum` char(64) NOT NULL,
            `applied_at` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `version` (`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function guardAgainstLegacySchema(): void
    {
        if (Schema::hasTable('v2_server') && !Schema::hasTable('v2_server_vmess')) {
            throw new RuntimeException(
                'Legacy database schema detected. Run v2board:update --legacy after backing up the database.'
            );
        }
    }

    private function applySubscriptionSchema(): void
    {
        $this->requireTable('v2_user');
        $this->requireTable('v2_order');
        $this->requireTable('v2_stat_user');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_subscription` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `plan_id` int(11) NOT NULL,
            `token` char(32) NOT NULL,
            `uuid` varchar(36) NOT NULL,
            `node_user_id` bigint(20) NOT NULL,
            `group_id` int(11) DEFAULT NULL,
            `speed_limit` int(11) DEFAULT NULL,
            `device_limit` int(11) DEFAULT NULL,
            `transfer_enable` bigint(20) NOT NULL DEFAULT '0',
            `u` bigint(20) NOT NULL DEFAULT '0',
            `d` bigint(20) NOT NULL DEFAULT '0',
            `status` varchar(16) NOT NULL DEFAULT 'active',
            `is_primary` tinyint(1) NOT NULL DEFAULT '0',
            `auto_renewal` tinyint(1) NOT NULL DEFAULT '0',
            `started_at` bigint(20) DEFAULT NULL,
            `expired_at` bigint(20) DEFAULT NULL,
            `last_reset_at` bigint(20) DEFAULT NULL,
            `next_reset_at` bigint(20) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columns = [
            'user_id' => 'int(11) NOT NULL',
            'plan_id' => 'int(11) NOT NULL',
            'token' => 'char(32) NOT NULL',
            'uuid' => 'varchar(36) NOT NULL',
            'node_user_id' => 'bigint(20) NOT NULL',
            'group_id' => 'int(11) DEFAULT NULL',
            'speed_limit' => 'int(11) DEFAULT NULL',
            'device_limit' => 'int(11) DEFAULT NULL',
            'transfer_enable' => "bigint(20) NOT NULL DEFAULT '0'",
            'u' => "bigint(20) NOT NULL DEFAULT '0'",
            'd' => "bigint(20) NOT NULL DEFAULT '0'",
            'status' => "varchar(16) NOT NULL DEFAULT 'active'",
            'is_primary' => "tinyint(1) NOT NULL DEFAULT '0'",
            'auto_renewal' => "tinyint(1) NOT NULL DEFAULT '0'",
            'started_at' => 'bigint(20) DEFAULT NULL',
            'expired_at' => 'bigint(20) DEFAULT NULL',
            'last_reset_at' => 'bigint(20) DEFAULT NULL',
            'next_reset_at' => 'bigint(20) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ];
        foreach ($columns as $column => $definition) {
            $this->ensureColumn('v2_subscription', $column, $definition);
        }

        $this->ensureIndex('v2_subscription', 'token', ['token'], true);
        $this->ensureIndex('v2_subscription', 'node_user_id', ['node_user_id'], true);
        $this->ensureIndex('v2_subscription', 'user_id_status', ['user_id', 'status']);
        $this->ensureIndex('v2_subscription', 'user_id_primary', ['user_id', 'is_primary']);
        $this->ensureIndex('v2_subscription', 'plan_id', ['plan_id']);

        $this->ensureColumn('v2_order', 'subscription_id', 'bigint(20) DEFAULT NULL');
        $this->ensureColumn('v2_stat_user', 'subscription_id', 'bigint(20) DEFAULT NULL');
        $this->dropIndexIfExists('v2_stat_user', 'server_rate_user_id_record_at');
        $this->ensureIndex(
            'v2_stat_user',
            'server_rate_user_id_subscription_record_at',
            ['server_rate', 'user_id', 'subscription_id', 'record_at'],
            true
        );

        $userColumns = [
            'id', 'plan_id', 'token', 'uuid', 'group_id', 'speed_limit',
            'device_limit', 'transfer_enable', 'u', 'd', 'expired_at',
            'auto_renewal', 'created_at', 'updated_at'
        ];
        foreach ($userColumns as $column) {
            if (!Schema::hasColumn('v2_user', $column)) {
                throw new RuntimeException("Required column v2_user.{$column} is missing.");
            }
        }

        DB::statement("INSERT INTO `v2_subscription`
            (`user_id`,`plan_id`,`token`,`uuid`,`node_user_id`,`group_id`,`speed_limit`,`device_limit`,
             `transfer_enable`,`u`,`d`,`status`,`is_primary`,`auto_renewal`,`started_at`,`expired_at`,
             `created_at`,`updated_at`)
            SELECT u.`id`, u.`plan_id`, u.`token`, u.`uuid`, 2000000000 + u.`id`, u.`group_id`,
                u.`speed_limit`, u.`device_limit`, u.`transfer_enable`, u.`u`, u.`d`,
                IF(u.`expired_at` IS NULL OR u.`expired_at` = 0 OR u.`expired_at` >= UNIX_TIMESTAMP(),
                    'active', 'expired'), 1, u.`auto_renewal`, u.`created_at`, NULLIF(u.`expired_at`, 0),
                u.`created_at`, u.`updated_at`
            FROM `v2_user` u
            WHERE u.`plan_id` IS NOT NULL
              AND NOT EXISTS (
                  SELECT 1 FROM `v2_subscription` s WHERE s.`user_id` = u.`id`
              )");

        // v2_user 是主订阅的镜像，但流量在很长一段时间里只累加到 v2_subscription，
        // 没有回写 v2_user（见 TrafficUpdate）。每分钟那次回写只覆盖当轮真的有流量上报的
        // 订阅，Redis 队列为空时命令直接返回，所以闲置订阅的历史偏差永远追不平，
        // 必须在升级时一次性对齐。条件写在 WHERE 里，已对齐的行不会被更新，可反复执行。
        DB::statement("UPDATE `v2_user` u
            JOIN `v2_subscription` s ON s.`user_id` = u.`id` AND s.`is_primary` = 1
            SET u.`u` = s.`u`, u.`d` = s.`d`
            WHERE u.`u` <> s.`u` OR u.`d` <> s.`d`");
    }

    /**
     * 迁移版本名沿用历史的 risk_audit_schema（改名会让已升级的库重跑一遍，
     * 校验和也对不上），方法名按现在的职责叫「订阅拉取审计」。
     * 同版本里曾经建过 30 天风险周期账本 v2_subscription_risk_cycle，
     * 判定引擎删除后这里不再建表；已经存在的库保留该表，不做 DROP。
     */
    private function applySubscribeAuditSchema(): void
    {
        $this->requireTable('v2_user');
        $this->requireTable('v2_subscription');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_subscribe_request_log` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `subscription_id` bigint(20) DEFAULT NULL,
            `user_agent` varchar(1000) NOT NULL,
            `ua_hash` char(64) NOT NULL,
            `request_ip` varchar(45) NOT NULL,
            `requested_at` bigint(20) NOT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'user_id' => 'int(11) NOT NULL',
            'subscription_id' => 'bigint(20) DEFAULT NULL',
            'user_agent' => 'varchar(1000) NOT NULL',
            'ua_hash' => 'char(64) NOT NULL',
            'request_ip' => 'varchar(45) NOT NULL',
            'requested_at' => 'bigint(20) NOT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_subscribe_request_log', $column, $definition);
        }
        $this->ensureIndex('v2_subscribe_request_log', 'user_subscription_requested_at', ['user_id', 'subscription_id', 'requested_at']);
        $this->ensureIndex('v2_subscribe_request_log', 'subscription_requested_at', ['subscription_id', 'requested_at']);
        $this->ensureIndex('v2_subscribe_request_log', 'subscription_ua_requested_at', ['subscription_id', 'ua_hash', 'requested_at']);
        // 上面三个索引里 requested_at 都不是前导列，按保留期删除会全表扫描。
        $this->ensureIndex('v2_subscribe_request_log', 'requested_at', ['requested_at']);
        // 管理端订阅溯源页要 GROUP BY user_id 并对 requested_at 取 MIN/MAX。MySQL 的
        // loose index scan 要求 GROUP BY 列是索引最左前缀、且聚合列紧随其后；
        // user_subscription_requested_at 中间夹了 subscription_id 用不上，会退化成整索引扫描。
        $this->ensureIndex('v2_subscribe_request_log', 'user_requested_at', ['user_id', 'requested_at']);
        // 以上五个索引里 request_ip 一次都没出现，任何「按 IP 聚合」都只能整索引/全表扫描
        // ——这就是 RiskTraceController::history() 里那句「刻意不含 distinct_ip_count」的原因。
        // **刻意不补 request_ip 前导索引**：多账号同 IP 分析改从累积表 v2_ip_account_link 读
        // （见 applyIpAccountLinkSchema），一条按 IP 聚合的查询都不打在这张原始日志上；仓库
        // 里唯一按 IP 过滤原始日志的地方是 UserController::subscribeRequests 的
        // `where('request_ip','like','%'.$kw.'%')`，前导 % 用不上任何索引。这张表是订阅拉取
        // 每次都要 INSERT 的全站最高频写路径，多一个二级索引就是每行多一次索引维护，
        // 而且在已有数百万行的表上跑 ALTER TABLE ADD KEY 本身也是一次与表规模成正比的
        // 在线 DDL。没有查询会用到的索引一律不加。
    }

    private function applySubscribeGatewaySchema(): void
    {
        $this->requireTable('v2_subscribe_request_log');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_subscribe_block_rule` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `scope` varchar(16) NOT NULL,
            `user_id` int(11) DEFAULT NULL,
            `subscription_id` bigint(20) DEFAULT NULL,
            `ip` varchar(45) DEFAULT NULL,
            `user_agent` varchar(1000) DEFAULT NULL,
            `user_agent_hash` char(64) DEFAULT NULL,
            `status` varchar(16) NOT NULL DEFAULT 'active',
            `reason` text DEFAULT NULL,
            `blocked_by` int(11) DEFAULT NULL,
            `blocked_at` bigint(20) DEFAULT NULL,
            `expires_at` bigint(20) DEFAULT NULL,
            `released_by` int(11) DEFAULT NULL,
            `released_at` bigint(20) DEFAULT NULL,
            `release_reason` text DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'scope' => 'varchar(16) NOT NULL',
            'user_id' => 'int(11) DEFAULT NULL',
            'subscription_id' => 'bigint(20) DEFAULT NULL',
            'ip' => 'varchar(45) DEFAULT NULL',
            'user_agent' => 'varchar(1000) DEFAULT NULL',
            'user_agent_hash' => 'char(64) DEFAULT NULL',
            'status' => "varchar(16) NOT NULL DEFAULT 'active'",
            'reason' => 'text DEFAULT NULL',
            'blocked_by' => 'int(11) DEFAULT NULL',
            'blocked_at' => 'bigint(20) DEFAULT NULL',
            'expires_at' => 'bigint(20) DEFAULT NULL',
            'released_by' => 'int(11) DEFAULT NULL',
            'released_at' => 'bigint(20) DEFAULT NULL',
            'release_reason' => 'text DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_subscribe_block_rule', $column, $definition);
        }
        $this->ensureIndex('v2_subscribe_block_rule', 'scope_subscription_status_expires', ['scope', 'subscription_id', 'status', 'expires_at']);
        $this->ensureIndex('v2_subscribe_block_rule', 'scope_user_status_expires', ['scope', 'user_id', 'status', 'expires_at']);
        $this->ensureIndex('v2_subscribe_block_rule', 'scope_ip_status_expires', ['scope', 'ip', 'status', 'expires_at']);
        $this->ensureIndex('v2_subscribe_block_rule', 'scope_ua_hash_status_expires', ['scope', 'user_agent_hash', 'status', 'expires_at']);
        $this->ensureIndex('v2_subscribe_block_rule', 'status_blocked_at', ['status', 'blocked_at']);

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_subscribe_block_rule_event` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `rule_id` bigint(20) NOT NULL,
            `action` varchar(32) NOT NULL,
            `actor_id` int(11) DEFAULT NULL,
            `reason` text DEFAULT NULL,
            `metadata` text DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'rule_id' => 'bigint(20) NOT NULL',
            'action' => 'varchar(32) NOT NULL',
            'actor_id' => 'int(11) DEFAULT NULL',
            'reason' => 'text DEFAULT NULL',
            'metadata' => 'text DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_subscribe_block_rule_event', $column, $definition);
        }
        $this->ensureIndex('v2_subscribe_block_rule_event', 'rule_created_at', ['rule_id', 'created_at']);
        $this->ensureIndex('v2_subscribe_block_rule_event', 'actor_created_at', ['actor_id', 'created_at']);
        $this->ensureIndex('v2_subscribe_block_rule_event', 'action_created_at', ['action', 'created_at']);

        foreach ([
            'decision' => "varchar(16) NOT NULL DEFAULT 'allowed'",
            'block_rule_id' => 'bigint(20) DEFAULT NULL',
            'block_scope' => 'varchar(16) DEFAULT NULL',
            'block_reason' => 'text DEFAULT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_subscribe_request_log', $column, $definition);
        }
    }

    /**
     * 订阅清洗网关的唯一数据源：一行 = 账号 × 订阅 × IP × User-Agent 四元组。
     *
     * 为什么不直接对 v2_subscribe_request_log 做 GROUP BY：那张表有保留期清理，而列表页
     * 每次打开都要按用户/订阅/IP/UA/次数/时间六个维度筛选排序，直接在原始日志上做等于让
     * 管理端的每次翻页都打一遍全站最高频写入的表。这里把它降维成四元组，筛选列全部落在
     * 索引上。
     *
     * subscription_id 用 0 而不是 NULL 表示「写入时没有订阅」：唯一键里的 NULL 在 MySQL
     * 下不参与去重，ON DUPLICATE KEY UPDATE 永远不触发，同一组合会无限插行。
     *
     * isp / organization / asn 是 IP 库的离线派生结果，由 access:locations 命令按
     * location_resolved_at 增量回填 —— 订阅拉取写路径上不做归属地查询。
     */
    private function applySubscribeAccessSummarySchema(): void
    {
        $this->requireTable('v2_subscribe_request_log');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_subscribe_access_summary` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `subscription_id` bigint(20) NOT NULL DEFAULT '0',
            `request_ip` varchar(45) NOT NULL,
            `ua_hash` char(64) NOT NULL,
            `user_agent` varchar(1000) NOT NULL,
            `hit_count` bigint(20) NOT NULL DEFAULT '0',
            `blocked_count` bigint(20) NOT NULL DEFAULT '0',
            `first_seen_at` bigint(20) NOT NULL,
            `last_seen_at` bigint(20) NOT NULL,
            `recent_audit_id` bigint(20) NOT NULL,
            `recent_decision` varchar(16) NOT NULL DEFAULT 'allowed',
            `isp` varchar(255) DEFAULT NULL,
            `organization` varchar(255) DEFAULT NULL,
            `asn` int(10) unsigned DEFAULT NULL,
            `country_name` varchar(64) DEFAULT NULL,
            `region` varchar(64) DEFAULT NULL,
            `city` varchar(64) DEFAULT NULL,
            `location_status` varchar(16) DEFAULT NULL,
            `location_resolved_at` bigint(20) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'user_id' => 'int(11) NOT NULL',
            'subscription_id' => "bigint(20) NOT NULL DEFAULT '0'",
            'request_ip' => 'varchar(45) NOT NULL',
            'ua_hash' => 'char(64) NOT NULL',
            'user_agent' => 'varchar(1000) NOT NULL',
            'hit_count' => "bigint(20) NOT NULL DEFAULT '0'",
            'blocked_count' => "bigint(20) NOT NULL DEFAULT '0'",
            'first_seen_at' => 'bigint(20) NOT NULL',
            'last_seen_at' => 'bigint(20) NOT NULL',
            'recent_audit_id' => 'bigint(20) NOT NULL',
            'recent_decision' => "varchar(16) NOT NULL DEFAULT 'allowed'",
            'isp' => 'varchar(255) DEFAULT NULL',
            'organization' => 'varchar(255) DEFAULT NULL',
            'asn' => 'int(10) unsigned DEFAULT NULL',
            // 国家/省/市：运营商字段在海外 IP 上经常是空的（全球库很多只给地理不给 ISP），
            // 列表里那格就会写成「未知」，对排查没有帮助。这四列是兜底显示用的。
            'country_name' => 'varchar(64) DEFAULT NULL',
            'region' => 'varchar(64) DEFAULT NULL',
            'city' => 'varchar(64) DEFAULT NULL',
            'location_status' => 'varchar(16) DEFAULT NULL',
            'location_resolved_at' => 'bigint(20) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_subscribe_access_summary', $column, $definition);
        }
        // 唯一键是写入路径 ON DUPLICATE KEY UPDATE 的依据，必须是四元组本身。
        $this->ensureIndex(
            'v2_subscribe_access_summary',
            'user_subscription_ip_ua',
            ['user_id', 'subscription_id', 'request_ip', 'ua_hash'],
            true
        );
        // 列表默认按最近拉取倒序，保留期清理也按这一列删。
        $this->ensureIndex('v2_subscribe_access_summary', 'last_seen_at', ['last_seen_at']);
        // 下面三个是「每个项目都要能筛选」的落点：按订阅、按 IP、按 ASN 精确筛。
        $this->ensureIndex('v2_subscribe_access_summary', 'subscription_id', ['subscription_id']);
        $this->ensureIndex('v2_subscribe_access_summary', 'request_ip', ['request_ip']);
        $this->ensureIndex('v2_subscribe_access_summary', 'asn', ['asn']);
        // 归属地回填命令的取数顺序：WHERE location_resolved_at IS NULL ORDER BY id。
        $this->ensureIndex('v2_subscribe_access_summary', 'location_resolved_at', ['location_resolved_at']);
        // UA 下拉筛选是精确匹配（按 ua_hash），给它一条索引，不然一次 UA 筛选就是全表扫。
        // 这笔开销是明确的：这张表是订阅拉取每次都要写的热路径，多一条二级索引就是每行
        // 多一次索引维护。加它的理由是 UA 筛选在同一个请求里可能被反复用（筛完看、换个
        // UA 再看），而 char(64) 在 utf8mb4 下每条索引项 256 字节 —— 表真的长到几百万行
        // 时值得再评估一次。
        $this->ensureIndex('v2_subscribe_access_summary', 'ua_hash', ['ua_hash']);
    }

    /**
     * 账号级风险台账：一行 = 一个账号。
     *
     * 风险程度 = 该账号的阻断次数 ÷ 该账号的订阅拉取总次数，来源是 access_summary 的
     * SUM(blocked_count) / SUM(hit_count)。
     *
     * 为什么单独落一张表，而不是每次查询现算：
     *   1. 列表要按风险程度排序、按数值筛选。现算就得在 ORDER BY / WHERE 里塞一个
     *      GROUP BY 的子查询，那是全表聚合，翻页一次算一次；
     *   2. 定时的「超阈值提醒」本来就要扫全站账号一遍，结果顺手落库，页面直接读；
     *   3. 「已处理」状态得有地方存 —— 提醒要持续发到有人处理为止，这就需要一行
     *      按账号去重的台账。
     *
     * 刷新由 risk:notify 命令完成（每 15 分钟），它与 access_summary 之间允许最多
     * 一个周期的延迟：风险程度是 180 天窗口上的比值，15 分钟的滞后没有意义。
     */
    private function applySubscribeAccountRiskSchema(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_subscribe_account_risk` (
            `user_id` int(11) NOT NULL,
            `total_count` bigint(20) NOT NULL DEFAULT '0',
            `blocked_count` bigint(20) NOT NULL DEFAULT '0',
            `risk_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
            `first_seen_at` bigint(20) DEFAULT NULL,
            `last_seen_at` bigint(20) DEFAULT NULL,
            `computed_at` bigint(20) NOT NULL DEFAULT '0',
            `notified_at` bigint(20) DEFAULT NULL,
            `notify_count` int(11) NOT NULL DEFAULT '0',
            `handled_at` bigint(20) DEFAULT NULL,
            `handled_by` int(11) DEFAULT NULL,
            `handled_note` varchar(255) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'total_count' => "bigint(20) NOT NULL DEFAULT '0'",
            'blocked_count' => "bigint(20) NOT NULL DEFAULT '0'",
            'risk_percent' => "decimal(5,2) NOT NULL DEFAULT '0.00'",
            'first_seen_at' => 'bigint(20) DEFAULT NULL',
            'last_seen_at' => 'bigint(20) DEFAULT NULL',
            'computed_at' => "bigint(20) NOT NULL DEFAULT '0'",
            'notified_at' => 'bigint(20) DEFAULT NULL',
            'notify_count' => "int(11) NOT NULL DEFAULT '0'",
            'handled_at' => 'bigint(20) DEFAULT NULL',
            'handled_by' => 'int(11) DEFAULT NULL',
            'handled_note' => 'varchar(255) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_subscribe_account_risk', $column, $definition);
        }
        // 列表按风险程度排序 / 按数值筛选走它。
        $this->ensureIndex('v2_subscribe_account_risk', 'risk_percent', ['risk_percent']);
        // 提醒命令的取数顺序：WHERE handled_at IS NULL AND risk_percent >= ? ORDER BY risk_percent DESC。
        $this->ensureIndex('v2_subscribe_account_risk', 'pending', ['handled_at', 'risk_percent']);
    }

    /**
     * 多账号同 IP 关联分析的累积表。
     *
     * 为什么不直接对 v2_subscribe_request_log 做 GROUP BY request_ip：那张表有保留期清理
     * （audit:clean，默认 180 天），过期原始行会被物理删除，而需求要的「历史累积」恰恰是
     * 比保留期更长的记忆。这张表与 v2_subscribe_access_summary 相反 —— 后者严格跟随保留期
     * （页面看到的窗口必须与留存设置一致），本表是跨保留期的长期记忆，刻意不参与
     * purgeExpired()，只在账号注销 / 清空该用户审计记录时被 purgeUser() 带走（否则已注销
     * 账号的真实 IP 会以派生形式残留，与当年漏掉 v2_node_connection_log 是同一类问题）。
     *
     * 粒度取「IP + 账号 + UA 指纹」三元组，一行一个三元组，用 first_seen_at /
     * last_seen_at / hit_count 表达历史：规模由去重后的三元组基数决定，不随时间线性增长
     * （形制照 v2_node_connection_log）。UA 进唯一键而不是另立一张表，是因为需求要的正是
     * 「同一 IP 下的不同账号各自用什么客户端」，一张表就同时喂列表页（GROUP BY request_ip）
     * 与明细页（WHERE request_ip = ? GROUP BY user_id）。
     *
     * 填充由 audit:ip-link 命令离线增量完成，订阅拉取写路径一条 SQL 都没加。
     */
    private function applyIpAccountLinkSchema(): void
    {
        $this->requireTable('v2_subscribe_request_log');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_ip_account_link` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `request_ip` varchar(45) NOT NULL,
            `user_id` int(11) NOT NULL,
            `ua_hash` char(64) NOT NULL,
            `user_agent` varchar(1000) NOT NULL,
            `hit_count` bigint(20) NOT NULL DEFAULT '0',
            `first_seen_at` bigint(20) NOT NULL,
            `last_seen_at` bigint(20) NOT NULL,
            `last_log_id` bigint(20) NOT NULL DEFAULT '0',
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'request_ip' => 'varchar(45) NOT NULL',
            'user_id' => 'int(11) NOT NULL',
            'ua_hash' => 'char(64) NOT NULL',
            'user_agent' => 'varchar(1000) NOT NULL',
            'hit_count' => "bigint(20) NOT NULL DEFAULT '0'",
            'first_seen_at' => 'bigint(20) NOT NULL',
            'last_seen_at' => 'bigint(20) NOT NULL',
            'last_log_id' => "bigint(20) NOT NULL DEFAULT '0'",
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_ip_account_link', $column, $definition);
        }

        // upsert 的冲突键。utf8mb4 下键长 180 + 4 + 256 = 440 字节，远低于 767/3072 上限。
        $this->ensureIndex('v2_ip_account_link', 'ip_user_ua', ['request_ip', 'user_id', 'ua_hash'], true);
        // 列表页那条聚合（GROUP BY request_ip，COUNT(DISTINCT user_id)/SUM(hit_count)/
        // MIN(first_seen_at)/MAX(last_seen_at)，按 last_seen_at 卡时间窗）用的就是这个索引：
        // 五列把聚合要读的列全包住，走 index-only 扫描且天然按 request_ip 有序，分组不落临时表。
        // 唯一键 ip_user_ua 不能替代它 —— 那里没有 hit_count / last_seen_at，分组时要逐行回表。
        $this->ensureIndex('v2_ip_account_link', 'ip_user_seen_hits',
            ['request_ip', 'user_id', 'last_seen_at', 'first_seen_at', 'hit_count']);
        // 按邮箱/UID 筛选时先把账号换成它涉及的 IP 集合，再回到上面那条聚合。
        $this->ensureIndex('v2_ip_account_link', 'user_last_seen', ['user_id', 'last_seen_at']);
        // 时间窗要真的能限制扫描量，就必须有一条以 last_seen_at 为**前导列**的覆盖索引：
        // 上面的 ip_user_seen_hits 里 last_seen_at 是第三列，窗口条件卡在它上面只能过滤、
        // 不能定位，优化器只会做 index-only 全索引扫描（把窗口从 365 天收窄到 7 天不会更快）。
        // 这条索引让窗口变成区间扫描，同时把列表页聚合要读的五列全包住（仍是 index-only）。
        // 代价是扫出来的行不再天然按 request_ip 有序，GROUP BY request_ip 要落临时表 ——
        // 所以两条索引并存、由优化器按窗口的实际选择性挑：窄窗口走这条，宽到接近全表时
        // 走 ip_user_seen_hits 的流式分组。它的前导列同时接管了原来单列 last_seen_at 索引的
        // 两个用途（新鲜度信号 MAX(last_seen_at) 与 prune 的区间删除），所以那条不再单独建。
        $this->ensureIndex('v2_ip_account_link', 'seen_ip_user_hits',
            ['last_seen_at', 'request_ip', 'user_id', 'hit_count', 'first_seen_at']);
        // 增量游标 MAX(last_log_id)：游标从数据本身推导，不另存状态，取值必须是常数级。
        $this->ensureIndex('v2_ip_account_link', 'last_log_id', ['last_log_id']);
    }

    private function applyNodeConnectionLogSchema(): void
    {
        $this->requireTable('v2_user');

        // 订阅拉取 IP 与实际连接节点的 IP 是两回事：前者是客户端下载配置的来源，
        // 记在 v2_subscribe_request_log；后者由节点通过 /UniProxy/alive 上报，此前只
        // 落在 120 秒 TTL 的缓存里，查不到任何历史。这张表把后者持久化。
        //
        // 唯一键用 node_user_id 而不是 subscription_id：老用户没有订阅记录时
        // subscription_id 为 NULL，而 MySQL 的唯一索引不约束 NULL，会写出重复行。
        // node_user_id 是节点上报时实际使用的标识，恒非空。
        //
        // 每个「节点用户 + 节点 + IP」只保留一行，用 first_seen_at / last_seen_at /
        // report_count 表达历史，表的规模由去重后的 IP 数决定而不是随时间线性增长。
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_node_connection_log` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `subscription_id` bigint(20) DEFAULT NULL,
            `node_user_id` bigint(20) NOT NULL,
            `node_type` varchar(16) NOT NULL,
            `node_id` int(11) NOT NULL,
            `node_name_snapshot` varchar(255) NOT NULL DEFAULT '',
            `ip` varchar(45) NOT NULL,
            `report_count` bigint(20) NOT NULL DEFAULT '0',
            `first_seen_at` bigint(20) NOT NULL,
            `last_seen_at` bigint(20) NOT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'user_id' => 'int(11) NOT NULL',
            'subscription_id' => 'bigint(20) DEFAULT NULL',
            'node_user_id' => 'bigint(20) NOT NULL',
            'node_type' => 'varchar(16) NOT NULL',
            'node_id' => 'int(11) NOT NULL',
            'node_name_snapshot' => "varchar(255) NOT NULL DEFAULT ''",
            'ip' => 'varchar(45) NOT NULL',
            'report_count' => "bigint(20) NOT NULL DEFAULT '0'",
            'first_seen_at' => 'bigint(20) NOT NULL',
            'last_seen_at' => 'bigint(20) NOT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_node_connection_log', $column, $definition);
        }

        $this->ensureIndex('v2_node_connection_log', 'node_user_node_ip', ['node_user_id', 'node_type', 'node_id', 'ip'], true);
        $this->ensureIndex('v2_node_connection_log', 'user_id_last_seen_at', ['user_id', 'last_seen_at']);
        $this->ensureIndex('v2_node_connection_log', 'subscription_id_last_seen_at', ['subscription_id', 'last_seen_at']);
        // 同上：last_seen_at 不是前导列，保留期清理需要它单独成索引。
        $this->ensureIndex('v2_node_connection_log', 'last_seen_at', ['last_seen_at']);
    }

    private function applyTokenHistorySchema(): void
    {
        $this->requireTable('v2_user');
        $this->requireTable('v2_subscription');

        // 与 applyTokenHistorySchema 同理：判断必须取在 CREATE 之前。
        $freshTable = !Schema::hasTable('v2_subscription_token_history');

        // 订阅 token 被 resetSecret / resetSecurity 原地覆写，改之前不读旧值，全库没有
        // 任何地方留下旧 token，所以这张表是「哪怕 token 被重置也能溯源」的唯一依据。
        //
        // token_hash 是语义主键：反查按它做唯一索引点查，去重也靠它 —— syncUser 会把
        // 同一个值同时写进 v2_user.token 和 v2_subscription.token，按 token 去重才不会
        // 让镜像看起来像两个不同的 token。
        //
        // 归属是 user_id（NOT NULL）；subscription_id 只是来源标注且可空（老用户没有订阅
        // 行），因此绝不能进唯一键 —— MySQL 唯一索引不约束 NULL，会写出重复行。
        //
        // 原值加密存 token_encrypted，仅在管理员显式点「显示」时解密；token_prefix 存前
        // 8 位明文，供部分搜索与解密失败（APP_KEY 变更）时兜底。
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_subscription_token_history` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `token_hash` char(64) NOT NULL,
            `token_prefix` char(8) NOT NULL DEFAULT '',
            `token_encrypted` text DEFAULT NULL,
            `user_id` int(11) NOT NULL,
            `subscription_id` bigint(20) DEFAULT NULL,
            `issued_at` bigint(20) NOT NULL,
            `issued_at_exact` tinyint(1) NOT NULL DEFAULT '1',
            `issued_reason` varchar(32) NOT NULL DEFAULT 'unknown',
            `issued_actor_type` varchar(16) NOT NULL DEFAULT 'unknown',
            `issued_actor_user_id` int(11) DEFAULT NULL,
            `retired_at` bigint(20) DEFAULT NULL,
            `retired_at_exact` tinyint(1) DEFAULT NULL,
            `retired_reason` varchar(32) DEFAULT NULL,
            `retired_actor_type` varchar(16) DEFAULT NULL,
            `retired_actor_user_id` int(11) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'token_hash' => 'char(64) NOT NULL',
            'token_prefix' => "char(8) NOT NULL DEFAULT ''",
            'token_encrypted' => 'text DEFAULT NULL',
            'user_id' => 'int(11) NOT NULL',
            'subscription_id' => 'bigint(20) DEFAULT NULL',
            'issued_at' => 'bigint(20) NOT NULL',
            'issued_at_exact' => "tinyint(1) NOT NULL DEFAULT '1'",
            'issued_reason' => "varchar(32) NOT NULL DEFAULT 'unknown'",
            'issued_actor_type' => "varchar(16) NOT NULL DEFAULT 'unknown'",
            'issued_actor_user_id' => 'int(11) DEFAULT NULL',
            'retired_at' => 'bigint(20) DEFAULT NULL',
            'retired_at_exact' => 'tinyint(1) DEFAULT NULL',
            'retired_reason' => 'varchar(32) DEFAULT NULL',
            'retired_actor_type' => 'varchar(16) DEFAULT NULL',
            'retired_actor_user_id' => 'int(11) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_subscription_token_history', $column, $definition);
        }

        $this->ensureIndex('v2_subscription_token_history', 'token_hash', ['token_hash'], true);
        $this->ensureIndex('v2_subscription_token_history', 'user_id_issued_at', ['user_id', 'issued_at']);
        $this->ensureIndex('v2_subscription_token_history', 'subscription_id_issued_at', ['subscription_id', 'issued_at']);
        $this->ensureIndex('v2_subscription_token_history', 'token_prefix', ['token_prefix']);
        // 刻意不给 retired_at 建索引：本表不做时间清理（保留期恰好就是答案消失的窗口），
        // 而 retired_at IS NULL 选择性太低，MySQL 也用不上。

        if (!$freshTable) {
            return;
        }

        // 种子就是对账。加密必须在 PHP 里做，所以不写单独的种子 SQL，直接跑与夜间命令
        // 同一个方法，只有一条代码路径。历史只能从这一刻起累积，种子行标为不精确。
        (new SubscriptionTokenHistoryService())->reconcile();
    }

    private function applyIpLocationCacheSchema(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_ip_location_cache` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `ip` varchar(45) NOT NULL,
            `ip_version` tinyint(1) NOT NULL,
            `country_code` varchar(8) NOT NULL DEFAULT '',
            `country_name` varchar(128) NOT NULL DEFAULT '',
            `region` varchar(128) NOT NULL DEFAULT '',
            `province` varchar(128) NOT NULL DEFAULT '',
            `city` varchar(128) NOT NULL DEFAULT '',
            `district` varchar(128) NOT NULL DEFAULT '',
            `isp` varchar(128) NOT NULL DEFAULT '',
            `idc_vendor` varchar(128) NOT NULL DEFAULT '',
            `location_key` varchar(384) NOT NULL DEFAULT '',
            `latitude` decimal(10,6) DEFAULT NULL,
            `longitude` decimal(10,6) DEFAULT NULL,
            `source` varchar(64) NOT NULL DEFAULT '',
            `source_version` varchar(96) NOT NULL DEFAULT '',
            `asn` int(10) unsigned DEFAULT NULL,
            `organization` varchar(255) NOT NULL DEFAULT '',
            `network_type` varchar(32) NOT NULL DEFAULT 'unknown',
            `status` varchar(16) NOT NULL DEFAULT 'unknown',
            `lookup_error` varchar(128) NOT NULL DEFAULT '',
            `resolved_at` bigint(20) DEFAULT NULL,
            `expires_at` bigint(20) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'ip' => 'varchar(45) NOT NULL',
            'ip_version' => 'tinyint(1) NOT NULL',
            'country_code' => "varchar(8) NOT NULL DEFAULT ''",
            'country_name' => "varchar(128) NOT NULL DEFAULT ''",
            'region' => "varchar(128) NOT NULL DEFAULT ''",
            'province' => "varchar(128) NOT NULL DEFAULT ''",
            'city' => "varchar(128) NOT NULL DEFAULT ''",
            'district' => "varchar(128) NOT NULL DEFAULT ''",
            'isp' => "varchar(128) NOT NULL DEFAULT ''",
            'idc_vendor' => "varchar(128) NOT NULL DEFAULT ''",
            'location_key' => "varchar(384) NOT NULL DEFAULT ''",
            'latitude' => 'decimal(10,6) DEFAULT NULL',
            'longitude' => 'decimal(10,6) DEFAULT NULL',
            'source' => "varchar(64) NOT NULL DEFAULT ''",
            'source_version' => "varchar(96) NOT NULL DEFAULT ''",
            'asn' => 'int(10) unsigned DEFAULT NULL',
            'organization' => "varchar(255) NOT NULL DEFAULT ''",
            'network_type' => "varchar(32) NOT NULL DEFAULT 'unknown'",
            'status' => "varchar(16) NOT NULL DEFAULT 'unknown'",
            'lookup_error' => "varchar(128) NOT NULL DEFAULT ''",
            'resolved_at' => 'bigint(20) DEFAULT NULL',
            'expires_at' => 'bigint(20) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_ip_location_cache', $column, $definition);
        }
        $this->ensureIndex('v2_ip_location_cache', 'ip', ['ip'], true);
        $this->ensureIndex('v2_ip_location_cache', 'location_status', ['status']);
        $this->ensureIndex('v2_ip_location_cache', 'location_key', ['location_key']);
        $this->ensureIndex('v2_ip_location_cache', 'expires_at', ['expires_at']);

        $this->widenIpLocationCountryCode();
    }

    /**
     * 把 v2_ip_location_cache.country_code 从 varchar(8) 放宽到 varchar(64)。
     *
     * 这一列建窄了：全球 IP 库里 country_code 字段装的是一级行政区名
     * （CALIFORNIA / Bavaria / Guangdong，见 IpLocationService::normalize 里的注释），
     * 8 个字符装不下，写入报 1406。IpLocationService::cache() 把它 catch 掉只记一条
     * warning，所以症状不是报错而是「这些 IP 永远进不了缓存，每次解析都重查 MMDB、
     * 每查一次写一条 warning」——静默的性能与日志问题。
     *
     * ensureColumn 只建不改，所以这里必须显式 MODIFY；只在当前确实更窄时才执行，
     * 避免每次 v2board:update 都做一次无谓的 DDL。
     */
    private function widenIpLocationCountryCode(): void
    {
        if (!Schema::hasColumn('v2_ip_location_cache', 'country_code')) {
            return;
        }
        $column = DB::selectOne("SHOW COLUMNS FROM `v2_ip_location_cache` WHERE `Field` = 'country_code'");
        if ($column === null) {
            return;
        }
        if (preg_match('/^varchar\((\d+)\)$/i', (string)$column->Type, $matches)
            && (int)$matches[1] >= 64) {
            return;
        }

        DB::statement("ALTER TABLE `v2_ip_location_cache` MODIFY `country_code` varchar(64) NOT NULL DEFAULT ''");
    }

    private function applyIpLocationEnrichmentSchema(): void
    {
        $this->requireTable('v2_ip_location_cache');
        foreach ([
            'operator_code' => "varchar(16) NOT NULL DEFAULT ''",
            'connection_type' => "varchar(32) NOT NULL DEFAULT ''",
            'is_residential' => 'tinyint(1) DEFAULT NULL',
            'geo_confidence' => 'decimal(6,4) DEFAULT NULL',
            'accuracy_radius' => 'int(10) unsigned DEFAULT NULL',
            'division_code' => "varchar(32) NOT NULL DEFAULT ''",
            'matched_sources' => 'text DEFAULT NULL',
            'catalog_version' => "varchar(128) NOT NULL DEFAULT ''"
        ] as $column => $definition) {
            $this->ensureColumn('v2_ip_location_cache', $column, $definition);
        }
    }

    private function applyPasswordPolicySchema(): void
    {
        $this->requireTable('v2_user');

        // 与 applyTokenHistorySchema 同理：判断必须取在 ALTER 之前。
        // run() 对每个版本每次部署都无条件重跑 apply()，不门控的话每次 update.sh 都会把
        // 已经重置过密码的用户重新标成待重置。
        $freshColumn = !Schema::hasColumn('v2_user', 'password_reset_required');

        // 默认 0：安装器创建的那个管理员天生合规，不需要额外补救；注册路径显式置 1。
        $this->ensureColumn('v2_user', 'password_reset_required', "tinyint(1) NOT NULL DEFAULT '0'");

        // 不建索引：这一列只会以「按主键读某个用户」的方式访问，且取值只有两种。
        if ($freshColumn) {
            // 一次性回填：现存普通用户的密码全是自己敲的，按策略都要重置。管理员和员工不打扰
            // —— 提醒只出现在用户端，而他们主要用管理端，被提醒了也无处可点。
            DB::table('v2_user')
                ->where('is_admin', 0)
                ->where('is_staff', 0)
                ->update(['password_reset_required' => 1]);
        }

        // 纠偏（每次部署重跑，幂等，必须放在回填之后）：@telegram.invalid 是 OAuth 注册
        // 的保留占位域，这些账号的密码只能是系统生成的 —— register() 拒绝该域名注册，
        // 面板改密码又要求先知道旧密码 —— 却被上面的一次性回填和 OAuth 注册路径早期的
        // stampRequired 误标成「自设密码待重置」。按语义清零，横幅不再对它们撒谎。
        DB::table('v2_user')
            ->where('password_reset_required', 1)
            ->where('email', 'like', '%@telegram.invalid')
            ->update(['password_reset_required' => 0]);
    }

    private function applyOAuthIdentitySchema(): void
    {
        $this->requireTable('v2_user');
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_user_oauth_identity` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `provider` varchar(24) NOT NULL,
            `provider_subject` varchar(191) NOT NULL,
            `provider_tenant` varchar(191) NOT NULL DEFAULT '',
            `provider_email` varchar(191) DEFAULT NULL,
            `provider_username` varchar(191) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'user_id' => 'int(11) NOT NULL',
            'provider' => 'varchar(24) NOT NULL',
            'provider_subject' => 'varchar(191) NOT NULL',
            'provider_tenant' => "varchar(191) NOT NULL DEFAULT ''",
            'provider_email' => 'varchar(191) DEFAULT NULL',
            'provider_username' => 'varchar(191) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_user_oauth_identity', $column, $definition);
        }
        $this->ensureIndex('v2_user_oauth_identity', 'provider_subject_tenant', ['provider', 'provider_subject', 'provider_tenant'], true);
        $this->ensureIndex('v2_user_oauth_identity', 'user_id', ['user_id']);
    }

    private function applyTelegramBindingSchema(): void
    {
        $this->requireTable('v2_user');
        $this->requireTable('v2_subscription');
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_telegram_subscription_binding` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `subscription_id` bigint(20) NOT NULL,
            `subscription_token_hash` char(64) NOT NULL,
            `telegram_user_id` bigint(20) NOT NULL,
            `telegram_username` varchar(191) NOT NULL,
            `chat_id` bigint(20) NOT NULL,
            `status` varchar(16) NOT NULL DEFAULT 'active',
            `invalid_reason` varchar(255) DEFAULT NULL,
            `invite_link` varchar(255) DEFAULT NULL,
            `invite_link_expires_at` int(11) DEFAULT NULL,
            `bound_at` int(11) NOT NULL,
            `last_checked_at` int(11) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'user_id' => 'int(11) NOT NULL',
            'subscription_id' => 'bigint(20) NOT NULL',
            'subscription_token_hash' => 'char(64) NOT NULL',
            'telegram_user_id' => 'bigint(20) NOT NULL',
            'telegram_username' => 'varchar(191) NOT NULL',
            'chat_id' => 'bigint(20) NOT NULL',
            'status' => "varchar(16) NOT NULL DEFAULT 'active'",
            'invalid_reason' => 'varchar(255) DEFAULT NULL',
            // 一次性入群链接及其失效时间。ensureColumn 幂等，老库升级时补列即可，
            // 不需要新的 migration key。
            'invite_link' => 'varchar(255) DEFAULT NULL',
            'invite_link_expires_at' => 'int(11) DEFAULT NULL',
            'bound_at' => 'int(11) NOT NULL',
            'last_checked_at' => 'int(11) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_telegram_subscription_binding', $column, $definition);
        }
        $this->ensureUniqueIndex('v2_telegram_subscription_binding', 'user_chat', ['user_id', 'chat_id']);
        $this->ensureUniqueIndex('v2_telegram_subscription_binding', 'telegram_chat', ['telegram_user_id', 'chat_id']);
        $this->ensureIndex('v2_telegram_subscription_binding', 'subscription_status', ['subscription_id', 'status']);
        $this->ensureIndex('v2_telegram_subscription_binding', 'status_checked', ['status', 'last_checked_at']);
    }

    private function applyResellerSchema(): void
    {
        $this->requireTable('v2_user');
        $this->requireTable('v2_plan');
        $this->requireTable('v2_order');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_reseller_account` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `email` varchar(128) NOT NULL,
            `password` varchar(255) NOT NULL,
            `store_slug` varchar(32) NOT NULL,
            `store_name` varchar(128) NOT NULL,
            `store_description` text DEFAULT NULL,
            `status` varchar(16) NOT NULL DEFAULT 'pending',
            `last_login_at` bigint(20) DEFAULT NULL,
            `last_login_ip` varchar(45) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_reseller_plan_template` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `base_plan_id` int(11) NOT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT '0',
            `sort` int(11) NOT NULL DEFAULT '0',
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_reseller_plan` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `reseller_id` bigint(20) unsigned NOT NULL,
            `base_plan_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `content` text DEFAULT NULL,
            `month_price` int(11) DEFAULT NULL,
            `quarter_price` int(11) DEFAULT NULL,
            `half_year_price` int(11) DEFAULT NULL,
            `year_price` int(11) DEFAULT NULL,
            `two_year_price` int(11) DEFAULT NULL,
            `three_year_price` int(11) DEFAULT NULL,
            `onetime_price` int(11) DEFAULT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT '1',
            `sort` int(11) NOT NULL DEFAULT '0',
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_reseller_payment` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `reseller_id` bigint(20) unsigned NOT NULL,
            `uuid` char(32) NOT NULL,
            `driver` varchar(64) NOT NULL,
            `name` varchar(255) NOT NULL,
            `config_encrypted` text NOT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT '0',
            `sort` int(11) NOT NULL DEFAULT '0',
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_reseller_customer` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `reseller_id` bigint(20) unsigned NOT NULL,
            `user_id` int(11) NOT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_reseller_order` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `reseller_id` bigint(20) unsigned NOT NULL,
            `reseller_plan_id` bigint(20) unsigned NOT NULL,
            `reseller_payment_id` bigint(20) unsigned DEFAULT NULL,
            `platform_order_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `period` varchar(32) NOT NULL,
            `amount_snapshot` int(11) NOT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'v2_reseller_account' => [
                'email' => 'varchar(128) NOT NULL', 'password' => 'varchar(255) NOT NULL',
                'store_slug' => 'varchar(32) NOT NULL', 'store_name' => 'varchar(128) NOT NULL',
                'store_description' => 'text DEFAULT NULL', 'status' => "varchar(16) NOT NULL DEFAULT 'pending'",
                'last_login_at' => 'bigint(20) DEFAULT NULL', 'last_login_ip' => 'varchar(45) DEFAULT NULL',
                'created_at' => 'int(11) NOT NULL', 'updated_at' => 'int(11) NOT NULL'
            ],
            'v2_reseller_plan_template' => [
                'base_plan_id' => 'int(11) NOT NULL', 'enabled' => "tinyint(1) NOT NULL DEFAULT '0'",
                'sort' => "int(11) NOT NULL DEFAULT '0'", 'created_at' => 'int(11) NOT NULL', 'updated_at' => 'int(11) NOT NULL'
            ],
            'v2_reseller_plan' => [
                'reseller_id' => 'bigint(20) unsigned NOT NULL', 'base_plan_id' => 'int(11) NOT NULL',
                'name' => 'varchar(255) NOT NULL', 'content' => 'text DEFAULT NULL',
                'month_price' => 'int(11) DEFAULT NULL', 'quarter_price' => 'int(11) DEFAULT NULL',
                'half_year_price' => 'int(11) DEFAULT NULL', 'year_price' => 'int(11) DEFAULT NULL',
                'two_year_price' => 'int(11) DEFAULT NULL', 'three_year_price' => 'int(11) DEFAULT NULL',
                'onetime_price' => 'int(11) DEFAULT NULL', 'enabled' => "tinyint(1) NOT NULL DEFAULT '1'",
                'sort' => "int(11) NOT NULL DEFAULT '0'", 'created_at' => 'int(11) NOT NULL', 'updated_at' => 'int(11) NOT NULL'
            ],
            'v2_reseller_payment' => [
                'reseller_id' => 'bigint(20) unsigned NOT NULL', 'uuid' => 'char(32) NOT NULL',
                'driver' => 'varchar(64) NOT NULL', 'name' => 'varchar(255) NOT NULL',
                'config_encrypted' => 'text NOT NULL', 'enabled' => "tinyint(1) NOT NULL DEFAULT '0'",
                'sort' => "int(11) NOT NULL DEFAULT '0'", 'created_at' => 'int(11) NOT NULL', 'updated_at' => 'int(11) NOT NULL'
            ],
            'v2_reseller_customer' => [
                'reseller_id' => 'bigint(20) unsigned NOT NULL', 'user_id' => 'int(11) NOT NULL',
                'created_at' => 'int(11) NOT NULL', 'updated_at' => 'int(11) NOT NULL'
            ],
            'v2_reseller_order' => [
                'reseller_id' => 'bigint(20) unsigned NOT NULL', 'reseller_plan_id' => 'bigint(20) unsigned NOT NULL',
                'reseller_payment_id' => 'bigint(20) unsigned DEFAULT NULL', 'platform_order_id' => 'int(11) NOT NULL',
                'user_id' => 'int(11) NOT NULL', 'period' => 'varchar(32) NOT NULL',
                'amount_snapshot' => 'int(11) NOT NULL', 'created_at' => 'int(11) NOT NULL', 'updated_at' => 'int(11) NOT NULL'
            ]
        ] as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $this->ensureColumn($table, $column, $definition);
            }
        }

        $this->ensureIndex('v2_reseller_account', 'email', ['email'], true);
        $this->ensureIndex('v2_reseller_account', 'store_slug', ['store_slug'], true);
        $this->ensureIndex('v2_reseller_account', 'status', ['status']);
        $this->ensureIndex('v2_reseller_plan_template', 'base_plan_id', ['base_plan_id'], true);
        $this->ensureIndex('v2_reseller_plan_template', 'enabled_sort', ['enabled', 'sort']);
        $this->ensureIndex('v2_reseller_plan', 'reseller_enabled_sort', ['reseller_id', 'enabled', 'sort']);
        $this->ensureIndex('v2_reseller_plan', 'base_plan_id', ['base_plan_id']);
        $this->ensureIndex('v2_reseller_payment', 'uuid', ['uuid'], true);
        $this->ensureIndex('v2_reseller_payment', 'reseller_enabled_sort', ['reseller_id', 'enabled', 'sort']);
        $this->ensureIndex('v2_reseller_customer', 'reseller_user', ['reseller_id', 'user_id'], true);
        $this->ensureIndex('v2_reseller_customer', 'user_id', ['user_id']);
        $this->ensureIndex('v2_reseller_order', 'platform_order_id', ['platform_order_id'], true);
        $this->ensureIndex('v2_reseller_order', 'reseller_user_created', ['reseller_id', 'user_id', 'created_at']);
        $this->ensureIndex('v2_reseller_order', 'reseller_plan', ['reseller_id', 'reseller_plan_id']);
    }

    private function applyResellerApprovalSchema(): void
    {
        $this->requireTable('v2_reseller_account');

        foreach ([
            'reseller_status' => 'varchar(16) DEFAULT NULL',
            'store_status' => 'varchar(16) DEFAULT NULL',
            'reseller_review_reason' => 'varchar(500) DEFAULT NULL',
            'store_review_reason' => 'varchar(500) DEFAULT NULL',
            'reseller_reviewed_by' => 'int(11) DEFAULT NULL',
            'reseller_reviewed_at' => 'int(11) DEFAULT NULL',
            'store_reviewed_by' => 'int(11) DEFAULT NULL',
            'store_reviewed_at' => 'int(11) DEFAULT NULL',
        ] as $column => $definition) {
            $this->ensureColumn('v2_reseller_account', $column, $definition);
        }

        DB::statement("UPDATE `v2_reseller_account`
            SET `reseller_status` = CASE
                WHEN `status` IN ('active', 'suspended', 'rejected', 'pending') THEN `status`
                ELSE 'pending'
            END
            WHERE `reseller_status` IS NULL OR `reseller_status` = ''");
        DB::statement("UPDATE `v2_reseller_account`
            SET `store_status` = CASE
                WHEN `status` IN ('active', 'suspended', 'rejected', 'pending') THEN `status`
                ELSE 'pending'
            END
            WHERE `store_status` IS NULL OR `store_status` = ''");

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_reseller_review_log` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `reseller_id` bigint(20) unsigned NOT NULL,
            `target_type` varchar(16) NOT NULL,
            `from_status` varchar(16) DEFAULT NULL,
            `to_status` varchar(16) NOT NULL,
            `reason` varchar(500) DEFAULT NULL,
            `operator_id` int(11) NOT NULL,
            `created_at` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            KEY `reseller_target_created` (`reseller_id`,`target_type`,`created_at`),
            KEY `operator_created` (`operator_id`,`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->ensureIndex('v2_reseller_account', 'reseller_status', ['reseller_status']);
        $this->ensureIndex('v2_reseller_account', 'store_status', ['store_status']);
        $this->ensureIndex('v2_reseller_review_log', 'reseller_target_created', ['reseller_id', 'target_type', 'created_at']);
        $this->ensureIndex('v2_reseller_review_log', 'operator_created', ['operator_id', 'created_at']);
    }

    private function applyResellerSharedSubscriptionSchema(): void
    {
        $this->requireTable('v2_reseller_plan');
        $this->requireTable('v2_reseller_order');
        $this->requireTable('v2_subscription');
        $this->requireTable('v2_user');

        $this->ensureColumn('v2_reseller_plan', 'shared_member_limit', "int(11) unsigned NOT NULL DEFAULT '1'");
        $this->ensureColumn('v2_reseller_order', 'shared_subscription_id', 'bigint(20) unsigned DEFAULT NULL');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_reseller_shared_subscription` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `reseller_id` bigint(20) unsigned NOT NULL,
            `reseller_plan_id` bigint(20) unsigned NOT NULL,
            `subscription_id` bigint(20) unsigned NOT NULL,
            `owner_user_id` int(11) NOT NULL,
            `member_limit` int(11) unsigned NOT NULL DEFAULT '1',
            `member_count` int(11) unsigned NOT NULL DEFAULT '1',
            `status` varchar(16) NOT NULL DEFAULT 'active',
            `created_order_id` int(11) NOT NULL,
            `last_order_id` int(11) DEFAULT NULL,
            `suspended_reason` varchar(500) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_reseller_shared_subscription_member` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `reseller_id` bigint(20) unsigned NOT NULL,
            `shared_subscription_id` bigint(20) unsigned NOT NULL,
            `user_id` int(11) NOT NULL,
            `role` varchar(16) NOT NULL DEFAULT 'member',
            `status` varchar(16) NOT NULL DEFAULT 'active',
            `joined_at` int(11) DEFAULT NULL,
            `removed_at` int(11) DEFAULT NULL,
            `removed_by_user_id` int(11) DEFAULT NULL,
            `remove_reason` varchar(500) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_reseller_shared_invitation` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `reseller_id` bigint(20) unsigned NOT NULL,
            `shared_subscription_id` bigint(20) unsigned NOT NULL,
            `email` varchar(128) NOT NULL,
            `token_hash` char(64) NOT NULL,
            `created_by_user_id` int(11) NOT NULL,
            `expires_at` int(11) NOT NULL,
            `accepted_by_user_id` int(11) DEFAULT NULL,
            `accepted_at` int(11) DEFAULT NULL,
            `revoked_at` int(11) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->ensureIndex('v2_reseller_plan', 'shared_member_limit', ['shared_member_limit']);
        $this->ensureIndex('v2_reseller_order', 'shared_subscription_id', ['shared_subscription_id']);
        $this->ensureIndex('v2_reseller_shared_subscription', 'subscription_id', ['subscription_id'], true);
        $this->ensureIndex('v2_reseller_shared_subscription', 'reseller_owner_status', ['reseller_id', 'owner_user_id', 'status']);
        $this->ensureIndex('v2_reseller_shared_subscription', 'reseller_status', ['reseller_id', 'status']);
        $this->ensureIndex('v2_reseller_shared_subscription_member', 'shared_user', ['shared_subscription_id', 'user_id'], true);
        $this->ensureIndex('v2_reseller_shared_subscription_member', 'user_status', ['user_id', 'status']);
        $this->ensureIndex('v2_reseller_shared_subscription_member', 'reseller_status', ['reseller_id', 'status']);
        $this->ensureIndex('v2_reseller_shared_invitation', 'token_hash', ['token_hash'], true);
        $this->ensureIndex('v2_reseller_shared_invitation', 'shared_status', ['shared_subscription_id', 'revoked_at', 'expires_at']);
        $this->ensureIndex('v2_reseller_shared_invitation', 'reseller_email', ['reseller_id', 'email']);
    }

    private function applyBalanceLogSchema(): void
    {
        // 资金流水表：记录每一次 v2_user.balance 变更（充值到账、下单抵扣、取消退款、佣金划转、
        // 礼品卡、续费扣款、返佣入账…）的前后值与来源。事件前钱包只有原地 UPDATE、无任何流水，
        // 损失只能反推 —— 这张表补上审计底座；unique_key 唯一键把「同一笔只入账一次」升级成
        // 数据库不变式（例如同一订单的 order_cancel_refund / deposit 各只能落一行）。
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_balance_log` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `balance_before` int(11) NOT NULL,
            `balance_after` int(11) NOT NULL,
            `amount` int(11) NOT NULL,
            `type` varchar(64) NOT NULL,
            `source_type` varchar(32) DEFAULT NULL,
            `source_id` bigint(20) DEFAULT NULL,
            `unique_key` varchar(128) DEFAULT NULL,
            `remark` varchar(255) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'user_id' => 'int(11) NOT NULL',
            'balance_before' => 'int(11) NOT NULL',
            'balance_after' => 'int(11) NOT NULL',
            'amount' => 'int(11) NOT NULL',
            'type' => 'varchar(64) NOT NULL',
            'source_type' => 'varchar(32) DEFAULT NULL',
            'source_id' => 'bigint(20) DEFAULT NULL',
            'unique_key' => 'varchar(128) DEFAULT NULL',
            'remark' => 'varchar(255) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_balance_log', $column, $definition);
        }

        // 幂等键：NULL 可重复（MySQL 唯一索引允许多个 NULL），带键的入账全局唯一。
        $this->ensureIndex('v2_balance_log', 'uniq_key', ['unique_key'], true);
        // 单用户按时间的流水查询（对账、导出）。
        $this->ensureIndex('v2_balance_log', 'user_created', ['user_id', 'created_at']);
        // 按类型筛选（如只看所有取消退款）。
        $this->ensureIndex('v2_balance_log', 'type_created', ['type', 'created_at']);
        // 按来源反查（如「这张订单退过几次款」）。
        $this->ensureIndex('v2_balance_log', 'source', ['source_type', 'source_id']);
    }

    private function applyOrderClientIpSchema(): void
    {
        $this->requireTable('v2_order');
        $this->ensureColumn('v2_order', 'client_ip', 'varchar(45) DEFAULT NULL');
    }


    private function applyPaymentAttemptSchema(): void
    {
        $this->requireTable('v2_order');
        $this->requireTable('v2_payment');
        $freshTable = !Schema::hasTable('v2_payment_attempt');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_payment_attempt` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `order_id` int(11) NOT NULL,
            `payment_id` int(11) NOT NULL,
            `payment_uuid` char(32) NOT NULL,
            `driver` varchar(64) NOT NULL,
            `attempt_no` char(32) NOT NULL,
            `order_amount_cents` int(11) NOT NULL,
            `gateway_amount_minor` bigint(20) DEFAULT NULL,
            `gateway_currency` varchar(12) DEFAULT NULL,
            `provider_reference` varchar(128) DEFAULT NULL,
            `gateway_transaction_id` varchar(255) DEFAULT NULL,
            `gateway_transaction_hash` char(64) DEFAULT NULL,
            `status` enum('initializing','pending','paid','failed','invalidated') NOT NULL,
            `failure_reason` varchar(255) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'order_id' => 'int(11) NOT NULL',
            'payment_id' => 'int(11) NOT NULL',
            'payment_uuid' => 'char(32) NOT NULL',
            'driver' => 'varchar(64) NOT NULL',
            'attempt_no' => 'char(32) NOT NULL',
            'order_amount_cents' => 'int(11) NOT NULL',
            'gateway_amount_minor' => 'bigint(20) DEFAULT NULL',
            'gateway_currency' => 'varchar(12) DEFAULT NULL',
            'provider_reference' => 'varchar(128) DEFAULT NULL',
            'gateway_transaction_id' => 'varchar(255) DEFAULT NULL',
            'gateway_transaction_hash' => 'char(64) DEFAULT NULL',
            'status' => "enum('initializing','pending','paid','failed','invalidated') NOT NULL",
            'failure_reason' => 'varchar(255) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_payment_attempt', $column, $definition);
        }

        $this->ensureIndex('v2_payment_attempt', 'uniq_order', ['order_id'], true);
        $this->ensureIndex('v2_payment_attempt', 'uniq_attempt_no', ['attempt_no'], true);
        $this->ensureIndex('v2_payment_attempt', 'uniq_provider_reference', ['provider_reference'], true);
        $this->ensureIndex('v2_payment_attempt', 'uniq_gateway_transaction', ['payment_id', 'gateway_transaction_hash'], true);
        $this->ensureIndex('v2_payment_attempt', 'payment_status', ['payment_id', 'status']);

        // Existing installations have payment links that cannot be mapped to an
        // immutable attempt. Quarantine the known unsafe drivers on first upgrade.
        if ($freshTable) {
            DB::table('v2_payment')
                ->whereIn('payment', ['BTCPay', 'Coinbase', 'MGate', 'PaytaroQR'])
                ->update(['enable' => 0, 'updated_at' => time()]);
        }
    }

    private function applyTelegramLoginLinkSchema(): void
    {
        $this->requireTable('v2_user');
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_telegram_login_link` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `telegram_chat_id` bigint(20) DEFAULT NULL,
            `token_hash` char(64) NOT NULL,
            `expires_at` int(11) NOT NULL,
            `consumed_at` int(11) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'user_id' => 'int(11) NOT NULL',
            'telegram_chat_id' => 'bigint(20) DEFAULT NULL',
            'token_hash' => 'char(64) NOT NULL',
            'expires_at' => 'int(11) NOT NULL',
            'consumed_at' => 'int(11) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_telegram_login_link', $column, $definition);
        }

        $this->ensureIndex('v2_telegram_login_link', 'uniq_user', ['user_id'], true);
        $this->ensureIndex('v2_telegram_login_link', 'uniq_telegram_chat', ['telegram_chat_id'], true);
        $this->ensureIndex('v2_telegram_login_link', 'uniq_token_hash', ['token_hash'], true);
        $this->ensureIndex('v2_telegram_login_link', 'expires_at', ['expires_at']);
    }

    private function applyTelegramRegistrationSchema(): void
    {
        $this->requireTable('v2_user');

        // 账号与 Telegram 的绑定关系从「代码判断」升级为「数据库约束」：一个 Telegram
        // 只能对一个账号。存量里语义为「未绑定」的 0 与空串先归一成 NULL —— 可空唯一列
        // 允许多个 NULL，但不允许多个 0/''，不归一会让下面的 ALTER 直接失败。
        DB::table('v2_user')->where('telegram_id', 0)->update(['telegram_id' => null]);
        DB::statement("UPDATE `v2_user` SET `telegram_id` = NULL WHERE `telegram_id` = ''");

        // 真有重复值时不能带着脏数据建唯一索引，也不能静默跳过（这层约束正是并发
        // 抢注的兜底），把冲突值报出来让人工处理后重跑。
        $duplicates = DB::table('v2_user')
            ->select('telegram_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('telegram_id')
            ->groupBy('telegram_id')
            ->having('total', '>', 1)
            ->limit(5)
            ->get();
        if ($duplicates->isNotEmpty()) {
            $list = [];
            foreach ($duplicates as $row) {
                $list[] = $row->telegram_id . '（' . $row->total . ' 个账号）';
            }
            throw new RuntimeException(
                'v2_user.telegram_id 存在重复值，无法建立唯一索引：' . implode('、', $list)
                . '。请先人工合并或解绑这些账号，再重新执行升级。'
            );
        }
        $this->ensureUniqueIndex('v2_user', 'uniq_telegram_id', ['telegram_id']);

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_telegram_registration` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `telegram_id` bigint(20) NOT NULL,
            `telegram_username` varchar(64) DEFAULT NULL,
            `email` varchar(64) NOT NULL,
            `code_hash` char(64) DEFAULT NULL,
            `status` tinyint(4) NOT NULL DEFAULT '0',
            `attempts` tinyint(4) NOT NULL DEFAULT '0',
            `sent_at` int(11) DEFAULT NULL,
            `expires_at` int(11) DEFAULT NULL,
            `user_id` int(11) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Telegram 注册申请'");

        foreach ([
            'telegram_id' => 'bigint(20) NOT NULL',
            'telegram_username' => 'varchar(64) DEFAULT NULL',
            'email' => 'varchar(64) NOT NULL',
            'code_hash' => 'char(64) DEFAULT NULL',
            'status' => "tinyint(4) NOT NULL DEFAULT '0'",
            'attempts' => "tinyint(4) NOT NULL DEFAULT '0'",
            'sent_at' => 'int(11) DEFAULT NULL',
            'expires_at' => 'int(11) DEFAULT NULL',
            'invite_code' => 'varchar(64) DEFAULT NULL',
            'user_id' => 'int(11) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_telegram_registration', $column, $definition);
        }

        $this->ensureIndex('v2_telegram_registration', 'telegram_id', ['telegram_id']);
        $this->ensureIndex('v2_telegram_registration', 'email', ['email']);
        $this->ensureIndex('v2_telegram_registration', 'status', ['status']);
    }

    private function applyTelegramRegistrationInviteSchema(): void
    {
        $this->requireTable('v2_telegram_registration');
        // 邀请码在机器人那一步就校验，落在这个字段上带到网页建号时消费
        $this->ensureColumn('v2_telegram_registration', 'invite_code', 'varchar(64) DEFAULT NULL');
    }

    /**
     * 二步验证的两张表只写在 install.sql（全新安装）与 update.sql（仅在 `v2board:update --legacy`
     * 时执行）里，幂等迁移一直漏了它们。于是「在引入二步验证之前安装、且从未跑过 legacy 升级」
     * 的站点跑 update.sh 时永远建不出这两张表，而登录路径每次都会读 v2_user_two_factor：
     * AuthController::performLogin() → TwoFactorService::issueLoginResult() → isEnabled() →
     * UserTwoFactor::where('user_id', ...)->first()，表不存在就是 QueryException（1146），
     * 密码登录、管理员登录、Telegram 免密码登录全部直接 500。这里补上与 install.sql 同一份 DDL，
     * 可反复执行。
     */
    private function applyTwoFactorSchema(): void
    {
        $this->requireTable('v2_user');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_user_two_factor` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `secret_encrypted` text DEFAULT NULL,
            `pending_secret_encrypted` text DEFAULT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT '0',
            `confirmed_at` int(11) DEFAULT NULL,
            `recovery_codes` text DEFAULT NULL,
            `last_used_step` bigint(20) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'user_id' => 'int(11) NOT NULL',
            'secret_encrypted' => 'text DEFAULT NULL',
            'pending_secret_encrypted' => 'text DEFAULT NULL',
            'enabled' => "tinyint(1) NOT NULL DEFAULT '0'",
            'confirmed_at' => 'int(11) DEFAULT NULL',
            'recovery_codes' => 'text DEFAULT NULL',
            'last_used_step' => 'bigint(20) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_user_two_factor', $column, $definition);
        }
        // 一个账号一条记录：登录校验靠这张表判断是否启用了二步验证，重复行会让状态判定失准。
        $this->ensureUniqueIndex('v2_user_two_factor', 'user_id', ['user_id']);

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_two_factor_audit` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `actor_user_id` int(11) DEFAULT NULL,
            `action` varchar(64) NOT NULL,
            `ip` varchar(64) DEFAULT NULL,
            `user_agent` varchar(500) DEFAULT NULL,
            `metadata` text DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'user_id' => 'int(11) NOT NULL',
            'actor_user_id' => 'int(11) DEFAULT NULL',
            'action' => 'varchar(64) NOT NULL',
            'ip' => 'varchar(64) DEFAULT NULL',
            'user_agent' => 'varchar(500) DEFAULT NULL',
            'metadata' => 'text DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_two_factor_audit', $column, $definition);
        }
        $this->ensureIndex('v2_two_factor_audit', 'user_id', ['user_id']);
        $this->ensureIndex('v2_two_factor_audit', 'action', ['action']);
    }

    /**
     * 同一类遗漏：这两个列也只在 install.sql / update.sql 里。少了它们，AnyTLS 节点保存
     * （Admin\Server\AnyTLSController::save 带 pinned_peer_cert_sha256）与 V2node 的
     * trusted_x_forwarded_for 写入都会以「未知列 1054」报 500。表不存在的老库直接跳过。
     */
    private function applyServerTlsPinSchema(): void
    {
        if (Schema::hasTable('v2_server_anytls')) {
            $this->ensureColumn(
                'v2_server_anytls',
                'pinned_peer_cert_sha256',
                "varchar(128) DEFAULT NULL COMMENT 'TLS leaf cert SHA256 hex'"
            );
        }

        if (Schema::hasTable('v2_server_v2node')) {
            $this->ensureColumn(
                'v2_server_v2node',
                'trusted_x_forwarded_for',
                "varchar(255) DEFAULT NULL COMMENT '信任的x-forwarded-for头部'"
            );
        }
    }

    private function requireTable(string $table): void
    {
        if (!Schema::hasTable($table)) {
            throw new RuntimeException("Required table {$table} is missing.");
        }
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        if (!Schema::hasColumn($table, $column)) {
            DB::statement("ALTER TABLE `{$table}` ADD `{$column}` {$definition}");
        }
    }

    private function ensureIndex(string $table, string $name, array $columns, bool $unique = false): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        $columnList = implode('`,`', $columns);
        $type = $unique ? 'ADD UNIQUE KEY' : 'ADD KEY';
        DB::statement("ALTER TABLE `{$table}` {$type} `{$name}` (`{$columnList}`)");
    }

    private function ensureUniqueIndex(string $table, string $name, array $columns): void
    {
        foreach (DB::select("SHOW INDEX FROM `{$table}`") as $index) {
            if ((string)$index->Key_name !== $name) {
                continue;
            }
            if ((int)$index->Non_unique === 0) {
                return;
            }
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
            break;
        }
        $this->ensureIndex($table, $name, $columns, true);
    }

    private function ensureNonUniqueIndex(string $table, string $name, array $columns): void
    {
        foreach (DB::select("SHOW INDEX FROM `{$table}`") as $index) {
            if ((string)$index->Key_name === $name && (int)$index->Non_unique === 0) {
                DB::statement('ALTER TABLE ' . $table . ' DROP INDEX ' . $name);
                break;
            }
        }
        $this->ensureIndex($table, $name, $columns, false);
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        foreach (DB::select("SHOW INDEX FROM `{$table}`") as $index) {
            if ((string) $index->Key_name === $name) {
                return true;
            }
        }
        return false;
    }

    private function applyTrafficRewardSchema(): void
    {
        $this->requireTable('v2_user');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_traffic_reward_log` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `subscription_id` bigint(20) NOT NULL,
            `source` varchar(32) NOT NULL COMMENT 'checkin|game',
            `entrypoint` varchar(128) NOT NULL COMMENT 'web|telegram|telegram_group|telegram-native',
            `reward_bytes` bigint(20) NOT NULL DEFAULT '0',
            `unique_key` varchar(128) NOT NULL,
            `metadata` json DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_key` (`unique_key`),
            KEY `user_id` (`user_id`),
            KEY `subscription_id` (`subscription_id`),
            KEY `source_created` (`source`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_daily_checkin` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `subscription_id` bigint(20) NOT NULL,
            `checkin_date` date NOT NULL,
            `reward_bytes` bigint(20) NOT NULL DEFAULT '0',
            `streak_days` int(11) NOT NULL DEFAULT '0',
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `subscription_date` (`subscription_id`, `checkin_date`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_user_game_setting` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `game` varchar(32) NOT NULL,
            `bet_gb` int(11) NOT NULL DEFAULT '1',
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_game` (`user_id`, `game`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function applyTrafficRewardSignedBytesSchema(): void
    {
        $this->requireTable('v2_traffic_reward_log');

        $column = DB::selectOne("SHOW COLUMNS FROM `v2_traffic_reward_log` LIKE 'reward_bytes'");
        if (!$column) {
            throw new RuntimeException('Required column v2_traffic_reward_log.reward_bytes is missing.');
        }

        if (stripos((string)$column->Type, 'unsigned') !== false) {
            DB::statement("ALTER TABLE `v2_traffic_reward_log` MODIFY `reward_bytes` bigint(20) NOT NULL DEFAULT '0'");
        }

        $column = DB::selectOne("SHOW COLUMNS FROM `v2_traffic_reward_log` LIKE 'reward_bytes'");
        if (!$column || stripos((string)$column->Type, 'unsigned') !== false) {
            throw new RuntimeException('v2_traffic_reward_log.reward_bytes must be a signed BIGINT.');
        }
    }

    private function applyTrafficRewardNativeEntrypointSchema(): void
    {
        $this->requireTable('v2_traffic_reward_log');
        $column = DB::selectOne("SHOW COLUMNS FROM `v2_traffic_reward_log` LIKE 'entrypoint'");
        if (!$column) {
            throw new RuntimeException('Required column v2_traffic_reward_log.entrypoint is missing.');
        }
        if (preg_match('/^varchar\\((\\d+)\\)$/', strtolower((string)$column->Type), $match) !== 1) {
            throw new RuntimeException('v2_traffic_reward_log.entrypoint must be a VARCHAR column.');
        }
        if ((int)$match[1] < 128) {
            DB::statement("ALTER TABLE `v2_traffic_reward_log` MODIFY `entrypoint` varchar(128) NOT NULL COMMENT 'web|telegram|telegram_group|telegram-native'");
        }
    }
}
