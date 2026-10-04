<?php

namespace Tests\Feature;

use App\Http\Middleware\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 管理页「动态倍率」的接口：一次取全、规则增删、参数保存与互相矛盾的参数。
 */
class AdminRateControllerTest extends TestCase
{
    private $url;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->withoutMiddleware(Admin::class);
        $this->url = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/rate';

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
        Schema::create('v2_server_vmess', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('rate')->default('1');
            $table->integer('sort')->default(0);
        });
        DB::table('v2_user')->insert(['id' => 7, 'email' => 'peak@example.com']);
        DB::table('v2_server_vmess')->insert(['id' => 3, 'name' => 'vmess-a', 'rate' => '2', 'sort' => 0]);
    }

    public function testFetchReturnsEverythingThePageNeeds(): void
    {
        $response = $this->getJson($this->url . '/fetch')->assertOk();

        // 参数还没存过时应回落到默认值，页面一打开就有东西可显示
        $response->assertJsonPath('data.settings.enabled', 0);
        // JSON encodes whole-valued floats as integers without JSON_PRESERVE_ZERO_FRACTION.
        $response->assertJsonPath('data.settings.instant_mbps', 50);
        $response->assertJsonPath('data.rules', []);
        $response->assertJsonPath('data.states.total', 0);
        $response->assertJsonPath('data.nodes.0.type', 'vmess');
        $response->assertJsonPath('data.nodes.0.name', 'vmess-a');
    }

    public function testRuleLifecycle(): void
    {
        $id = $this->postJson($this->url . '/rule/save', [
            'scope' => 'global',
            'weekdays' => [1, 2, 3, 4, 5],
            'start_minute' => 1200,
            'end_minute' => 1380,
            'multiplier' => 1.5,
            'enabled' => 1,
            'remark' => '晚高峰'
        ])->assertOk()->json('data.id');

        $this->getJson($this->url . '/fetch')
            ->assertJsonPath('data.rules.0.id', $id)
            ->assertJsonPath('data.rules.0.weekdays', '1,2,3,4,5')
            ->assertJsonPath('data.rules.0.start_minute', 1200);
        // 小数在 sqlite / mysql 下的 JSON 类型不同（1.5 与 "1.500"），转成 float 再比
        $this->assertSame(1.5, (float) $this->getJson($this->url . '/fetch')->json('data.rules.0.multiplier'));

        // 改成只对某个节点生效
        $this->postJson($this->url . '/rule/save', [
            'id' => $id,
            'scope' => 'node',
            'node_type' => 'vmess',
            'node_id' => 3,
            'start_minute' => 0,
            'end_minute' => 1440,
            'multiplier' => 2,
            'enabled' => 1
        ])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame('node', DB::table('v2_rate_rule')->where('id', $id)->value('scope'));
        $this->assertSame(1, DB::table('v2_rate_rule')->count());

        $this->postJson($this->url . '/rule/drop', ['id' => $id])->assertOk()->assertJsonPath('data', true);
        $this->assertSame(0, DB::table('v2_rate_rule')->count());
    }

    public function testNodeScopedRuleMustNameARealNode(): void
    {
        $payload = [
            'scope' => 'node',
            'start_minute' => 0,
            'end_minute' => 1440,
            'multiplier' => 2
        ];

        $this->postJson($this->url . '/rule/save', $payload)
            ->assertStatus(422)->assertJsonPath('message', '选择节点作用域时必须指定具体的节点');
        $this->postJson($this->url . '/rule/save', array_merge($payload, ['node_type' => 'vmess', 'node_id' => 0]))
            ->assertStatus(422);
        $this->postJson($this->url . '/rule/save', array_merge($payload, ['node_type' => 'nope', 'node_id' => 3]))
            ->assertStatus(422);
        $this->assertSame(0, DB::table('v2_rate_rule')->count());
    }

    public function testSettingsSaveAndTheContradictoryCombinationIsRejected(): void
    {
        $this->postJson($this->url . '/settings/save', [
            'enabled' => 1,
            'instant_mbps' => 80,
            'sustained_mbps' => 20,
            'burst_exempt_minutes' => 2,
            'stack_minutes' => 6,
            'stack_multiplier' => 1.8,
            'decay_step' => 1
        ])->assertOk()->assertJsonPath('data.enabled', 1);

        $this->getJson($this->url . '/fetch')
            ->assertJsonPath('data.settings.instant_mbps', 80)
            ->assertJsonPath('data.settings.stack_multiplier', 1.8);

        // 持续阈值高于瞬时阈值会让突发豁免把叠加彻底架空，属于配错而不是配得奇怪
        $this->postJson($this->url . '/settings/save', [
            'enabled' => 1,
            'instant_mbps' => 10,
            'sustained_mbps' => 20,
            'burst_exempt_minutes' => 3,
            'stack_minutes' => 5,
            'stack_multiplier' => 1.5,
            'decay_step' => 1
        ])->assertStatus(422)->assertJsonPath('message', '持续阈值不能高于瞬时阈值，否则突发豁免会让叠加永远不生效');
    }

    public function testExplainBreaksDownTheThreeFactors(): void
    {
        $this->postJson($this->url . '/rule/save', [
            'scope' => 'global',
            'start_minute' => 0,
            'end_minute' => 1440,
            'multiplier' => 1.5,
            'enabled' => 1,
            'weekdays' => []
        ])->assertOk();

        DB::table('v2_rate_state')->insert([
            'user_id' => 7, 'multiplier' => 1.5, 'rate_bps' => 20000000, 'high' => 5, 'burst' => 0,
            'state' => 'stacked', 'sampled_at' => time(), 'computed_at' => time()
        ]);

        $response = $this->postJson($this->url . '/explain', ['user_id' => 7])->assertOk();

        $response->assertJsonPath('data.user_multiplier', 1.5);
        $response->assertJsonPath('data.global_multiplier', 1.5);
        // 节点倍率 2 × 时段 1.5 × 动态 1.5 = 4.5
        $response->assertJsonPath('data.nodes.0.effective', 4.5);

        $this->postJson($this->url . '/explain', [])->assertStatus(422)->assertJsonValidationErrors('user_id');
    }
}
