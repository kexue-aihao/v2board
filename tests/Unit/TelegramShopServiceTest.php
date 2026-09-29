<?php

namespace Tests\Unit;

use App\Models\Plan;
use App\Models\User;
use App\Services\TelegramService;
use App\Services\TelegramShopService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TelegramShopServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('show')->default(true);
            $table->boolean('renew')->default(true);
            $table->integer('sort')->default(0);
            $table->integer('month_price')->nullable();
            $table->integer('quarter_price')->nullable();
            $table->integer('half_year_price')->nullable();
            $table->integer('year_price')->nullable();
            $table->integer('onetime_price')->nullable();
            $table->integer('reset_price')->nullable();
            $table->integer('capacity_limit')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            $table->string('trade_no')->unique();
            $table->integer('user_id');
            $table->integer('status')->default(0);
            $table->integer('total_amount')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function testShowPlansOnlyExposesVisiblePlansWithCallbacks(): void
    {
        User::create(['telegram_id' => 3001]);
        Plan::create([
            'name' => '隐藏套餐',
            'show' => 0,
            'sort' => 1,
            'month_price' => 100,
        ]);
        Plan::create([
            'name' => '基础套餐',
            'show' => 1,
            'sort' => 2,
            'month_price' => 199,
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(3001, '请选择套餐：', '', Mockery::on(function ($markup) {
                return isset($markup['inline_keyboard'][0][0]['callback_data'])
                    && $markup['inline_keyboard'][0][0]['callback_data'] === 'shop:plan:2'
                    && $markup['inline_keyboard'][0][0]['text'] === '基础套餐  1.99 元起';
            }));

        (new TelegramShopService($telegram))->showPlans(3001);
    }

    public function testUnboundUsersReceiveARegistrationPath(): void
    {
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(3002, Mockery::pattern('/还没有绑定账号/'), '', null);

        (new TelegramShopService($telegram))->showPlans(3002);
    }

    public function testNonShopCallbacksAreLeftForTheEntertainmentHandler(): void
    {
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldNotReceive('sendMessage');
        $service = new TelegramShopService($telegram);

        $this->assertFalse($service->handleCallback([
            'id' => 'callback-1',
            'data' => 'reward:checkin',
            'message' => ['chat' => ['id' => 3003]],
        ]));
    }

    public function testPaymentCallbackCannotPayAnotherUsersOrder(): void
    {
        User::create(['telegram_id' => 3004]);
        DB::table('v2_order')->insert([
            'trade_no' => 'order-owned-by-someone-else',
            'user_id' => 999,
            'status' => 0,
            'total_amount' => 100,
        ]);

        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->with(3004, '订单不存在或已支付。', '', null);

        (new TelegramShopService($telegram))->pay(3004, 'order-owned-by-someone-else', 1);
    }
}
