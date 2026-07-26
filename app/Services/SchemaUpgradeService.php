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
        'ip_location_cache_schema' => 'ip_location_cache_schema_v1'
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
