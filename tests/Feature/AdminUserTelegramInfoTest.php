<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Services\TelegramService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class AdminUserTelegramInfoTest extends TestCase
{
    private $url;
    private $telegram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->url = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/user/telegramInfo';
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'app.key' => str_repeat('test', 16),
            'v2board.telegram_bot_token' => 'test-bot-token',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $this->telegram = Mockery::mock(TelegramService::class);
        $this->app->instance(TelegramService::class, $this->telegram);
    }

    private function login(bool $admin = true): void
    {
        $user = User::create(['email' => 'operator@example.test', 'is_admin' => $admin]);
        if ($admin) {
            \Tests\Support\AdminSecurityFixture::install();
            $user->refresh();
        }
        $auth = (new AuthService($user))->generateAuthData(Request::create('/'), true);
        $this->withHeader('authorization', $auth['auth_data']);
    }

    public function testOnlyAdministratorsCanReadTelegramBindings(): void
    {
        $this->telegram->shouldNotReceive('getChat');
        $this->getJson($this->url . '?id=1')->assertStatus(403);
        $this->login(false);
        $this->getJson($this->url . '?id=1')->assertStatus(403);
    }

    public function testItValidatesTheUserAndHandlesMissingAccounts(): void
    {
        $this->login();
        $this->telegram->shouldNotReceive('getChat');
        $this->getJson($this->url)->assertStatus(422);
        $this->getJson($this->url . '?id=0')->assertStatus(422);
        $this->getJson($this->url . '?id=abc')->assertStatus(422);
        $this->getJson($this->url . '?id=999')->assertStatus(404);
    }

    public function testUnboundAccountsDoNotQueryTelegram(): void
    {
        $this->login();
        $this->telegram->shouldNotReceive('getChat');
        foreach ([null, 0] as $telegramId) {
            $user = User::create(['email' => 'unbound@example.test', 'telegram_id' => $telegramId]);
            $this->getJson($this->url . '?id=' . $user->id)->assertOk()
                ->assertJsonPath('data.bound', false)
                ->assertJsonPath('data.telegram_id', null)
                ->assertJsonPath('data.username', null)
                ->assertJsonPath('data.username_status', 'unbound');
        }
    }

    public function testItReadsTheCurrentUsernameAndKeepsTheUidAsAString(): void
    {
        $this->login();
        $uid = '4503599627370001';
        $user = User::create(['email' => 'bound@example.test', 'telegram_id' => $uid]);
        $this->telegram->shouldReceive('getChat')->once()->with($uid)->andReturn((object)[
            'result' => (object)['id' => (int)$uid, 'type' => 'private', 'username' => 'alice_updated'],
        ]);
        $this->getJson($this->url . '?id=' . $user->id)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['data' => [
                'bound' => true, 'telegram_id' => $uid, 'username' => 'alice_updated',
                'username_status' => 'available', 'message' => '',
            ]]);
        $this->assertSame($uid, (string)$user->fresh()->telegram_id);
    }

    public function testAnAccountWithoutAUsernameStillDisplaysItsBinding(): void
    {
        $this->login();
        $user = User::create(['email' => 'no-name@example.test', 'telegram_id' => 1002]);
        $this->telegram->shouldReceive('getChat')->once()->with('1002')->andReturn((object)[
            'result' => (object)['id' => 1002, 'type' => 'private'],
        ]);
        $this->getJson($this->url . '?id=' . $user->id)->assertOk()
            ->assertJsonPath('data.bound', true)
            ->assertJsonPath('data.telegram_id', '1002')
            ->assertJsonPath('data.username', null)
            ->assertJsonPath('data.username_status', 'not_set');
    }

    public function testMissingBotConfigurationStillDisplaysTheUid(): void
    {
        $this->login();
        config(['v2board.telegram_bot_token' => '']);
        $user = User::create(['email' => 'bound@example.test', 'telegram_id' => 1003]);
        $this->telegram->shouldNotReceive('getChat');
        $this->getJson($this->url . '?id=' . $user->id)->assertOk()
            ->assertJsonPath('data.bound', true)
            ->assertJsonPath('data.telegram_id', '1003')
            ->assertJsonPath('data.username_status', 'unavailable');
    }

    public function testTelegramFailuresDoNotLeakTransportDetailsOrHideTheUid(): void
    {
        $this->login();
        $user = User::create(['email' => 'bound@example.test', 'telegram_id' => 1004]);
        $this->telegram->shouldReceive('getChat')->once()->with('1004')
            ->andThrow(new \RuntimeException('https://api.telegram.org/bottest-bot-token/getChat failed'));
        $this->getJson($this->url . '?id=' . $user->id)->assertOk()
            ->assertJsonPath('data.bound', true)
            ->assertJsonPath('data.telegram_id', '1004')
            ->assertJsonPath('data.username_status', 'unavailable')
            ->assertDontSee('test-bot-token');
    }

    public function testItRejectsProfilesFromAnotherAccountOrAGroup(): void
    {
        $this->login();
        $user = User::create(['email' => 'bound@example.test', 'telegram_id' => 1005]);
        $this->telegram->shouldReceive('getChat')->twice()->with('1005')->andReturn(
            (object)['result' => (object)['id' => 1006, 'type' => 'private', 'username' => 'other']],
            (object)['result' => (object)['id' => 1005, 'type' => 'group', 'username' => 'group']]
        );
        for ($i = 0; $i < 2; $i++) {
            $this->getJson($this->url . '?id=' . $user->id)->assertOk()
                ->assertJsonPath('data.telegram_id', '1005')
                ->assertJsonPath('data.username', null)
                ->assertJsonPath('data.username_status', 'unavailable');
        }
    }
}
