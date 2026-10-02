<?php

namespace Tests\Feature;

use App\Services\DynamicRateService;
use App\Services\RatePeakStateMachine;
use App\Services\RateResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * 动态倍率的配置、热路径解析与每分钟判定。
 *
 * Redis 全部用 facade mock：这一层要验证的是「读不到 / 挂了时怎么退化」，真连一个
 * Redis 反而测不出关键路径。
 */
class DynamicRateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('v2_rate_rule', function (Blueprint $table) {
            $table->increments('id');
            $table->string('scope')->default('global');
            $table->string('node_type')->default('');
            $table->integer('node_id')->default(0);
            $table->string('weekdays')->default('1,2,3,4,5,6,7');
            $table->smallInteger('start_minute')->default(0);
            $table->smallInteger('end_minute')->default(1440);
            $table->decimal('multiplier', 6, 3)->default(1);
            $table->boolean('enabled')->default(true);
            $table->string('remark')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_rate_setting', function (Blueprint $table) {
            $table->string('setting_key')->primary();
            $table->string('setting_value')->default('');
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_rate_state', function (Blueprint $table) {
            $table->integer('user_id')->primary();
            $table->decimal('multiplier', 6, 3)->default(1);
            $table->bigInteger('rate_bps')->default(0);
            $table->integer('high')->default(0);
            $table->integer('burst')->default(0);
            $table->string('state')->default('normal');
            $table->bigInteger('sampled_at')->default(0);
            $table->bigInteger('computed_at')->default(0);
        });
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
        });
        DB::table('v2_user')->insert(['id' => 12, 'email' => 'stacked@example.com']);
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function fakeRedis(array $rules = [], array $userMultipliers = [], ?int $lastTick = null): void
    {
        Redis::shouldReceive('get')->andReturnUsing(function ($key) use ($rules, $lastTick) {
            if ($key === DynamicRateService::KEY_RULES) {
                return json_encode($rules);
            }
            if ($key === DynamicRateService::KEY_LAST_TICK) {
                return $lastTick === null ? null : (string) $lastTick;
            }

            return null;
        });
        Redis::shouldReceive('hmget')->andReturnUsing(function ($key, $fields) use ($userMultipliers) {
            $values = [];
            foreach ($fields as $field) {
                $values[] = isset($userMultipliers[(int) $field]) ? (string) $userMultipliers[(int) $field] : null;
            }

            return $values;
        });
    }

    public function testSettingsFallBackToDefaultsWhenNothingIsStored(): void
    {
        $settings = (new DynamicRateService())->settings();

        $this->assertSame(0, $settings['enabled']);
        $this->assertSame(RatePeakStateMachine::DEFAULT_PARAMS['instant_mbps'], $settings['instant_mbps']);
        $this->assertSame(RatePeakStateMachine::DEFAULT_PARAMS['stack_multiplier'], $settings['stack_multiplier']);
    }

    public function testSaveSettingsClampsAndPersists(): void
    {
        $service = new DynamicRateService();
        $saved = $service->saveSettings([
            'enabled' => 1,
            'instant_mbps' => 30,
            'sustained_mbps' => 5,
            'burst_exempt_minutes' => 2,
            'stack_minutes' => 4,
            'stack_multiplier' => 1.25,
            'decay_step' => 2
        ]);

        $this->assertSame(1, $saved['enabled']);
        $this->assertSame(30.0, (new DynamicRateService())->settings()['instant_mbps']);
        $this->assertSame(1.25, (new DynamicRateService())->settings()['stack_multiplier']);
        $this->assertSame('30', DB::table('v2_rate_setting')->where('setting_key', 'instant_mbps')->value('setting_value'));
    }

    public function testRuleCrudNormalizesInput(): void
    {
        $service = new DynamicRateService();
        $id = $service->saveRule([
            'scope' => 'node',
            'node_type' => 'v2node',
            'node_id' => 7,
            'weekdays' => ['5', '1', '1', '9'],
            'start_minute' => 99999,
            'end_minute' => -5,
            'multiplier' => -3,
            'enabled' => 0,
            'remark' => '测试'
        ]);

        $rule = $service->rules()[0];
        $this->assertSame($id, (int) $rule['id']);
        $this->assertSame('1,5', $rule['weekdays']);          // 去重、排序、丢掉越界的 9
        $this->assertSame(1440, (int) $rule['start_minute']); // 夹到一天的上界
        $this->assertSame(0, (int) $rule['end_minute']);
        $this->assertSame(0.0, (float) $rule['multiplier']);  // 负倍率归零，不是保留负数
        $this->assertSame(0, (int) $rule['enabled']);

        // 全局规则不该带着节点信息
        $globalId = $service->saveRule(['scope' => 'global', 'node_type' => 'vmess', 'node_id' => 9]);
        $global = collect($service->rules())->firstWhere('id', $globalId);
        $this->assertSame('', $global['node_type']);
        $this->assertSame(0, (int) $global['node_id']);

        $this->assertTrue($service->deleteRule($globalId));
        $this->assertCount(1, $service->rules());
    }

    public function testOnlyEnabledRulesAreHandedToTheHotPath(): void
    {
        $service = new DynamicRateService();
        $service->saveRule(['scope' => 'global', 'multiplier' => 1.5, 'enabled' => 1]);
        $service->saveRule(['scope' => 'global', 'multiplier' => 9, 'enabled' => 0]);

        $this->assertCount(2, $service->rules());
        $this->assertCount(1, $service->rules(true));
    }

    public function testResolverMultipliesNodeBandAndUserFactors(): void
    {
        $this->fakeRedis([
            ['id' => 1, 'scope' => 'global', 'node_type' => '', 'node_id' => 0, 'weekdays' => '',
             'start_minute' => 0, 'end_minute' => 1440, 'multiplier' => 1.5, 'enabled' => 1]
        ], [12 => 1.5]);
        RateResolver::forgetRulesCache();

        $rates = (new RateResolver())->resolveForPush(['id' => 3, 'rate' => '2'], 'v2node', [12]);

        // 2（节点）× 1.5（时段）× 1.5（动态）= 4.5
        $this->assertSame(4.5, $rates[12]);
    }

    public function testResolverNeverChargesAnInvalidNodeRate(): void
    {
        $this->fakeRedis();
        RateResolver::forgetRulesCache();

        // rate 被手工改成 0 时，按 1 倍计费比把用户的流量记成 0 安全得多
        $rates = (new RateResolver())->resolveForPush(['id' => 3, 'rate' => '0'], 'v2node', [12]);
        $this->assertSame(1.0, $rates[12]);
    }

    public function testResolverFallsBackToNodeRateWhenRedisIsDown(): void
    {
        Redis::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));
        Redis::shouldReceive('hmget')->andThrow(new \RuntimeException('redis down'));
        RateResolver::forgetRulesCache();

        $rates = (new RateResolver())->resolveForPush(['id' => 3, 'rate' => '1.5'], 'vmess', [12, 13]);

        $this->assertSame(1.5, $rates[12]);
        $this->assertSame(1.5, $rates[13]);
    }

    public function testTickAdvancesStateAndPublishesStackedUsers(): void
    {
        $service = new DynamicRateService();
        $service->saveSettings(['enabled' => 1]);

        // 一分钟 150 MB ≈ 20 Mbps：超过持续阈值 10，但低于瞬时阈值 50
        $bytes = 150000000;
        $published = [];
        $this->fakeTickRedis($bytes, $published, time() - 60);

        for ($i = 0; $i < 5; $i++) {
            $result = $service->tick();
        }

        $this->assertSame(1, $result['sampled_users']);
        $state = DB::table('v2_rate_state')->where('user_id', 12)->first();
        $this->assertSame(5, (int) $state->high);
        $this->assertSame(1.5, (float) $state->multiplier);
        $this->assertSame(RatePeakStateMachine::STATE_STACKED, $state->state);
        $this->assertSame(20000000, (int) $state->rate_bps);

        // 热路径的键要在同一轮里发布出去
        $this->assertSame(['12' => '1.5'], $published);
    }

    public function testTickKeepsTrackingWhenTheSwitchIsOffButNeverCharges(): void
    {
        $service = new DynamicRateService();
        $service->saveSettings(['enabled' => 0]);

        $published = [];
        $this->fakeTickRedis(150000000, $published, time() - 60);

        for ($i = 0; $i < 5; $i++) {
            $service->tick();
        }

        $state = DB::table('v2_rate_state')->where('user_id', 12)->first();
        // 状态照推（管理页要看「开了会怎样」），但倍率与发布表都不动
        $this->assertSame(5, (int) $state->high);
        $this->assertSame(RatePeakStateMachine::STATE_STACKED, $state->state);
        $this->assertSame(1.0, (float) $state->multiplier);
        $this->assertSame([], $published);
    }

    public function testDryRunDoesNotTouchAnything(): void
    {
        $service = new DynamicRateService();
        $service->saveSettings(['enabled' => 1]);

        $published = [];
        $this->fakeTickRedis(150000000, $published, time() - 60);

        $result = $service->tick(true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, DB::table('v2_rate_state')->count());
        $this->assertSame([], $published);
    }

    /**
     * tick 用到的 Redis 交互：drain 原始计数、读上次 tick 时间、发布规则与倍率。
     *
     * @param array<string, string> $published 收集发布出去的 uid => 倍率
     */
    private function fakeTickRedis(int $bytes, array &$published, int $lastTick): void
    {
        Redis::shouldReceive('hgetall')->andReturnUsing(function ($key) use ($bytes) {
            return $key === DynamicRateService::KEY_RAW_UP ? ['12' => $bytes] : [];
        });
        Redis::shouldReceive('del')->andReturnTrue();
        Redis::shouldReceive('set')->andReturnTrue();
        Redis::shouldReceive('get')->andReturnUsing(function ($key) use ($lastTick) {
            return $key === DynamicRateService::KEY_LAST_TICK ? (string) $lastTick : null;
        });
        $pipeline = Mockery::mock();
        $pipeline->shouldReceive('del')->andReturnTrue();
        $pipeline->shouldReceive('hset')->andReturnUsing(function ($key, $field, $value) use (&$published) {
            $published[(string) $field] = (string) $value;

            return true;
        });
        $pipeline->shouldReceive('exec')->andReturnTrue();
        Redis::shouldReceive('pipeline')->andReturnUsing(function ($callback = null) use ($pipeline) {
            if ($callback) {
                $callback($pipeline);
            }

            return $pipeline;
        });
    }
}
