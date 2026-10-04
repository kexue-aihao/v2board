<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminPlanControllerTest extends TestCase
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
        $this->url = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/plan';

        Schema::create('v2_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->text('content')->nullable();
            $table->integer('group_id');
            $table->integer('transfer_enable');
            $table->integer('device_limit')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->boolean('show')->default(true);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_subscription', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('plan_id');
            $table->integer('user_id');
            $table->integer('transfer_enable');
        });
        foreach (['v2_user', 'v2_order'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->increments('id');
                $table->integer('plan_id')->nullable();
            });
        }
        Schema::create('v2_reseller_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('base_plan_id');
        });
    }

    public function testSubscribedPlanCanSaveRichDescriptionWithoutChangingSubscriptions(): void
    {
        $plan = $this->plan();
        DB::table('v2_subscription')->insert([
            'id' => 7, 'plan_id' => $plan->id, 'user_id' => 9, 'transfer_enable' => 107374182400,
        ]);
        $html = '<div style="padding:20px;color:#fff;background:linear-gradient(145deg,#1a1a1a,#0e0e0e);border-radius:14px">'
            . '<ul style="list-style:none;padding-left:0"><li>✔ 每月 100G 流量</li></ul>'
            . '<a href="https://t.me/xiangye001_bot?start=reg" target="_blank" style="color:#00e0ff;text-decoration:none" rel="noopener noreferrer">@xiangye001_bot</a></div>';

        $this->postJson($this->url . '/save', [
            'id' => $plan->id, 'name' => $plan->name, 'group_id' => 1,
            'transfer_enable' => 100, 'content' => $html,
        ])->assertOk()->assertJsonPath('data', true);

        $saved = Plan::findOrFail($plan->id)->content;
        $this->assertStringContainsString('每月 100G 流量', $saved);
        $this->assertStringContainsString('background:linear-gradient(145deg,#1a1a1a,#0e0e0e)', $saved);
        $this->assertStringContainsString('href="https://t.me/xiangye001_bot?start=reg"', $saved);
        $this->assertSame(1, DB::table('v2_subscription')->count());
        $this->assertDatabaseHas('v2_subscription', [
            'id' => 7, 'plan_id' => $plan->id, 'user_id' => 9, 'transfer_enable' => 107374182400,
        ]);
    }

    public function testSubscribedPlanStillCannotBeDeleted(): void
    {
        $plan = $this->plan();
        DB::table('v2_subscription')->insert([
            'plan_id' => $plan->id, 'user_id' => 9, 'transfer_enable' => 107374182400,
        ]);

        $this->postJson($this->url . '/drop', ['id' => $plan->id])
            ->assertStatus(500)->assertJsonPath('message', '该套餐下存在订阅，无法删除');

        $this->assertDatabaseHas('v2_plan', ['id' => $plan->id]);
        $this->assertDatabaseHas('v2_subscription', ['plan_id' => $plan->id]);
    }

    public function testSubscriptionsOnAnotherPlanDoNotPreventDeletion(): void
    {
        $plan = $this->plan();
        $other = $this->plan();
        DB::table('v2_subscription')->insert([
            'plan_id' => $other->id, 'user_id' => 9, 'transfer_enable' => 107374182400,
        ]);

        $this->postJson($this->url . '/drop', ['id' => $plan->id])
            ->assertOk()->assertJsonPath('data', true);

        $this->assertDatabaseMissing('v2_plan', ['id' => $plan->id]);
        $this->assertDatabaseHas('v2_plan', ['id' => $other->id]);
        $this->assertDatabaseHas('v2_subscription', ['plan_id' => $other->id]);
    }

    public function testLegacyInstallWithoutSubscriptionsTableCanSaveAndDelete(): void
    {
        Schema::drop('v2_subscription');
        $plan = $this->plan();

        $this->postJson($this->url . '/save', [
            'id' => $plan->id, 'name' => $plan->name, 'group_id' => 1,
            'transfer_enable' => 100, 'content' => 'Updated description',
        ])->assertOk()->assertJsonPath('data', true);
        $this->assertSame('Updated description', Plan::findOrFail($plan->id)->content);

        $this->postJson($this->url . '/drop', ['id' => $plan->id])
            ->assertOk()->assertJsonPath('data', true);
        $this->assertDatabaseMissing('v2_plan', ['id' => $plan->id]);
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => '100G 套餐', 'group_id' => 1, 'transfer_enable' => 100,
            'content' => 'Original description', 'show' => 1,
        ]);
    }
}
