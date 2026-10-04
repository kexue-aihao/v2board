<?php

namespace Tests\Feature;

use App\Http\Middleware\Admin;
use App\Jobs\TrafficFetchJob;
use App\Services\DynamicRateService;
use App\Services\RatePolicySchema;
use App\Services\RatePolicyService;
use App\Services\RateResolver;
use App\Services\ServerIdService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RatePolicyTest extends TestCase
{
    private $url;
    private $redis;
    private const UID = 2000000001;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite'); DB::reconnect('sqlite');
        $this->withoutMiddleware(Admin::class);
        $this->url = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/rate';
        $this->redis = new RatePolicyMemoryRedis();
        Redis::swap($this->redis);
        Schema::create('v2_rate_setting', function (Blueprint $t) { $t->string('setting_key')->primary(); $t->string('setting_value'); $t->integer('updated_at'); });
        Schema::create('v2_rate_rule', function (Blueprint $t) {
            $t->increments('id'); $t->string('scope'); $t->string('node_type'); $t->integer('node_id'); $t->string('weekdays');
            $t->integer('start_minute'); $t->integer('end_minute'); $t->decimal('multiplier', 6, 3); $t->integer('enabled');
            $t->string('remark')->nullable(); $t->integer('created_at'); $t->integer('updated_at');
        });
        Schema::create('v2_user', function (Blueprint $t) { $t->increments('id'); $t->string('email'); });
        Schema::create('v2_subscription', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedBigInteger('node_user_id')->unique(); $t->integer('user_id'); $t->boolean('is_primary');
        });
        DB::table('v2_user')->insert([['id' => 1, 'email' => 'one@example.test'], ['id' => 2, 'email' => 'two@example.test']]);
        DB::table('v2_subscription')->insert([
            ['id' => 1, 'node_user_id' => self::UID, 'user_id' => 1, 'is_primary' => 1],
            ['id' => 2, 'node_user_id' => self::UID + 1, 'user_id' => 1, 'is_primary' => 0],
            ['id' => 3, 'node_user_id' => self::UID + 2, 'user_id' => 2, 'is_primary' => 1],
        ]);
        foreach (array_keys(ServerIdService::TYPES) as $type) {
            Schema::create('v2_server_' . $type, function (Blueprint $t) {
                $t->increments('id'); $t->string('name'); $t->string('rate')->default('2'); $t->integer('sort')->default(0);
                $t->integer('parent_id')->nullable(); $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
            });
            for ($id = 1; $id <= 4; $id++) DB::table('v2_server_' . $type)->insert(['id' => $id, 'name' => $type . '-' . $id]);
        }
        RatePolicySchema::install();
    }

    private function params(array $extra = []): array
    {
        return $extra + ['name' => '专线', 'enabled' => 1, 'instant_mbps' => 50, 'sustained_mbps' => 15,
            'burst_exempt_minutes' => 3, 'stack_minutes' => 2, 'stack_multiplier' => 1.5, 'decay_step' => 1];
    }

    private function policy(array $extra = []): int
    {
        return $this->postJson($this->url . '/policy/save', $this->params($extra))->assertOk()->json('data.id');
    }

    private function bind(array $nodes, string $mode, ?int $id = null): array
    {
        $params = ['nodes' => $nodes, 'mode' => $mode];
        if ($id !== null) $params['policy_id'] = $id;
        $preview = $this->postJson($this->url . '/binding/preview', $params)->assertOk()->json('data');
        return $this->postJson($this->url . '/binding/apply', $params + ['revision' => $preview['revision'], 'confirm' => true])->assertOk()->json('data');
    }

    private function report(string $type, int $id, int $bytes, int $uid = self::UID): array
    {
        $server = (array) DB::table('v2_server_' . $type)->where('id', $id)->first();
        $context = (new RateResolver())->resolveTraffic($server, $type, [$uid]);
        (new TrafficFetchJob([$uid => [$bytes, 0]], $server, $type, $context['rates'], $context['sampling']))->handle();
        return $context;
    }

    private function tick(): array
    {
        $this->redis->set(RatePolicyService::LAST_TICK, time() - 60);
        return (new DynamicRateService())->tick();
    }

    public function testSchemaIsIdempotentAndExistingNodesInheritGlobalWithoutNewBindings(): void
    {
        $id = $this->policy(); RatePolicySchema::install();
        $this->assertSame(1, DB::table('v2_rate_policy')->count());
        $node = (new RatePolicyService())->annotate([['type' => 'vmess', 'id' => 1]])[0];
        $this->assertSame('global', $node['rate_policy']['mode']);
        $this->assertSame(0, $node['rate_policy']['policy_id']);
        $this->assertSame(0, DB::table('v2_rate_node_policy')->count());
    }

    public function testScenesAggregateAcrossNodesButIsolateOtherScenesAndSubscriptions(): void
    {
        $a = $this->policy();
        $b = $this->policy(['name' => '普通线路', 'stack_multiplier' => 2]);
        $this->bind([['type' => 'vmess', 'id' => 1], ['type' => 'trojan', 'id' => 1]], 'policy', $a);
        $this->bind([['type' => 'vmess', 'id' => 2]], 'policy', $b);
        for ($minute = 0; $minute < 2; $minute++) {
            // Neither node alone crosses 15 Mbps; the scene total is 16 Mbps.
            $this->report('vmess', 1, 60000000); $this->report('trojan', 1, 60000000); $this->tick();
        }
        $resolver = new RateResolver();
        $this->assertSame([self::UID => 3.0, self::UID + 1 => 2.0], $resolver->resolveForPush(['id' => 1, 'rate' => 2], 'vmess', [self::UID, self::UID + 1]));
        $this->assertSame(3.0, $resolver->resolveForPush(['id' => 1, 'rate' => 2], 'trojan', [self::UID])[self::UID]);
        $this->assertSame(2.0, $resolver->resolveForPush(['id' => 2, 'rate' => 2], 'vmess', [self::UID])[self::UID]);
        $this->assertSame(1, DB::table('v2_rate_policy_state')->count());
        $this->assertSame(16000000, (int) DB::table('v2_rate_policy_state')->value('rate_bps'));
        $this->getJson($this->url . '/fetch?keyword=one@example.test&policy_id=' . $a)->assertOk()
            ->assertJsonPath('data.states.rows.0.user_id', 1)->assertJsonPath('data.states.rows.0.subscription_id', 1)
            ->assertJsonPath('data.states.rows.0.node_user_id', self::UID)->assertJsonPath('data.states.rows.0.policy_name', '专线');
        $this->getJson($this->url . '/fetch?keyword=two@example.test')->assertJsonPath('data.states.total', 0);
        $this->postJson($this->url . '/explain', ['user_id' => 1, 'node_user_id' => self::UID])->assertOk()
            ->assertJsonPath('data.subscription_id', 1);
        $this->postJson($this->url . '/explain', ['user_id' => 2, 'node_user_id' => self::UID])->assertStatus(422);
    }

    public function testIncompleteSchemaCannotAcceptUnversionedPolicyChanges(): void
    {
        DB::table(DynamicRateService::TABLE_SETTING)->where('setting_key', 'policy_config_revision')->delete();
        $this->assertFalse((new RatePolicyService())->ready());
        $this->postJson($this->url . '/policy/save', $this->params())->assertStatus(503);
        RatePolicySchema::install();
        $this->assertTrue((new RatePolicyService())->ready());
        $this->policy();
    }

    public function testOffModeKeepsBaseAndTimeRateAndDoesNotContributeToAnyScene(): void
    {
        $this->policy();
        $this->postJson($this->url . '/settings/save', $this->params())->assertOk();
        $this->postJson($this->url . '/rule/save', ['scope' => 'global', 'start_minute' => 0, 'end_minute' => 1440, 'multiplier' => 1.25])->assertOk();
        $this->bind([['type' => 'vmess', 'id' => 1]], 'off');
        $context = $this->report('vmess', 1, 150000000);
        $this->assertSame(2.5, $context['rates'][self::UID]);
        $this->assertSame([], $this->redis->hgetall(RatePolicyService::SAMPLES));
        $this->tick();
        $this->assertSame(0, DB::table('v2_rate_policy_state')->count());
    }

    public function testInheritedGlobalAndExplicitScenesNeverMultiplyTogether(): void
    {
        $a = $this->policy(['stack_multiplier' => 2]);
        $this->postJson($this->url . '/settings/save', $this->params())->assertOk();
        $this->bind([['type' => 'vmess', 'id' => 1]], 'policy', $a);
        for ($i = 0; $i < 2; $i++) {
            $this->report('vmess', 1, 150000000); $this->report('vmess', 2, 150000000); $this->tick();
        }
        $this->assertSame(4.0, $this->report('vmess', 1, 0)['rates'][self::UID]);
        $this->assertSame(3.0, $this->report('vmess', 2, 0)['rates'][self::UID]);
        $this->postJson($this->url . '/settings/save', $this->params(['enabled' => 0]))->assertOk();
        $this->assertSame(2.0, $this->report('vmess', 2, 0)['rates'][self::UID]);
        $this->assertSame(4.0, $this->report('vmess', 1, 0)['rates'][self::UID]);
    }

    public function testPolicyEditsInvalidateOldMultipliersAndQueuedSamplesWithoutWorkerRestart(): void
    {
        $a = $this->policy();
        $this->bind([['type' => 'vmess', 'id' => 1]], 'policy', $a);
        for ($i = 0; $i < 2; $i++) { $this->report('vmess', 1, 150000000); $this->tick(); }
        $server = ['id' => 1, 'rate' => 2];
        $context = (new RateResolver())->resolveTraffic($server, 'vmess', [self::UID]);
        $this->assertSame(3.0, $context['rates'][self::UID]);
        $this->postJson($this->url . '/policy/save', $this->params(['id' => $a, 'revision' => 1, 'enabled' => 0]))->assertOk();
        $this->assertSame(2.0, (new RateResolver())->resolveForPush($server, 'vmess', [self::UID])[self::UID]);
        (new TrafficFetchJob([self::UID => [150000000, 0]], $server, 'vmess', $context['rates'], $context['sampling']))->handle();
        $this->tick();
        $this->assertSame(0, (int) DB::table('v2_rate_policy_state')->value('high'));
    }

    public function testStoppingTrafficDecaysAndMissingRuntimeNeverReusesLegacyGlobalPenalties(): void
    {
        $a = $this->policy(); $this->bind([['type' => 'vmess', 'id' => 1]], 'policy', $a);
        for ($i = 0; $i < 2; $i++) { $this->report('vmess', 1, 150000000); $this->tick(); }
        $this->tick();
        $this->assertSame(2.0, $this->report('vmess', 1, 0)['rates'][self::UID]);
        $this->redis->hset(DynamicRateService::KEY_USER_MULT, self::UID, '99');
        $this->redis->del(RatePolicyService::CONFIG);
        $this->assertSame(2.0, $this->report('vmess', 1, 0)['rates'][self::UID]);
    }

    public function testBatchBindingValidatesAndProtectsConcurrentEditsAcrossEveryNodeType(): void
    {
        $a = $this->policy();
        $nodes = array_map(function ($type) { return ['type' => $type, 'id' => 1]; }, array_keys(ServerIdService::TYPES));
        $result = $this->bind($nodes, 'policy', $a);
        $this->assertSame(8, $result['changed_count']);
        $this->assertSame(8, DB::table('v2_rate_node_policy')->count());
        $params = ['nodes' => $nodes, 'mode' => 'off'];
        $preview = $this->postJson($this->url . '/binding/preview', $params)->assertOk()->json('data');
        $this->postJson($this->url . '/policy/save', $this->params(['id' => $a, 'revision' => 1, 'name' => '改名']))->assertOk();
        $this->postJson($this->url . '/binding/apply', $params + ['confirm' => true, 'revision' => $preview['revision']])->assertStatus(409);
        $this->assertSame(8, DB::table('v2_rate_node_policy')->where('mode', 'policy')->count());
        $this->postJson($this->url . '/binding/apply', $params)->assertStatus(422);
        $this->postJson($this->url . '/binding/preview', ['nodes' => [$nodes[0], $nodes[0]], 'mode' => 'global'])->assertStatus(422);
        $this->postJson($this->url . '/binding/preview', ['nodes' => [['type' => 'vmess', 'id' => 99]], 'mode' => 'global'])->assertStatus(404);
        $this->postJson($this->url . '/binding/preview', ['nodes' => $nodes, 'mode' => 'policy', 'policy_id' => 999])->assertStatus(422);
        $this->postJson($this->url . '/binding/preview', ['nodes' => $nodes, 'mode' => 'bogus'])->assertStatus(422);
        $this->postJson($this->url . '/policy/drop', ['id' => $a, 'revision' => 2])->assertStatus(409);
        $this->bind($nodes, 'global');
        $this->postJson($this->url . '/policy/drop', ['id' => $a, 'revision' => 2])->assertOk();
    }

    public function testPublishFailureRollsBackConfigurationAndBindings(): void
    {
        $a = $this->policy();
        $params = ['nodes' => [['type' => 'vmess', 'id' => 1]], 'mode' => 'policy', 'policy_id' => $a];
        $preview = $this->postJson($this->url . '/binding/preview', $params)->assertOk()->json('data');
        $before = $this->redis->get(RatePolicyService::CONFIG);
        $this->redis->failSet = true;
        $this->postJson($this->url . '/binding/apply', $params + ['revision' => $preview['revision'], 'confirm' => true])->assertStatus(500);
        $this->assertSame(0, DB::table('v2_rate_node_policy')->count());
        $this->assertSame($before, $this->redis->get(RatePolicyService::CONFIG));
    }

    public function testPolicyWritesRejectInvalidParametersStaleRevisionsAndNonAdmins(): void
    {
        $id = $this->policy();
        $this->postJson($this->url . '/policy/save', $this->params(['sustained_mbps' => 51]))->assertStatus(422);
        $this->postJson($this->url . '/policy/save', $this->params(['name' => '']))->assertStatus(422);
        $this->postJson($this->url . '/policy/save', $this->params(['id' => $id, 'revision' => 2]))->assertStatus(409);
        $this->withMiddleware(Admin::class);
        $this->postJson($this->url . '/policy/save', $this->params())->assertStatus(403);
    }

    public function testDryRunDoesNotConsumeSamplesOrWriteState(): void
    {
        $a = $this->policy(); $this->bind([['type' => 'vmess', 'id' => 1]], 'policy', $a);
        $this->report('vmess', 1, 150000000);
        $before = $this->redis->data;
        (new DynamicRateService())->tick(true);
        $this->assertSame($before, $this->redis->data);
        $this->assertSame(0, DB::table('v2_rate_policy_state')->count());
    }

    public function testNodeIdChangesMoveBindingsAndNodeDeletionDoesNotLeakRolledBackChanges(): void
    {
        Schema::create('v2_stat_server', function (Blueprint $t) { $t->integer('server_id'); $t->string('server_type'); });
        $id = $this->policy(); $this->bind([['type' => 'vmess', 'id' => 1]], 'policy', $id);
        $node = \App\Models\ServerVmess::find(1);
        ServerIdService::changeId('vmess', $node, 19);
        $this->assertDatabaseHas('v2_rate_node_policy', ['node_type' => 'vmess', 'node_id' => 19, 'policy_id' => $id]);
        $this->assertSame($id, (new RatePolicyService())->runtime()['bindings']['vmess:19']['policy_id']);
        $before = $this->redis->get(RatePolicyService::CONFIG);
        DB::beginTransaction();
        $node->delete();
        $this->assertSame($before, $this->redis->get(RatePolicyService::CONFIG));
        DB::rollBack();
        $this->assertDatabaseHas('v2_rate_node_policy', ['node_id' => 19]);
        DB::transaction(function () { \App\Models\ServerVmess::find(19)->delete(); });
        $this->assertSame(0, DB::table('v2_rate_node_policy')->count());
        $this->assertArrayNotHasKey('vmess:19', (new RatePolicyService())->runtime()['bindings']);
    }

    public function testMultiplierExpiryFallsBackToOneAndTimeRulesPublishImmediately(): void
    {
        $id = $this->policy(); $this->bind([['type' => 'vmess', 'id' => 1]], 'policy', $id);
        for ($i = 0; $i < 2; $i++) { $this->report('vmess', 1, 150000000); $this->tick(); }
        $this->assertSame(3.0, $this->report('vmess', 1, 0)['rates'][self::UID]);
        $this->redis->expire(RatePolicyService::MULTIPLIERS, -1);
        $this->assertSame(2.0, $this->report('vmess', 1, 0)['rates'][self::UID]);
        $this->postJson($this->url . '/rule/save', ['scope' => 'global', 'start_minute' => 0, 'end_minute' => 1440, 'multiplier' => 1.25])->assertOk();
        $this->assertSame(2.5, $this->report('vmess', 1, 0)['rates'][self::UID]);
    }

    public function testNodeLifecycleCleanupSurvivesRedisPublicationFailure(): void
    {
        $id = $this->policy();
        $this->bind([['type' => 'vmess', 'id' => 1], ['type' => 'vmess', 'id' => 2]], 'policy', $id);
        $this->redis->failSet = true;
        try {
            \App\Models\ServerVmess::find(1)->delete();
            $this->fail('Expected Redis publication failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('redis unavailable', $error->getMessage());
        }
        $this->assertDatabaseMissing('v2_server_vmess', ['id' => 1]);
        $this->assertDatabaseMissing('v2_rate_node_policy', ['node_type' => 'vmess', 'node_id' => 1]);

        // A previously orphaned binding must also be cleared when an ID is reused.
        DB::table('v2_server_vmess')->where('id', 2)->delete();
        try {
            ServerIdService::createWithId('vmess', ['name' => 'Replacement'], 2);
            $this->fail('Expected Redis publication failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('redis unavailable', $error->getMessage());
        }
        $this->assertDatabaseHas('v2_server_vmess', ['id' => 2, 'name' => 'Replacement']);
        $this->assertDatabaseMissing('v2_rate_node_policy', ['node_type' => 'vmess', 'node_id' => 2]);
        $this->redis->failSet = false;
        $this->tick();
        $config = (new RatePolicyService())->runtime();
        $this->assertArrayNotHasKey('vmess:1', $config['bindings']);
        $this->assertArrayNotHasKey('vmess:2', $config['bindings']);
        $this->assertSame(0, (new RatePolicyService())->policyFor($config, 'vmess', 2)['id']);
    }
}

/** Redis command contract used by the production resolver, collector and tick. */
class RatePolicyMemoryRedis
{
    public $data = [];
    private $expires = [];
    public $failSet = false;
    public function get($key) { return $this->data[$key] ?? null; }
    public function set($key, $value) { if ($this->failSet) throw new \RuntimeException('redis unavailable'); $this->data[$key] = $value; return true; }
    public function hgetall($key) { if (($this->expires[$key] ?? PHP_INT_MAX) <= time()) $this->del($key); return $this->data[$key] ?? []; }
    public function hset($key, $field, $value) { $this->data[$key][$field] = $value; return 1; }
    public function hincrby($key, $field, $value) { $this->data[$key][$field] = ($this->data[$key][$field] ?? 0) + (int) $value; }
    public function hmget($key, $fields) { $this->hgetall($key); return array_map(function ($field) use ($key) { return $this->data[$key][$field] ?? null; }, $fields); }
    public function del($key) { unset($this->data[$key], $this->expires[$key]); return 1; }
    public function expire($key, $seconds) { $this->expires[$key] = time() + $seconds; return true; }
    public function rename($from, $to) { $this->data[$to] = $this->data[$from]; $this->expires[$to] = $this->expires[$from] ?? PHP_INT_MAX; $this->del($from); return true; }
    public function pipeline($callback) { $callback($this); }
    public function eval($script, $numKeys, $key) {
        $result = []; foreach ($this->hgetall($key) as $field => $value) { $result[] = (string) $field; $result[] = (string) $value; }
        $this->del($key); return $result;
    }
}
