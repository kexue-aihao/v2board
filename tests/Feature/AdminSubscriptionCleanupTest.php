<?php

namespace Tests\Feature;

use App\Models\User;
use App\Jobs\SendTelegramAdminOperationJob;
use App\Services\AuthService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AdminSubscriptionCleanupTest extends TestCase
{
    private $url;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'v2board.telegram_admin_operation_enable' => 0,
        ]);
        Queue::fake();
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->withoutMiddleware();
        $this->url = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/user/subscription-cleanup';

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('invite_user_id')->nullable();
            $table->string('email');
            $table->integer('plan_id')->nullable();
            $table->integer('expired_at')->nullable();
            $table->integer('balance')->default(0);
            $table->integer('commission_balance')->default(0);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_subscription', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->integer('expired_at')->nullable();
        });
        foreach (['v2_order', 'v2_invite_code', 'v2_ticket'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('user_id')->nullable();
            });
        }
        Schema::create('v2_ticket_message', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('ticket_id');
        });
    }

    public function testScanFindsExpiredAndEmptyUsersButSkipsBackofficeAccounts(): void
    {
        $now = time();
        $expired = $this->user('expired@example.test', 1, $now - 60);
        $empty = $this->user('empty@example.test', null, null);
        $this->user('active@example.test', 2, $now + 86400);
        $this->user('unlimited@example.test', 2, null);
        $this->user('admin@example.test', null, $now - 60, true);
        $this->user('staff@example.test', null, $now - 60, false, true);

        $response = $this->postJson($this->url, ['action' => 'scan'])->assertOk();
        $this->assertSame(2, $response->json('total'));
        $this->assertSame([$expired->id, $empty->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertSame('expired', $response->json('data.0.reason'));
        $this->assertSame('empty', $response->json('data.1.reason'));
        $this->assertSame(0, $response->json('data.0.balance'));
        $this->assertSame(0, $response->json('data.0.commission_balance'));
        $this->assertNotEmpty($response->json('scan_token'));
        $this->assertDatabaseHas('v2_user', ['id' => $expired->id]);
    }

    public function testDeletionRequiresConfirmationAndRemovesSubscriptions(): void
    {
        $expired = $this->user('expired@example.test', 1, time() - 60);
        DB::table('v2_subscription')->insert(['user_id' => $expired->id, 'expired_at' => time() - 60]);
        $scanToken = $this->scanToken();

        $this->postJson($this->url, ['action' => 'delete'])
            ->assertStatus(422);
        $this->assertDatabaseHas('v2_user', ['id' => $expired->id]);

        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1);
        $this->assertDatabaseMissing('v2_user', ['id' => $expired->id]);
        $this->assertDatabaseMissing('v2_subscription', ['user_id' => $expired->id]);
    }

    public function testAnyNonzeroBalanceOrCommissionProtectsExpiredAndEmptyAccounts(): void
    {
        $expired = $this->user('expired@example.test', 1, time() - 60);
        $empty = $this->user('empty@example.test', null, null);
        foreach ([null, 1] as $planId) {
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1]] as $index => $funds) {
                $user = $this->user('protected-' . ($planId ?? 'empty') . '-' . $index . '@example.test', $planId, time() - 60);
                $user->update(['balance' => $funds[0], 'commission_balance' => $funds[1]]);
            }
        }
        $response = $this->postJson($this->url, ['action' => 'scan'])->assertOk();
        $this->assertSame([$expired->id, $empty->id], array_column($response->json('data'), 'id'));
        $this->postJson($this->url, [
            'action' => 'delete', 'confirm' => 1, 'scan_token' => $response->json('scan_token')
        ])->assertOk()->assertJsonPath('data.deleted_count', 2);
        $this->assertSame(10, User::count());
    }

    public function testOtherUnexpiredOrUnlimitedSubscriptionsProtectTheAccount(): void
    {
        $allExpired = $this->user('all-expired@example.test', 1, time() - 60);
        $future = $this->user('other-valid@example.test', 1, time() - 60);
        $unlimited = $this->user('other-unlimited@example.test', null, null);
        DB::table('v2_subscription')->insert([
            ['user_id' => $allExpired->id, 'expired_at' => time() - 60],
            ['user_id' => $allExpired->id, 'expired_at' => time() - 120],
            ['user_id' => $future->id, 'expired_at' => time() - 60],
            ['user_id' => $future->id, 'expired_at' => time() + 86400],
            ['user_id' => $unlimited->id, 'expired_at' => null]
        ]);
        $response = $this->postJson($this->url, ['action' => 'scan'])->assertOk();
        $this->assertSame([$allExpired->id], array_column($response->json('data'), 'id'));
        $this->postJson($this->url, [
            'action' => 'delete', 'confirm' => 1, 'scan_token' => $response->json('scan_token')
        ])->assertOk()->assertJsonPath('data.deleted_count', 1);
        $this->assertDatabaseHas('v2_user', ['id' => $future->id]);
        $this->assertDatabaseHas('v2_user', ['id' => $unlimited->id]);
    }

    public function testDeletionRechecksFundsRenewalsSubscriptionsAndRoles(): void
    {
        $balance = $this->user('balance@example.test', null, null);
        $commission = $this->user('commission@example.test', 1, time() - 60);
        $renewed = $this->user('renewed@example.test', 1, time() - 60);
        $newSubscription = $this->user('subscription@example.test', null, null);
        $admin = $this->user('promoted-admin@example.test', null, null);
        $staff = $this->user('promoted-staff@example.test', null, null);
        $invalid = $this->user('invalid@example.test', null, null);
        $scanToken = $this->scanToken();
        $balance->update(['balance' => 1]);
        $commission->update(['commission_balance' => 1]);
        $renewed->update(['expired_at' => time() + 86400]);
        DB::table('v2_subscription')->insert(['user_id' => $newSubscription->id, 'expired_at' => time() + 86400]);
        $admin->update(['is_admin' => 1]);
        $staff->update(['is_staff' => 1]);
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken])
            ->assertOk()->assertJsonPath('data.deleted_count', 1);
        $this->assertSame(6, User::count());
        $this->assertDatabaseMissing('v2_user', ['id' => $invalid->id]);
    }

    public function testDeletionOnlyProcessesTheExactScanSnapshot(): void
    {
        $laterInvalid = $this->user('later-invalid@example.test', 1, time() + 86400);
        $invalid = $this->user('invalid@example.test', null, null);
        $scanToken = $this->scanToken();
        $laterInvalid->update(['expired_at' => time() - 60]);
        $newUser = $this->user('new@example.test', null, null);
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken])
            ->assertOk()->assertJsonPath('data.deleted_count', 1);
        $this->assertDatabaseMissing('v2_user', ['id' => $invalid->id]);
        $this->assertDatabaseHas('v2_user', ['id' => $laterInvalid->id]);
        $this->assertDatabaseHas('v2_user', ['id' => $newUser->id]);
    }

    public function testCleanupRemovesRelatedDataAndRevokesSessions(): void
    {
        $invalid = $this->user('invalid@example.test', null, null);
        $invitee = $this->user('invitee@example.test', 1, time() + 86400);
        $invitee->update(['invite_user_id' => $invalid->id]);
        DB::table('v2_order')->insert(['user_id' => $invalid->id]);
        DB::table('v2_invite_code')->insert(['user_id' => $invalid->id]);
        $ticketId = DB::table('v2_ticket')->insertGetId(['user_id' => $invalid->id]);
        DB::table('v2_ticket_message')->insert(['ticket_id' => $ticketId]);
        $auth = new AuthService($invalid);
        $auth->generateAuthData(Request::create('/'), true);
        $this->assertNotEmpty($auth->getSessions());
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $this->scanToken()])
            ->assertOk()->assertJsonPath('data.deleted_count', 1);
        foreach (['v2_order', 'v2_invite_code', 'v2_ticket', 'v2_ticket_message'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
        $this->assertNull($invitee->fresh()->invite_user_id);
        $this->assertSame([], $auth->getSessions());
    }

    public function testMissingExpiredOrReusedSnapshotsCannotDeleteUsers(): void
    {
        $invalid = $this->user('invalid@example.test', null, null);
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1])->assertStatus(422);
        $scanToken = $this->scanToken();
        Cache::forget('ADMIN_SUBSCRIPTION_CLEANUP_' . $scanToken);
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken])->assertStatus(422);
        $this->assertDatabaseHas('v2_user', ['id' => $invalid->id]);
        $scanToken = $this->scanToken();
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken])->assertOk();
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken])->assertStatus(422);
    }

    public function testOnlyAdministratorsCanScanOrDeleteAndSnapshotsBelongToTheirCreator(): void
    {
        $this->withMiddleware();
        $ordinary = $this->user('ordinary@example.test', null, null);
        $admin = $this->user('admin@example.test', null, null, true);
        $otherAdmin = $this->user('other-admin@example.test', null, null, true);
        $ordinaryToken = (new AuthService($ordinary))->generateAuthData(Request::create('/'), true)['auth_data'];
        $adminToken = (new AuthService($admin))->generateAuthData(Request::create('/'), true)['auth_data'];
        $otherToken = (new AuthService($otherAdmin))->generateAuthData(Request::create('/'), true)['auth_data'];
        foreach (['scan', 'delete'] as $action) {
            $this->postJson($this->url, ['action' => $action])->assertStatus(403);
            $this->postJson($this->url, ['action' => $action], ['Authorization' => $ordinaryToken])->assertStatus(403);
        }
        $scanToken = $this->postJson($this->url, ['action' => 'scan'], ['Authorization' => $adminToken])
            ->assertOk()->json('scan_token');
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken], ['Authorization' => $otherToken])
            ->assertStatus(422);
        $this->assertDatabaseHas('v2_user', ['id' => $ordinary->id]);
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken], ['Authorization' => $adminToken])
            ->assertOk()->assertJsonPath('data.deleted_count', 1);
    }

    public function testLegacyDatabaseWithoutMultipleSubscriptionsIsSupported(): void
    {
        Schema::drop('v2_subscription');
        $invalid = $this->user('invalid@example.test', null, null);
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $this->scanToken()])
            ->assertOk()->assertJsonPath('data.deleted_count', 1);
        $this->assertDatabaseMissing('v2_user', ['id' => $invalid->id]);
    }

    public function testThePreviewLimitDoesNotLimitTheCleanupSnapshot(): void
    {
        $this->enableCleanupNotifications();
        $users = [];
        for ($index = 0; $index < 205; $index++) {
            $users[] = ['email' => 'empty-' . $index . '@example.test'];
        }
        DB::table('v2_user')->insert($users);
        $response = $this->postJson($this->url, ['action' => 'scan'])
            ->assertOk()->assertJsonPath('total', 205)->assertJsonPath('returned', 200);
        $this->assertSame(205, User::count());
        Queue::fake();
        $this->postJson($this->url, [
            'action' => 'delete', 'confirm' => 1, 'scan_token' => $response->json('scan_token')
        ])->assertOk()->assertJsonPath('data.deleted_count', 205);
        $this->assertSame(0, User::count());
        $messages = Queue::pushed(SendTelegramAdminOperationJob::class)->map(function ($job) {
            return $this->notificationText($job);
        })->implode("\n");
        $this->assertStringContainsString('empty-204@example.test', $messages);
        $this->assertStringContainsString('已删除：205', $messages);
    }

    public function testOptionalIdsCannotExpandTheSnapshotAndAreValidated(): void
    {
        $first = $this->user('first@example.test', null, null);
        $second = $this->user('second@example.test', null, null);
        $scanToken = $this->scanToken();
        $newUser = $this->user('new@example.test', null, null);
        $this->postJson($this->url, [
            'action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken, 'ids' => ['invalid']
        ])->assertStatus(422);
        $this->postJson($this->url, [
            'action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken, 'ids' => [$first->id, $newUser->id]
        ])->assertOk()->assertJsonPath('data.deleted_count', 1);
        $this->assertDatabaseMissing('v2_user', ['id' => $first->id]);
        $this->assertDatabaseHas('v2_user', ['id' => $second->id]);
        $this->assertDatabaseHas('v2_user', ['id' => $newUser->id]);
    }

    public function testScanSendsAGroupSummaryWithoutDeletingAccounts(): void
    {
        $this->enableCleanupNotifications();
        $user = $this->user('expired@example.test', 1, time() - 60);
        $this->postJson($this->url, ['action' => 'scan', 'user' => ['id' => 99, 'email' => 'admin@example.test']])
            ->assertOk()->assertJsonPath('total', 1);
        Queue::assertPushed(SendTelegramAdminOperationJob::class, 1);
        Queue::assertPushed(SendTelegramAdminOperationJob::class, function ($job) {
            $text = $this->notificationText($job);
            return strpos($text, '无效账号检测完成') !== false
                && strpos($text, '符合条件：1 个账号') !== false
                && strpos($text, 'admin@example.test') !== false;
        });
        $this->assertDatabaseHas('v2_user', ['id' => $user->id]);
    }

    public function testDeletionReportsOnlyActualDeletedAccounts(): void
    {
        $this->enableCleanupNotifications();
        $deleted = $this->user('deleted@example.test', 1, time() - 60);
        $protected = $this->user('protected@example.test', null, null);
        $scanToken = $this->scanToken();
        $protected->update(['balance' => 1]);
        Queue::fake();
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken])
            ->assertOk()->assertJsonPath('data.deleted_count', 1);
        Queue::assertPushed(SendTelegramAdminOperationJob::class, 1);
        Queue::assertPushed(SendTelegramAdminOperationJob::class, function ($job) {
            $text = $this->notificationText($job);
            return strpos($text, 'deleted@example.test') !== false
                && strpos($text, 'protected@example.test') === false
                && strpos($text, '已删除：1；跳过：1；未完成：0') !== false;
        });
        $this->assertDatabaseMissing('v2_user', ['id' => $deleted->id]);
        $this->assertDatabaseHas('v2_user', ['id' => $protected->id]);
    }

    public function testRejectedDeletionDoesNotSendADeletionNotification(): void
    {
        $this->enableCleanupNotifications();
        $user = $this->user('expired@example.test', 1, time() - 60);
        $scanToken = $this->scanToken();
        Queue::fake();
        $this->postJson($this->url, ['action' => 'delete', 'scan_token' => $scanToken])->assertStatus(422);
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1])->assertStatus(422);
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('v2_user', ['id' => $user->id]);
    }

    public function testDeletionNotificationIsQueuedAfterTheTransactionCommits(): void
    {
        $this->enableCleanupNotifications();
        $user = $this->user('expired@example.test', 1, time() - 60);
        $scanToken = $this->scanToken();
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function ($job) use ($user) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseMissing('v2_user', ['id' => $user->id]);
            $this->assertStringContainsString('expired@example.test', $this->notificationText($job));
        });
        $this->app->instance(Dispatcher::class, $dispatcher);
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken])
            ->assertOk()->assertJsonPath('data.deleted_count', 1);
    }

    public function testNotificationQueueFailuresDoNotBreakScanningOrDeletion(): void
    {
        $this->enableCleanupNotifications();
        $user = $this->user('expired@example.test', 1, time() - 60);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->twice()->andThrow(new \RuntimeException('notification queue offline'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        $scanToken = $this->scanToken();
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken])
            ->assertOk()->assertJsonPath('data.deleted_count', 1);
        $this->assertDatabaseMissing('v2_user', ['id' => $user->id]);
    }

    public function testInterruptedCleanupReportsOnlyCommittedDeletions(): void
    {
        $this->enableCleanupNotifications();
        $first = $this->user('committed@example.test', null, null);
        $second = $this->user('rolled-back@example.test', null, null);
        $third = $this->user('unprocessed@example.test', null, null);
        DB::table('v2_order')->insert(['user_id' => $second->id]);
        DB::statement('CREATE TRIGGER fail_cleanup BEFORE DELETE ON v2_user WHEN OLD.id = '
            . (int)$second->id . " BEGIN SELECT RAISE(ABORT, 'cleanup test failure'); END");
        $scanToken = $this->scanToken();
        Queue::fake();
        $this->postJson($this->url, ['action' => 'delete', 'confirm' => 1, 'scan_token' => $scanToken])->assertStatus(500);
        $this->assertDatabaseMissing('v2_user', ['id' => $first->id]);
        $this->assertDatabaseHas('v2_user', ['id' => $second->id]);
        $this->assertDatabaseHas('v2_user', ['id' => $third->id]);
        $this->assertDatabaseHas('v2_order', ['user_id' => $second->id]);
        Queue::assertPushed(SendTelegramAdminOperationJob::class, 1);
        Queue::assertPushed(SendTelegramAdminOperationJob::class, function ($job) {
            $text = $this->notificationText($job);
            return strpos($text, '无效账号清理中断') !== false
                && strpos($text, 'committed@example.test') !== false
                && strpos($text, 'rolled-back@example.test') === false
                && strpos($text, 'unprocessed@example.test') === false
                && strpos($text, '已删除：1；跳过：0；未完成：2') !== false;
        });
    }

    private function enableCleanupNotifications(): void
    {
        config([
            'v2board.telegram_admin_operation_enable' => 1,
            'v2board.telegram_bot_token' => 'test-token',
            'v2board.telegram_discuss_id' => '-100123456789',
            'v2board.telegram_admin_operation_topic_id' => 42,
        ]);
    }

    private function notificationText(SendTelegramAdminOperationJob $job): string
    {
        $reflection = new \ReflectionProperty($job, 'text');
        $reflection->setAccessible(true);
        return $reflection->getValue($job);
    }

    private function scanToken(): string
    {
        return $this->postJson($this->url, ['action' => 'scan'])->assertOk()->json('scan_token');
    }

    private function user(string $email, ?int $planId, ?int $expiredAt, bool $admin = false, bool $staff = false)
    {
        return User::create([
            'email' => $email,
            'plan_id' => $planId,
            'expired_at' => $expiredAt,
            'is_admin' => $admin ? 1 : 0,
            'is_staff' => $staff ? 1 : 0,
            'created_at' => time(),
            'updated_at' => time()
        ]);
    }
}
