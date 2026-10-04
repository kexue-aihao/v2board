<?php

namespace Tests\Support;

use App\Services\AdminSecuritySchema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AdminSecurityFixture
{
    public static function install(): void
    {
        Schema::create('v2_schema_migrations', function (Blueprint $table) {
            $table->string('version')->primary();
            $table->string('checksum', 64);
            $table->unsignedInteger('applied_at');
        });
        AdminSecuritySchema::apply();
    }
}
