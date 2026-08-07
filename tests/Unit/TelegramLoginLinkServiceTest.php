<?php

namespace Tests\Unit;

use App\Models\TelegramLoginLink;
use App\Models\User;
use App\Services\TelegramLoginLinkService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class TelegramLoginLinkServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'v2board.app_url' => 'https://panel.example.test'
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->boolean('banned')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_telegram_login_link', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id')->unique();
            $table->unsignedBigInteger('telegram_chat_id')->nullable()->unique();
            $table->char('token_hash', 64)->unique();
            $table->integer('expires_at');
            $table->integer('consumed_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    public function testItIssuesAHashOnlySingleUseTelegramLoginLink(): void
    {
        $user = $this->user('1001');
        $service = new TelegramLoginLinkService();

        $url = $service->issue($user, '1001', 'dashboard', true);
        $token = $this->tokenFromUrl($url);
        $link = TelegramLoginLink::first();

        $this->assertSame('https://panel.example.test/#/login?verify=' . $token . '&redirect=dashboard', $url);
        $this->assertTrue(TelegramLoginLinkService::isLoginToken($token));
        $this->assertSame(hash('sha256', $token), $link->token_hash);
        $this->assertNotSame($token, $link->token_hash);
        $this->assertSame($user->id, $service->consume($token)->id);
        $this->assertNull($service->consume($token));
    }

    public function testItRejectsExpiredAndUnboundTelegramLinks(): void
    {
        $user = $this->user('1002');
        $service = new TelegramLoginLinkService();
        $expiredToken = $this->tokenFromUrl($service->issue($user, '1002', 'dashboard', true));
        TelegramLoginLink::query()->update(['expires_at' => time() - 1]);
        $this->assertNull($service->consume($expiredToken));

        TelegramLoginLink::query()->update(['created_at' => time() - 16]);
        $token = $this->tokenFromUrl($service->issue($user, '1002', 'dashboard', true));
        $user->telegram_id = 2002;
        $user->save();
        $this->assertNull($service->consume($token));
    }

    public function testItRateLimitsReissueAndRequiresHttpsForTelegram(): void
    {
        $user = $this->user('1003');
        $service = new TelegramLoginLinkService();
        $service->issue($user, '1003', 'dashboard', true);

        try {
            $service->issue($user, '1003', 'dashboard', true);
            $this->fail('Expected a reissue rate limit exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Login link was issued recently', $exception->getMessage());
        }

        config(['v2board.app_url' => 'http://panel.example.test']);
        $otherUser = $this->user('1004');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Telegram login requires HTTPS');
        $service->issue($otherUser, '1004', 'dashboard', true);
    }

    public function testItPrunesExpiredLinks(): void
    {
        $user = $this->user('1005');
        $service = new TelegramLoginLinkService();
        $service->issue($user, '1005', 'dashboard', true);
        TelegramLoginLink::query()->update(['expires_at' => time() - 1]);

        $this->assertSame(1, $service->prune());
        $this->assertSame(0, TelegramLoginLink::count());
    }

    public function testReissueInvalidatesThePreviousTokenAndStartsANewCooldown(): void
    {
        $user = $this->user('1006');
        $service = new TelegramLoginLinkService();
        $oldToken = $this->tokenFromUrl($service->issue($user, '1006', 'dashboard', true));
        TelegramLoginLink::query()->update(['created_at' => time() - 16]);

        $newToken = $this->tokenFromUrl($service->issue($user, '1006', 'dashboard', true));
        $this->assertNull($service->consume($oldToken));
        $this->assertSame($user->id, $service->consume($newToken)->id);

        try {
            $service->issue($user, '1006', 'dashboard', true);
            $this->fail('Expected a reissue rate limit exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Login link was issued recently', $exception->getMessage());
        }
    }

    private function user(string $telegramId): User
    {
        return User::create([
            'telegram_id' => $telegramId,
            'banned' => 0
        ]);
    }

    private function tokenFromUrl(string $url): string
    {
        $fragment = (string)parse_url($url, PHP_URL_FRAGMENT);
        parse_str((string)parse_url($fragment, PHP_URL_QUERY), $query);
        return $query['verify'];
    }
}
