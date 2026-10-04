<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AdminSecuritySchema
{
    public static function apply(): void
    {
        $first = DB::table('v2_user')->where('id', 1)->first();
        if (!$first || !$first->is_admin || !empty($first->banned)) {
            throw new RuntimeException('4A 升级停止：用户 ID 1 必须是可用的现有管理员，请先人工核实创始账号。');
        }
        if (!Schema::hasColumn('v2_user', 'admin_role')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->string('admin_role', 24)->nullable();
            });
        }
        if (!Schema::hasColumn('v2_user', 'admin_version')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->unsignedInteger('admin_version')->default(0);
            });
        }
        if (!Schema::hasTable('v2_admin_audit_head')) {
            Schema::create('v2_admin_audit_head', function (Blueprint $table) {
                $table->unsignedInteger('id')->primary();
                $table->unsignedBigInteger('sequence')->default(0);
                $table->string('hash', 64);
            });
        }
        DB::table('v2_admin_audit_head')->insertOrIgnore(['id' => 1, 'sequence' => 0, 'hash' => str_repeat('0', 64)]);
        if (!Schema::hasTable('v2_admin_audit')) {
            Schema::create('v2_admin_audit', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary();
                $table->string('request_id', 32)->index();
                $table->unsignedInteger('actor_id')->nullable()->index();
                $table->string('role', 24)->nullable();
                $table->string('event', 100)->index();
                $table->string('result', 24)->index();
                $table->longText('payload');
                $table->string('previous_hash', 64);
                $table->string('hash', 64);
                $table->unsignedInteger('created_at')->index();
            });
        }
        // Triggers also cover query-builder SQL; no foreign keys or cleanup cascade.
        $driver = DB::connection()->getDriverName();
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'v2_admin_audit_no_' . strtolower($operation);
            if ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$name} BEFORE {$operation} ON v2_admin_audit BEGIN SELECT RAISE(ABORT, 'Security audit is append only'); END");
            } elseif ($driver === 'mysql') {
                $exists = DB::select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', [$name]);
                if (!$exists) DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON v2_admin_audit FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security audit is append only'");
            } else {
                throw new RuntimeException('4A 审计防删目前支持 MySQL/MariaDB 和 SQLite');
            }
        }
        // run() reapplies migrations. This independent marker prevents repeated
        // revocation or reassignment after the super administrator allocates roles.
        if (!DB::table('v2_schema_migrations')->where('version', 'admin_security_bootstrap')->exists()) {
            DB::transaction(function () {
                DB::table('v2_user')->where(function ($q) {
                    $q->where('is_admin', 1)->orWhere('is_staff', 1);
                })->update(['admin_role' => null, 'admin_version' => 1]);
                DB::table('v2_schema_migrations')->insert([
                    'version' => 'admin_security_bootstrap', 'checksum' => hash('sha256', 'admin_security_bootstrap_v1'), 'applied_at' => time(),
                ]);
                SecurityAuditService::append('security.bootstrap', 'success', ['super_user_id' => 1, 'legacy_roles' => 'unassigned']);
            });
        }
    }
}
