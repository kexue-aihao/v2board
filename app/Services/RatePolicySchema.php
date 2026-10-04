<?php

namespace App\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RatePolicySchema
{
    public static function install(): void
    {
        if (!Schema::hasTable('v2_rate_policy')) {
            Schema::create('v2_rate_policy', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 80);
                $table->text('settings');
                $table->unsignedInteger('revision')->default(1);
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }
        if (!Schema::hasTable('v2_rate_node_policy')) {
            Schema::create('v2_rate_node_policy', function (Blueprint $table) {
                $table->string('node_type', 24);
                $table->unsignedInteger('node_id');
                $table->string('mode', 16);
                $table->unsignedInteger('policy_id')->nullable()->index();
                $table->primary(['node_type', 'node_id']);
            });
        }
        if (!Schema::hasTable('v2_rate_policy_state')) {
            Schema::create('v2_rate_policy_state', function (Blueprint $table) {
                // 0 is the inherited global policy. Other IDs identify a shared scene.
                $table->unsignedInteger('policy_id');
                $table->unsignedBigInteger('node_user_id');
                $table->unsignedInteger('revision');
                $table->decimal('multiplier', 6, 3)->default(1);
                $table->bigInteger('rate_bps')->default(0);
                $table->unsignedInteger('high')->default(0);
                $table->unsignedInteger('burst')->default(0);
                $table->string('state', 16)->default('normal');
                $table->bigInteger('sampled_at');
                $table->bigInteger('computed_at')->index();
                $table->primary(['policy_id', 'node_user_id']);
            });
        }
        foreach (['policy_config_revision', 'global_policy_revision'] as $key) {
            DB::table(DynamicRateService::TABLE_SETTING)->insertOrIgnore([
                'setting_key' => $key, 'setting_value' => '1', 'updated_at' => time(),
            ]);
        }
    }
}
