<?php

namespace Tests\Feature;

use App\Http\Middleware\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 管理页「外部订阅源」的接口。
 *
 * 抓取本身（HTTP → 解析 → 入库）在 ExternalSubscriptionTest 里用注入的 Guzzle 客户端覆盖，
 * 这里只测接口层的取数与校验 —— 控制器内部自己 new 服务，塞不进 MockHandler，
 * 真发请求会让测试依赖外网。
 */
class AdminExternalSourceControllerTest extends TestCase
{
    private $url;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->withoutMiddleware(Admin::class);
        $this->url = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/external';

        Schema::create('v2_external_source', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('url');
            $table->integer('group_id')->default(0);
            $table->boolean('enabled')->default(true);
            $table->string('remark')->nullable();
            $table->bigInteger('last_fetch_at')->default(0);
            $table->string('last_status')->default('never');
            $table->string('last_error')->nullable();
            $table->integer('node_count')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_external_node', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('source_id');
            $table->string('name');
            $table->string('protocol');
            $table->string('host');
            $table->integer('port')->default(0);
            $table->text('payload')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->default(0);
            $table->bigInteger('fetched_at')->default(0);
        });
        Schema::create('v2_server_group', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        DB::table('v2_server_group')->insert([['id' => 2, 'name' => '过渡节点'], ['id' => 3, 'name' => '高级套餐']]);
    }

    public function testFetchReturnsSourcesAndTheGroupList(): void
    {
        DB::table('v2_external_source')->insert([
            'id' => 1, 'name' => '机场 1', 'url' => 'https://sub.example/a', 'group_id' => 2,
            'enabled' => 1, 'last_status' => 'ok', 'node_count' => 5, 'created_at' => time(), 'updated_at' => time()
        ]);

        $this->getJson($this->url . '/fetch')
            ->assertOk()
            ->assertJsonPath('data.sources.0.name', '机场 1')
            ->assertJsonPath('data.sources.0.group_id', 2)
            ->assertJsonPath('data.groups.0.name', '过渡节点')
            ->assertJsonPath('data.nodes', []);
    }

    public function testFetchCanCarryOneSourcesNodePreview(): void
    {
        DB::table('v2_external_source')->insert(['id' => 1, 'name' => '机场 1', 'url' => 'https://sub.example/a', 'group_id' => 2, 'enabled' => 1, 'created_at' => time(), 'updated_at' => time()]);
        DB::table('v2_external_node')->insert([
            'source_id' => 1, 'name' => 'HK-01', 'protocol' => 'trojan', 'host' => 'hk.example', 'port' => 443,
            'payload' => json_encode(['raw_uri' => 'trojan://p@hk.example:443#HK-01']), 'enabled' => 1, 'sort' => 0, 'fetched_at' => time()
        ]);

        $this->getJson($this->url . '/fetch?source_id=1')
            ->assertOk()
            ->assertJsonPath('data.nodes.0.name', 'HK-01')
            ->assertJsonPath('data.nodes.0.protocol', 'trojan');
        $this->getJson($this->url . '/fetch?source_id=2')->assertOk()->assertJsonPath('data.nodes', []);
    }

    public function testSaveSourceValidatesUrlAndGroup(): void
    {
        $this->postJson($this->url . '/source/save', ['url' => 'file:///etc/passwd', 'group_id' => 2])
            ->assertStatus(422)->assertJsonPath('message', '订阅链接必须是 http 或 https 地址');
        $this->postJson($this->url . '/source/save', ['url' => 'https://sub.example/a', 'group_id' => 99])
            ->assertStatus(422)->assertJsonPath('message', '选择的权限组不存在');
        $this->postJson($this->url . '/source/save', ['url' => 'https://sub.example/a'])
            ->assertStatus(422)->assertJsonValidationErrors('group_id');
        $this->assertSame(0, DB::table('v2_external_source')->count());
    }

    public function testSaveAndUpdateASource(): void
    {
        $id = $this->postJson($this->url . '/source/save', [
            'name' => '机场 1', 'url' => 'https://sub.example/a', 'group_id' => 2, 'enabled' => 1, 'remark' => '备用'
        ])->assertOk()->json('data.id');

        $this->assertSame('机场 1', DB::table('v2_external_source')->where('id', $id)->value('name'));
        $this->assertSame(1, (int) DB::table('v2_external_source')->where('id', $id)->value('enabled'));

        $this->postJson($this->url . '/source/save', [
            'id' => $id, 'name' => '机场 1（停用）', 'url' => 'https://sub.example/a', 'group_id' => 3, 'enabled' => 0
        ])->assertOk()->assertJsonPath('data.id', $id);

        $row = DB::table('v2_external_source')->where('id', $id)->first();
        $this->assertSame('机场 1（停用）', $row->name);
        $this->assertSame(3, (int) $row->group_id);
        $this->assertSame(0, (int) $row->enabled);
        $this->assertSame(1, DB::table('v2_external_source')->count());
    }

    public function testDropSourceRemovesItsNodesToo(): void
    {
        DB::table('v2_external_source')->insert(['id' => 1, 'name' => '机场 1', 'url' => 'https://sub.example/a', 'group_id' => 2, 'enabled' => 1, 'created_at' => time(), 'updated_at' => time()]);
        DB::table('v2_external_node')->insert([
            'source_id' => 1, 'name' => 'HK-01', 'protocol' => 'trojan', 'host' => 'hk.example', 'port' => 443,
            'payload' => '{}', 'enabled' => 1, 'sort' => 0, 'fetched_at' => time()
        ]);

        $this->postJson($this->url . '/source/drop', ['id' => 1])->assertOk()->assertJsonPath('data', true);
        $this->assertSame(0, DB::table('v2_external_source')->count());
        $this->assertSame(0, DB::table('v2_external_node')->count());

        $this->postJson($this->url . '/source/drop', [])->assertStatus(422)->assertJsonValidationErrors('id');
    }

    public function testRefreshSourceRequiresAnId(): void
    {
        $this->postJson($this->url . '/source/refresh', [])->assertStatus(422)->assertJsonValidationErrors('id');
        // 不存在的源：refresh() 直接返回失败，不认识也不去发请求
        $this->postJson($this->url . '/source/refresh', ['id' => 42])
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.error', '源不存在');
    }
}
