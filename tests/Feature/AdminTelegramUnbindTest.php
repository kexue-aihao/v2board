<?php

namespace Tests\Feature;

use App\Http\Middleware\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 后台解绑用户 Telegram 账号。
 *
 * 「绑定」就是 v2_user.telegram_id 一个字段，所以这里只验证接口层的三件事：
 * 真的清掉了、没绑定时明确报错（而不是静默成功）、以及必须带 confirm。
 */
class AdminTelegramUnbindTest extends TestCase
{
    private $url;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->withoutMiddleware(Admin::class);
        $this->url = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/user/telegramUnbind';

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->string('telegram_id')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function testUnbindingClearsTheTelegramId(): void
    {
        DB::table('v2_user')->insert([
            'id' => 17, 'email' => 'member@example.test', 'telegram_id' => '4503599627370001',
            'created_at' => time(), 'updated_at' => time()
        ]);

        $this->postJson($this->url, ['id' => 17, 'confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.bound', false)
            ->assertJsonPath('data.telegram_id', null);

        // 未绑定在库里就是 NULL（迁移把 0 与空串都归一成 NULL）
        $this->assertNull(DB::table('v2_user')->where('id', 17)->value('telegram_id'));
        // 只动这一个字段：邮箱等其它列不受影响
        $this->assertSame('member@example.test', DB::table('v2_user')->where('id', 17)->value('email'));
    }

    public function testAnUnboundUserIsReportedInsteadOfSilentlySucceeding(): void
    {
        DB::table('v2_user')->insert(['id' => 18, 'email' => 'plain@example.test', 'telegram_id' => null, 'created_at' => time(), 'updated_at' => time()]);

        $this->postJson($this->url, ['id' => 18, 'confirm' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', '该用户尚未绑定 Telegram 账号');
    }

    public function testConfirmationAndUserExistenceAreRequired(): void
    {
        DB::table('v2_user')->insert(['id' => 19, 'email' => 'x@example.test', 'telegram_id' => '12345', 'created_at' => time(), 'updated_at' => time()]);

        // 不带 confirm 不能解绑
        $this->postJson($this->url, ['id' => 19])
            ->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertSame('12345', DB::table('v2_user')->where('id', 19)->value('telegram_id'));

        $this->postJson($this->url, ['confirm' => true])
            ->assertStatus(422)->assertJsonValidationErrors('id');

        $this->postJson($this->url, ['id' => 999, 'confirm' => true])
            ->assertStatus(500)->assertJsonPath('message', '用户不存在');
    }
}
