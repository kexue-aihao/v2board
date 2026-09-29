<?php

namespace Tests\Unit;

use App\Jobs\SendTelegramJob;
use App\Models\TelegramRegistration;
use App\Services\TelegramRegistrationService;
use App\Services\TelegramService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class TelegramRegistrationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'v2board.telegram_register_enable' => 1,
            'v2board.telegram_register_code_delay' => 0,
            'v2board.telegram_bot_token' => 'test-token',
            'v2board.stop_register' => 0,
            'v2board.invite_force' => 0,
            'v2board.try_out_plan_id' => 0,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('password_algo')->nullable();
            $table->string('password_salt')->nullable();
            $table->string('uuid')->nullable();
            $table->string('token')->nullable();
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->integer('invite_user_id')->nullable();
            $table->integer('plan_id')->nullable();
            $table->integer('group_id')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->bigInteger('transfer_enable')->default(0);
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->integer('expired_at')->nullable();
            $table->boolean('banned')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->integer('last_login_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_telegram_registration', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('telegram_id');
            $table->string('telegram_username')->nullable();
            $table->string('email', 64);
            $table->string('invite_code', 64)->nullable();
            $table->char('code_hash', 64)->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->integer('sent_at')->nullable();
            $table->integer('expires_at')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_invite_code', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->default(0);
            $table->char('code', 32);
            $table->boolean('status')->default(false);
            $table->integer('pv')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function testApplySendsAHashedCodeAndConsumesTheSession(): void
    {
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(1001, Mockery::on(function ($message) {
                return preg_match('/注册验证码：\d{6}/', $message) === 1;
            }));

        $service = new TelegramRegistrationService($telegram);
        $service->startSession(1001);
        $this->assertNotNull($service->session(1001));

        $result = $service->apply(1001, 'alice', 'Alice@example.com');

        $this->assertTrue($result['ok']);
        $this->assertNull($service->session(1001));
        $application = TelegramRegistration::first();
        $this->assertSame('alice', $application->telegram_username);
        $this->assertSame('alice@example.com', $application->email);
        $this->assertSame(TelegramRegistrationService::STATUS_CODE_SENT, (int)$application->status);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string)$application->code_hash);
    }

    public function testForcedInviteIsValidatedBeforeAnApplicationIsCreated(): void
    {
        config(['v2board.invite_force' => 1]);
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldNotReceive('sendMessage');
        $service = new TelegramRegistrationService($telegram);

        $result = $service->apply(1002, null, 'new@example.com');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('邀请码', $result['message']);
        $this->assertSame(0, TelegramRegistration::count());
    }

    public function testCorrectCodeCreatesTheBoundUserAndConsumesTheApplication(): void
    {
        $code = null;
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->andReturnUsing(function ($chatId, $message) use (&$code) {
            preg_match('/注册验证码：(\d{6})/', $message, $matches);
            $code = $matches[1] ?? null;
        });

        $service = new TelegramRegistrationService($telegram);
        $service->apply(1005, 'bound_user', 'bound@example.com');
        $this->assertNotNull($code);
        $user = $service->register('bound@example.com', $code);

        $this->assertSame('bound@example.com', $user->email);
        $this->assertSame(1005, (int)$user->telegram_id);
        $application = TelegramRegistration::first();
        $this->assertSame(TelegramRegistrationService::STATUS_COMPLETED, (int)$application->status);
        $this->assertSame($user->id, (int)$application->user_id);
        $this->assertNull($application->code_hash);
    }

    public function testTheFifthWrongCodeAttemptVoidsTheApplication(): void
    {
        $code = null;
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->andReturnUsing(function ($chatId, $message) use (&$code) {
            preg_match('/注册验证码：(\d{6})/', $message, $matches);
            $code = $matches[1] ?? null;
        });

        $service = new TelegramRegistrationService($telegram);
        $service->apply(1003, null, 'retry@example.com');
        $this->assertNotNull($code);

        for ($i = 0; $i < 5; $i++) {
            try {
                $service->register('retry@example.com', '000000');
                $this->fail('An invalid code must be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }

        $application = TelegramRegistration::first();
        $this->assertSame(5, (int)$application->attempts);
        $this->assertSame(TelegramRegistrationService::STATUS_VOID, (int)$application->status);
        $this->assertNull($application->code_hash);

        try {
            $service->register('retry@example.com', $code);
            $this->fail('A code must not work after the application is voided.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function testDelayedCodeUsesTheQueue(): void
    {
        config(['v2board.telegram_register_code_delay' => 10]);
        Queue::fake();
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldNotReceive('sendMessage');

        $result = (new TelegramRegistrationService($telegram))->apply(1004, null, 'queued@example.com');

        $this->assertTrue($result['ok']);
        Queue::assertPushed(SendTelegramJob::class, function ($job) {
            return $job->queue === 'send_telegram';
        });
    }
}
