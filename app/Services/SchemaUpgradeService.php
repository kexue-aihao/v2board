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
        'ip_location_cache_schema' => 'ip_location_cache_schema_v1',
        'node_connection_log_schema' => 'node_connection_log_schema_v1',
        'risk_rule_schema' => 'risk_rule_schema_v1',
        'token_history_schema' => 'token_history_schema_v1',
        'password_policy_schema' => 'password_policy_schema_v1',
        'reseller_schema' => 'reseller_schema_v1',
        'reseller_approval_schema' => 'reseller_approval_schema_v1',
        'reseller_shared_subscription_schema' => 'reseller_shared_subscription_schema_v1',
        'oauth_identity_schema' => 'oauth_identity_schema_v1',
        'telegram_binding_schema' => 'telegram_binding_schema_v1'
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
                $this->applyRiskAuditSchema();
                return;
            case 'ip_location_cache_schema':
                $this->applyIpLocationCacheSchema();
                return;
            case 'node_connection_log_schema':
                $this->applyNodeConnectionLogSchema();
                return;
            case 'risk_rule_schema':
                $this->applyRiskRuleSchema();
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
        }

        throw new RuntimeException("Unknown schema migration: {$version}");
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

    private function applyRiskAuditSchema(): void
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

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_subscription_risk_cycle` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `subscription_id` bigint(20) NOT NULL,
            `cycle_start` bigint(20) NOT NULL,
            `cycle_end` bigint(20) NOT NULL,
            `transfer_enable` bigint(20) NOT NULL DEFAULT '0',
            `used_traffic` bigint(20) NOT NULL DEFAULT '0',
            `used_ratio` decimal(12,8) DEFAULT NULL,
            `user_agent_count` int(11) NOT NULL DEFAULT '0',
            `distinct_ip_count` int(11) NOT NULL DEFAULT '0',
            `city_count` int(11) NOT NULL DEFAULT '0',
            `region_count` int(11) NOT NULL DEFAULT '0',
            `country_count` int(11) NOT NULL DEFAULT '0',
            `status` varchar(16) NOT NULL DEFAULT 'pending',
            `risk_reasons` text DEFAULT NULL,
            `evaluated_at` bigint(20) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach ([
            'user_id' => 'int(11) NOT NULL',
            'subscription_id' => 'bigint(20) NOT NULL',
            'cycle_start' => 'bigint(20) NOT NULL',
            'cycle_end' => 'bigint(20) NOT NULL',
            'transfer_enable' => "bigint(20) NOT NULL DEFAULT '0'",
            'used_traffic' => "bigint(20) NOT NULL DEFAULT '0'",
            'used_ratio' => 'decimal(12,8) DEFAULT NULL',
            'user_agent_count' => "int(11) NOT NULL DEFAULT '0'",
            'distinct_ip_count' => "int(11) NOT NULL DEFAULT '0'",
            'city_count' => "int(11) NOT NULL DEFAULT '0'",
            'region_count' => "int(11) NOT NULL DEFAULT '0'",
            'country_count' => "int(11) NOT NULL DEFAULT '0'",
            'status' => "varchar(16) NOT NULL DEFAULT 'pending'",
            'risk_reasons' => 'text DEFAULT NULL',
            'evaluated_at' => 'bigint(20) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_subscription_risk_cycle', $column, $definition);
        }
        $this->ensureIndex('v2_subscription_risk_cycle', 'subscription_cycle_start', ['subscription_id', 'cycle_start'], true);
        $this->ensureIndex('v2_subscription_risk_cycle', 'user_cycle_end', ['user_id', 'cycle_end']);
        $this->ensureIndex('v2_subscription_risk_cycle', 'status', ['status']);
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

    private function applyRiskRuleSchema(): void
    {
        $this->requireTable('v2_subscription_risk_cycle');

        // 种子只在这次真的建了表时写，判断必须取在 CREATE 之前：
        //   存量安装首次升级 ⇒ 表不存在 ⇒ 建表并写三条默认规则
        //   全新安装 ⇒ install.sql 已建表并写好种子 ⇒ 不再写
        //   任何后续部署 ⇒ 表已存在 ⇒ 不写，管理员删掉的规则不会复活
        // 用「这次是否首次应用该迁移版本」来判断是不够的：全新安装不写
        // v2_schema_migrations 行，首次 bash update.sh 会看到「未应用」，此时若管理员已经
        // 删掉某条默认规则，下面的 WHERE NOT EXISTS 挡不住，规则会复活并重新给用户打标。
        $freshTable = !Schema::hasTable('v2_risk_rule');

        DB::statement("CREATE TABLE IF NOT EXISTS `v2_risk_rule` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `label` varchar(255) NOT NULL,
            `dimension` varchar(32) NOT NULL,
            `operator` varchar(2) NOT NULL,
            `threshold` decimal(18,8) NOT NULL,
            `enabled` tinyint(1) NOT NULL DEFAULT '1',
            `sort` int(11) DEFAULT NULL,
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'label' => 'varchar(255) NOT NULL',
            'dimension' => 'varchar(32) NOT NULL',
            'operator' => 'varchar(2) NOT NULL',
            'threshold' => 'decimal(18,8) NOT NULL',
            'enabled' => "tinyint(1) NOT NULL DEFAULT '1'",
            'sort' => 'int(11) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_risk_rule', $column, $definition);
        }
        // 引擎唯一的读法是 WHERE enabled = 1 ORDER BY sort ASC, id ASC。
        $this->ensureIndex('v2_risk_rule', 'enabled_sort', ['enabled', 'sort']);

        // 新指标存进一个可空 JSON 列：已有的五个计数列和 used_ratio 是展示契约（编译产物
        // 和 summaryForUser 都直接读），一个字节都不动，此后新增维度永远不需要再 DDL。
        // 刻意不把这一列加进 SubscriptionRiskService::available()，那是硬闸门，多一个条件
        // 就会在未升级的库上静默关掉全部风控评估。
        $this->ensureColumn('v2_subscription_risk_cycle', 'metrics', 'text DEFAULT NULL');

        if (!$freshTable) {
            return;
        }

        // 第二道保险：并发部署或 CREATE 与 INSERT 之间被打断时，NOT EXISTS 保证不写重复行。
        $now = time();
        foreach ([
            ['订阅 UA 种类过多', 'user_agent_count', '>', 3, 1],
            ['跨省/州请求过多', 'region_count', '>=', 3, 2],
            ['跨市请求过多', 'city_count', '>=', 3, 3]
        ] as [$label, $dimension, $operator, $threshold, $sort]) {
            DB::statement(
                "INSERT INTO `v2_risk_rule`
                    (`label`,`dimension`,`operator`,`threshold`,`enabled`,`sort`,`created_at`,`updated_at`)
                 SELECT ?, ?, ?, ?, 1, ?, ?, ?
                 FROM (SELECT 1) AS seed
                 WHERE NOT EXISTS (
                     SELECT 1 FROM `v2_risk_rule` r
                     WHERE r.`dimension` = ? AND r.`operator` = ? AND r.`threshold` = ?
                 )",
                [$label, $dimension, $operator, $threshold, $sort, $now, $now, $dimension, $operator, $threshold]
            );
        }
    }

    private function applyTokenHistorySchema(): void
    {
        $this->requireTable('v2_user');
        $this->requireTable('v2_subscription');

        // 与 applyRiskRuleSchema 同理：判断必须取在 CREATE 之前。
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
            `status` varchar(16) NOT NULL DEFAULT 'unknown',
            `resolved_at` bigint(20) DEFAULT NULL,
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
            'status' => "varchar(16) NOT NULL DEFAULT 'unknown'",
            'resolved_at' => 'bigint(20) DEFAULT NULL',
            'created_at' => 'int(11) NOT NULL',
            'updated_at' => 'int(11) NOT NULL'
        ] as $column => $definition) {
            $this->ensureColumn('v2_ip_location_cache', $column, $definition);
        }
        $this->ensureIndex('v2_ip_location_cache', 'ip', ['ip'], true);
        $this->ensureIndex('v2_ip_location_cache', 'location_status', ['status']);
        $this->ensureIndex('v2_ip_location_cache', 'location_key', ['location_key']);
    }

    private function applyPasswordPolicySchema(): void
    {
        $this->requireTable('v2_user');

        // 与 applyRiskRuleSchema / applyTokenHistorySchema 同理：判断必须取在 ALTER 之前。
        // run() 对每个版本每次部署都无条件重跑 apply()，不门控的话每次 update.sh 都会把
        // 已经重置过密码的用户重新标成待重置。
        $freshColumn = !Schema::hasColumn('v2_user', 'password_reset_required');

        // 默认 0：安装器创建的那个管理员天生合规，不需要额外补救；注册路径显式置 1。
        $this->ensureColumn('v2_user', 'password_reset_required', "tinyint(1) NOT NULL DEFAULT '0'");

        // 不建索引：这一列只会以「按主键读某个用户」的方式访问，且取值只有两种。
        if (!$freshColumn) {
            return;
        }

        // 一次性回填：现存普通用户的密码全是自己敲的，按策略都要重置。管理员和员工不打扰
        // —— 提醒只出现在用户端，而他们主要用管理端，被提醒了也无处可点。
        DB::table('v2_user')
            ->where('is_admin', 0)
            ->where('is_staff', 0)
            ->update(['password_reset_required' => 1]);
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
}
