<?php

namespace Tests\Unit;

use App\Jobs\SendTelegramJob;
use App\Models\SubscriptionRiskNotify;
use App\Services\SubscriptionRiskNotifyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 提醒台账的两阶段行为：collect 只登记（幂等 + 未处理抑制），flush 才发送并标记。
 * 用 sqlite 内存库 + Queue::fake，发送路径不落网。
 */
class SubscriptionRiskNotifyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'v2board.risk_notify_enable' => 1,
            'v2board.risk_notify_threshold' => 60,
            'v2board.risk_notify_max_per_run' => 20,
            'v2board.telegram_bot_enable' => 1,
            'v2board.telegram_bot_token' => '123456:unit-test-token',
            'v2board.app_url' => 'https://panel.example.test',
            'v2board.secure_path' => 'securepath',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        // 手动评估表就位 ⇒ scoreSource() 走 manual；因此不需要建周期账本表。
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->unsignedTinyInteger('is_admin')->default(0);
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_subscription', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
        });
        Schema::create('v2_subscription_risk_manual', function (Blueprint $table) {
            $table->increments('id');
            $table->string('run_id', 32)->nullable();
            $table->integer('user_id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('status', 16)->default('no_data');
            $table->unsignedTinyInteger('risk_score')->nullable();
            $table->unsignedBigInteger('window_start')->default(0);
            $table->unsignedBigInteger('window_end')->default(0);
            $table->text('risk_reasons')->nullable();
            $table->text('metrics')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_subscription_risk_notify', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->unsignedBigInteger('subscription_id');
            $table->string('source', 16);
            $table->unsignedBigInteger('window_start');
            $table->unsignedBigInteger('window_end');
            $table->unsignedTinyInteger('risk_score');
            $table->text('reasons')->nullable();
            $table->integer('recipients')->default(0);
            $table->unsignedBigInteger('sent_at')->nullable();
            $table->unsignedBigInteger('handled_at')->nullable();
            $table->string('handled_by')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->unique(['subscription_id', 'source', 'window_start']);
        });

        Queue::fake();
    }

    private function makeSubscription(int $id, int $userId): void
    {
        DB::table('v2_subscription')->insert(['id' => $id, 'user_id' => $userId]);
    }

    private function makeAdmin(int $id, int $telegramId): void
    {
        DB::table('v2_user')->insert([
            'id' => $id,
            'email' => 'admin' . $id . '@example.test',
            'is_admin' => 1,
            'telegram_id' => $telegramId,
        ]);
    }

    private function makeScore(int $userId, int $subscriptionId, ?int $score, int $windowStart = 1000): void
    {
        DB::table('v2_subscription_risk_manual')->insert([
            'run_id' => 'run-1',
            'user_id' => $userId,
            'subscription_id' => $subscriptionId,
            'status' => $score === null ? 'no_data' : ($score > 0 ? 'suspicious' : 'normal'),
            'risk_score' => $score,
            'window_start' => $windowStart,
            'window_end' => $windowStart + 86400,
            'risk_reasons' => json_encode(['命中清洗策略「订阅 UA 种类过多」：订阅 UA 种类数 4 大于 3'], JSON_UNESCAPED_UNICODE),
            'metrics' => null,
            'created_at' => 1000,
            'updated_at' => 1000,
        ]);
    }

    public function testCollectRegistersOnlyRowsAtOrAboveThreshold(): void
    {
        $user = DB::table('v2_user')->insertGetId(['email' => 'member@example.test', 'is_admin' => 0, 'telegram_id' => null]);
        $this->makeSubscription(11, $user);
        $this->makeSubscription(12, $user);
        $this->makeScore($user, 11, 60);   // 正好到阈值
        $this->makeScore($user, 12, 59);   // 差一分
        $this->makeScore($user, 13, null); // no_data：没有分数，绝不能进待办

        $service = new SubscriptionRiskNotifyService();
        $this->assertSame(1, $service->collect());

        $rows = SubscriptionRiskNotify::get();
        $this->assertCount(1, $rows);
        $this->assertSame(11, (int)$rows[0]->subscription_id);
        $this->assertSame(60, (int)$rows[0]->risk_score);
        $this->assertNull($rows[0]->sent_at, 'collect 只登记，不发送');
        $this->assertSame(SubscriptionRiskNotify::SOURCE_MANUAL, $rows[0]->source);
    }

    public function testCollectIsIdempotentAndSuppressedUntilHandled(): void
    {
        $user = DB::table('v2_user')->insertGetId(['email' => 'member@example.test', 'is_admin' => 0, 'telegram_id' => null]);
        $this->makeSubscription(21, $user);
        $this->makeScore($user, 21, 80);

        $service = new SubscriptionRiskNotifyService();
        $this->assertSame(1, $service->collect());
        // 同窗口重复收集：唯一键挡住。
        $this->assertSame(0, $service->collect());
        // 换一个窗口（另一次手动评估）也不该重复打扰：该订阅还有未处理的待办。
        DB::table('v2_subscription_risk_manual')->where('subscription_id', 21)->update(['window_start' => 2000, 'window_end' => 3000]);
        $this->assertSame(0, $service->collect());

        // 管理员处理后，新窗口才会再次登记。
        SubscriptionRiskNotify::query()->update(['handled_at' => 2500]);
        $this->assertSame(1, $service->collect());
        $this->assertSame(2, SubscriptionRiskNotify::count());
    }

    public function testFlushSendsOneDigestToEveryBoundAdminAndMarksTheBatch(): void
    {
        $user = DB::table('v2_user')->insertGetId(['email' => 'member@example.test', 'is_admin' => 0, 'telegram_id' => null]);
        $this->makeSubscription(31, $user);
        $this->makeScore($user, 31, 75);
        $this->makeAdmin(1, 900000001);
        $this->makeAdmin(2, 900000002);
        // 没绑定 telegram_id 的管理员不该收到。
        DB::table('v2_user')->insert(['id' => 3, 'email' => 'unbound@example.test', 'is_admin' => 1, 'telegram_id' => null]);

        $service = new SubscriptionRiskNotifyService();
        $service->collect();
        $result = $service->flush();

        $this->assertSame(1, $result['sent']);
        $this->assertSame(2, $result['recipients']);
        Queue::assertPushed(SendTelegramJob::class, 2);

        $row = SubscriptionRiskNotify::first();
        $this->assertNotNull($row->sent_at);
        $this->assertSame(2, (int)$row->recipients);
    }

    public function testFlushKeepsRowsPendingWhenNoAdminCanReceive(): void
    {
        $user = DB::table('v2_user')->insertGetId(['email' => 'member@example.test', 'is_admin' => 0, 'telegram_id' => null]);
        $this->makeSubscription(41, $user);
        $this->makeScore($user, 41, 90);

        $service = new SubscriptionRiskNotifyService();
        $service->collect();
        $result = $service->flush();

        $this->assertSame(0, $result['sent']);
        Queue::assertNothingPushed();
        $this->assertNull(SubscriptionRiskNotify::first()->sent_at, '发不出去就不能标记为已发送，否则提醒会丢');
    }

    public function testDigestListsOnlyMaxPerRunRowsButMarksWholeBacklog(): void
    {
        config(['v2board.risk_notify_max_per_run' => 2]);
        $user = DB::table('v2_user')->insertGetId(['email' => 'member@example.test', 'is_admin' => 0, 'telegram_id' => null]);
        $this->makeAdmin(1, 900000001);
        foreach ([51, 52, 53] as $index => $subscriptionId) {
            $this->makeSubscription($subscriptionId, $user);
            $this->makeScore($user, $subscriptionId, 70 + $index);
        }

        $service = new SubscriptionRiskNotifyService();
        $service->collect();
        $result = $service->flush();

        $this->assertSame(3, $result['sent'], '整批一起标记，避免 15 分钟后又来一条');
        $this->assertSame(2, $result['detailed'], '摘要只展开 max_per_run 行，其余只报数量');
        Queue::assertPushed(SendTelegramJob::class, 1);
        $this->assertSame(3, SubscriptionRiskNotify::whereNotNull('sent_at')->count());
    }

    public function testRunIsNoOpWithoutUpgradedSchema(): void
    {
        Schema::drop('v2_subscription_risk_notify');

        $service = new SubscriptionRiskNotifyService();
        $result = $service->run();

        $this->assertSame(0, $result['collected']);
        $this->assertSame(0, $result['sent']);
        Queue::assertNothingPushed();
    }
}
