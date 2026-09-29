<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\TelegramPasswordResetService;
use App\Services\TelegramService;
use App\Utils\CacheKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TelegramPasswordResetServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'v2board.telegram_bot_token' => 'test-token',
            'v2board.app_name' => 'Test Panel',
            'v2board.app_url' => 'https://panel.example.test',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Cache::flush();

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function testIssueSendsAndStoresAOneTimeCode(): void
    {
        $code = null;
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once()->andReturnUsing(function ($chatId, $message) use (&$code) {
            $this->assertSame(2001, $chatId);
            preg_match('/验证码：(\d{6})/', $message, $matches);
            $code = $matches[1] ?? null;
        });

        $user = User::create(['email' => 'member@example.com', 'telegram_id' => 2001]);
        $result = (new TelegramPasswordResetService($telegram))->issue($user);

        $this->assertTrue($result['ok']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string)$code);
        $this->assertSame($code, Cache::get(CacheKey::get('TELEGRAM_FORGET_CODE', 'member@example.com')));
        $this->assertNotNull(Cache::get(CacheKey::get('LAST_SEND_TELEGRAM_FORGET_TIMESTAMP', 'member@example.com')));
    }

    public function testIssueIsRateLimitedAndDoesNotSendToUnboundAccounts(): void
    {
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')->once();
        $user = User::create(['email' => 'member@example.com', 'telegram_id' => 2002]);
        $service = new TelegramPasswordResetService($telegram);

        $this->assertTrue($service->issue($user)['ok']);
        $second = $service->issue($user);
        $this->assertFalse($second['ok']);
        $this->assertStringContainsString('稍后', $second['message']);

        $unbound = User::create(['email' => 'unbound@example.com', 'telegram_id' => null]);
        $unboundResult = $service->issue($unbound);
        $this->assertFalse($unboundResult['ok']);
        $this->assertStringContainsString('绑定 Telegram', $unboundResult['message']);
    }
}
