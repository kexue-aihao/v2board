<?php

namespace Tests\Unit;

use App\Services\TelegramRewardService;
use App\Services\TelegramService;
use App\Services\TrafficRewardService;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TelegramRewardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->boolean('banned')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_subscription', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('token');
            $table->string('status');
            $table->boolean('is_primary')->default(false);
            $table->integer('expired_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_telegram_subscription_binding', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('subscription_id');
            $table->char('subscription_token_hash', 64);
            $table->unsignedBigInteger('telegram_user_id');
            $table->string('status');
            $table->integer('updated_at')->nullable();
        });
    }

    public function testLegacyBindWithAnActivePrimarySubscriptionCanUseRewards(): void
    {
        DB::table('v2_user')->insert(['id' => 1, 'telegram_id' => 10001, 'banned' => 0, 'is_admin' => 1]);
        DB::table('v2_subscription')->insert([
            'id' => 10,
            'user_id' => 1,
            'token' => 'legacy-token',
            'status' => 'active',
            'is_primary' => 1,
            'expired_at' => time() + 3600,
        ]);

        $context = $this->boundContext(10001);

        $this->assertSame(1, $context['user']->id);
        $this->assertSame(10, $context['subscription_id']);
        $this->assertTrue($context['is_admin']);
    }

    public function testLegacyBindCannotUseAnExpiredSubscription(): void
    {
        DB::table('v2_user')->insert(['id' => 2, 'telegram_id' => 10002, 'banned' => 0, 'is_admin' => 0]);
        DB::table('v2_subscription')->insert([
            'id' => 20,
            'user_id' => 2,
            'token' => 'expired-token',
            'status' => 'active',
            'is_primary' => 1,
            'expired_at' => time() - 1,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('请先在网站绑定有效订阅');
        $this->boundContext(10002);
    }

    public function testCurrentBindingUsesTheVerifiedSubscription(): void
    {
        DB::table('v2_user')->insert(['id' => 3, 'telegram_id' => null, 'banned' => 0, 'is_admin' => 0]);
        DB::table('v2_subscription')->insert([
            'id' => 30,
            'user_id' => 3,
            'token' => 'current-token',
            'status' => 'active',
            'is_primary' => 1,
            'expired_at' => time() + 3600,
        ]);
        DB::table('v2_telegram_subscription_binding')->insert([
            'id' => 1,
            'user_id' => 3,
            'subscription_id' => 30,
            'subscription_token_hash' => hash('sha256', 'current-token'),
            'telegram_user_id' => 10003,
            'status' => 'active',
        ]);

        $context = $this->boundContext(10003);

        $this->assertSame(3, $context['user']->id);
        $this->assertSame(30, $context['subscription_id']);
        $this->assertFalse($context['is_admin']);
    }

    public function testCurrentBindingCannotUseAChangedSubscriptionToken(): void
    {
        DB::table('v2_user')->insert(['id' => 4, 'telegram_id' => null, 'banned' => 0, 'is_admin' => 0]);
        DB::table('v2_subscription')->insert([
            'id' => 40,
            'user_id' => 4,
            'token' => 'rotated-token',
            'status' => 'active',
            'is_primary' => 1,
            'expired_at' => time() + 3600,
        ]);
        DB::table('v2_telegram_subscription_binding')->insert([
            'id' => 2,
            'user_id' => 4,
            'subscription_id' => 40,
            'subscription_token_hash' => hash('sha256', 'previous-token'),
            'telegram_user_id' => 10004,
            'status' => 'active',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('请先在网站绑定有效订阅');
        $this->boundContext(10004);
    }

    public function testCallbackContinuesWhenTelegramAcknowledgementFails(): void
    {
        DB::table('v2_user')->insert(['id' => 5, 'telegram_id' => null, 'banned' => 0, 'is_admin' => 0]);
        $telegram = new FailingCallbackTelegramService();
        $rewards = new CallbackRewardService(User::findOrFail(5));
        $service = new TelegramRewardService($telegram, $rewards);

        $service->handleCallback([
            'id' => 'callback-id',
            'from' => ['id' => 10005],
            'message' => ['message_id' => 77, 'chat' => ['id' => 10005, 'type' => 'private']],
            'data' => 'rw:go:d',
        ]);

        $this->assertSame(1, $rewards->dicePlays);
        $this->assertCount(1, $telegram->messages);
        $this->assertStringContainsString('骰子点数：6', $telegram->messages[0]['text']);
    }

    public function testExplicitTelegramTokenOverridesTheSavedConfiguration(): void
    {
        config(['v2board.telegram_bot_token' => 'saved-token']);
        $service = new TelegramService('replacement-token');
        $property = (new \ReflectionClass($service))->getProperty('api');
        $property->setAccessible(true);

        $this->assertSame('https://api.telegram.org/botreplacement-token/', $property->getValue($service));
    }

    private function boundContext(int $telegramUserId): array
    {
        $service = new TelegramRewardService();
        $method = (new \ReflectionClass($service))->getMethod('boundContext');
        $method->setAccessible(true);
        return $method->invoke($service, $telegramUserId);
    }
}

class FailingCallbackTelegramService extends TelegramService
{
    public $messages = [];

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false)
    {
        throw new RuntimeException('callback acknowledgement failed');
    }

    public function sendMessage(int $chatId, string $text, string $parseMode = '', ?array $replyMarkup = null)
    {
        $this->messages[] = compact('chatId', 'text', 'parseMode', 'replyMarkup');
        return (object)['ok' => true];
    }
}

class CallbackRewardService extends TrafficRewardService
{
    public $dicePlays = 0;
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function telegramBindingContext($telegramUserId, $chatId = null): ?array
    {
        return ['user' => $this->user, 'subscription_id' => 50, 'is_admin' => false];
    }

    public function gameRules(): array
    {
        return ['dice' => ['enabled' => true]];
    }

    public function playDice(User $user, string $source = 'web', ?string $requestId = null, ?int $subscriptionId = null): array
    {
        $this->dicePlays++;
        return [
            'result' => 6,
            'won' => true,
            'bet_gb' => 1,
            'payout_gb' => 2,
            'net_bytes' => 2 * TrafficRewardService::GB,
        ];
    }
}
