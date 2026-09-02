<?php

namespace Tests\Unit;

use App\Models\TrafficRewardLog;
use App\Http\Controllers\V1\Guest\TelegramController;
use App\Services\TelegramRewardService;
use App\Services\TelegramService;
use App\Services\TrafficRewardService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramNativeRewardServiceTest extends TestCase
{
    private const CHAT_ID = -1001234567890;
    private const TELEGRAM_USER_ID = 10001;
    private const SUBSCRIPTION_ID = 10;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        config([
            'v2board.reward_enable' => 1,
            'v2board.reward_group_enable' => 1,
            'v2board.reward_dice_enable' => 1,
            'v2board.reward_slots_enable' => 1,
            'v2board.reward_dice_win_probability' => '100.00',
            'v2board.reward_slots_win_probability' => '100.00',
            'v2board.reward_dice_payout_multiplier' => '2.00',
            'v2board.reward_slots_payout_multiplier' => '3.00',
            'v2board.reward_dice_win_face' => 6,
            'v2board.reward_dice_daily_limit' => 0,
            'v2board.reward_slots_daily_limit' => 0,
            'v2board.telegram_subscription_binding_enable' => 0,
            'v2board.telegram_webhook_secret' => null,
            'v2board.telegram_bot_token' => 'native-test-token',
        ]);

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->boolean('banned')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->bigInteger('transfer_enable')->default(0);
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
            $table->bigInteger('transfer_enable')->default(0);
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_telegram_subscription_binding', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('subscription_id');
            $table->char('subscription_token_hash', 64);
            $table->unsignedBigInteger('telegram_user_id');
            $table->bigInteger('chat_id');
            $table->string('status');
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_user_game_setting', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('game');
            $table->integer('bet_gb');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->unique(['user_id', 'game']);
        });
        Schema::create('v2_traffic_reward_log', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('subscription_id');
            $table->string('source');
            $table->string('entrypoint', 128);
            $table->bigInteger('reward_bytes');
            $table->string('unique_key')->unique();
            $table->text('metadata')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

        DB::table('v2_user')->insert([
            'id' => 1,
            'telegram_id' => self::TELEGRAM_USER_ID,
            'banned' => 0,
            'is_admin' => 0,
            'transfer_enable' => 100 * TrafficRewardService::GB,
        ]);
        DB::table('v2_subscription')->insert([
            'id' => self::SUBSCRIPTION_ID,
            'user_id' => 1,
            'token' => 'native-token',
            'status' => 'active',
            'is_primary' => 1,
            'expired_at' => time() + 3600,
            'transfer_enable' => 100 * TrafficRewardService::GB,
            'u' => 0,
            'd' => 0,
        ]);
        DB::table('v2_telegram_subscription_binding')->insert([
            'id' => 1,
            'user_id' => 1,
            'subscription_id' => self::SUBSCRIPTION_ID,
            'subscription_token_hash' => hash('sha256', 'native-token'),
            'telegram_user_id' => self::TELEGRAM_USER_ID,
            'chat_id' => self::CHAT_ID,
            'status' => 'active',
            'updated_at' => time(),
        ]);
    }

    public function testNativeDiceUsesTelegramValueForWinningAndLosingSettlement(): void
    {
        [$service, $telegram] = $this->service();

        $winning = $service->settleNativeEmoji(self::CHAT_ID, self::TELEGRAM_USER_ID, '🎲', 6, 101, 9001);
        $losing = $service->settleNativeEmoji(self::CHAT_ID, self::TELEGRAM_USER_ID, '🎲', 5, 102, 9002);

        $this->assertTrue($winning['won']);
        $this->assertFalse($losing['won']);
        $this->assertCount(2, $telegram->messages);
        $this->assertStringContainsString('Telegram 原生结果', $telegram->messages[0]['text']);
        $this->assertStringContainsString('流量变化：+2 GB', $telegram->messages[0]['text']);
        $this->assertStringContainsString('流量变化：-1 GB', $telegram->messages[1]['text']);

        $logs = TrafficRewardLog::orderBy('id')->get();
        $this->assertSame('telegram-native:-1001234567890:101', $logs[0]->entrypoint);
        $this->assertSame('telegram_native', $logs[0]->metadata['result_source']);
        $this->assertSame('🎲', $logs[0]->metadata['telegram_native_emoji']);
        $this->assertSame(6, $logs[0]->metadata['telegram_dice_value']);
        $this->assertSame(9001, (int)$logs[0]->metadata['telegram_update_id']);
        $this->assertSame(5, $logs[1]->metadata['result']);
        $this->assertFalse((bool)$logs[1]->metadata['won']);
    }

    public function testNativeSlotsValueSixtyFourTriggersTheJackpotSettlement(): void
    {
        [$service, $telegram] = $this->service();

        $result = $service->settleNativeEmoji(self::CHAT_ID, self::TELEGRAM_USER_ID, '🎰', 64, 201, 9101);

        $this->assertTrue($result['won']);
        $this->assertSame(64, $result['result']);
        $this->assertStringContainsString('Jackpot', $telegram->messages[0]['text']);
        $log = TrafficRewardLog::firstOrFail();
        $this->assertSame(64, $log->metadata['result']);
        $this->assertSame(64, $log->metadata['telegram_dice_value']);
    }

    public function testRepeatedNativeUpdateDoesNotSettleOrSendAgain(): void
    {
        $telegram = new NativeResultTelegramService();
        $controller = new TelegramController();
        $property = (new \ReflectionClass($controller))->getProperty('telegramService');
        $property->setAccessible(true);
        $property->setValue($controller, $telegram);
        $request = Request::create(
            '/telegram/webhook?access_token=' . md5('native-test-token'),
            'POST',
            [
                'update_id' => 9201,
                'message' => [
                    'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup'],
                    'from' => ['id' => self::TELEGRAM_USER_ID],
                    'message_id' => 301,
                    'dice' => ['emoji' => '🎲', 'value' => 6],
                ],
            ]
        );

        $controller->webhook($request);
        $controller->webhook($request);

        $this->assertSame(1, TrafficRewardLog::count());
        $this->assertCount(1, $telegram->messages);
    }

    private function service(): array
    {
        $telegram = new NativeResultTelegramService();
        return [new TelegramRewardService($telegram), $telegram];
    }
}

class NativeResultTelegramService extends TelegramService
{
    public $messages = [];

    public function sendMessage(int $chatId, string $text, string $parseMode = '', ?array $replyMarkup = null)
    {
        $this->messages[] = compact('chatId', 'text', 'parseMode', 'replyMarkup');
        return (object)['ok' => true];
    }
}
