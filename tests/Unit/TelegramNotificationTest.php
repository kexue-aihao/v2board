<?php

namespace Tests\Unit;

use App\Jobs\SendTelegramJob;
use App\Services\TelegramService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramNotificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'v2board.telegram_bot_enable' => 1,
            'v2board.telegram_bot_token' => 'test-token',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->string('admin_role')->nullable();
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function testPaymentNotificationsOnlyTargetBoundAdministratorsByDefault(): void
    {
        DB::table('v2_user')->insert([
            ['is_admin' => 1, 'is_staff' => 0, 'telegram_id' => 4001],
            ['is_admin' => 0, 'is_staff' => 1, 'telegram_id' => 4002],
            ['is_admin' => 1, 'is_staff' => 0, 'telegram_id' => null],
        ]);
        Queue::fake();

        (new TelegramService())->sendMessageWithAdmin('payment received');

        Queue::assertPushed(SendTelegramJob::class, 1);
    }

    public function testStaffCanBeIncludedExplicitly(): void
    {
        DB::table('v2_user')->insert([
            ['is_admin' => 1, 'is_staff' => 0, 'admin_role' => null, 'telegram_id' => 4011],
            ['is_admin' => 1, 'is_staff' => 0, 'admin_role' => 'support', 'telegram_id' => 4012],
        ]);
        Queue::fake();

        (new TelegramService())->sendMessageWithAdmin('ticket update', true);

        Queue::assertPushed(SendTelegramJob::class, 2);
    }

    public function testNotificationsFollowBusinessRolesInsteadOfTheOldAdminFlag(): void
    {
        foreach ([null, 'operations', 'finance', 'support', 'marketing', null] as $i => $role) {
            DB::table('v2_user')->insert(['id' => $i + 1, 'is_admin' => 1, 'admin_role' => $role, 'telegram_id' => 5000 + $i]);
        }
        $service = new TelegramService();
        $this->assertSame([1, 3], $service->administratorRecipients()->pluck('id')->values()->all());
        $this->assertSame([1, 4], $service->administratorRecipients(true)->pluck('id')->values()->all());
        $this->assertSame([1, 2], $service->administratorRecipients(false, ['super', 'operations'])->pluck('id')->values()->all());
    }
}
