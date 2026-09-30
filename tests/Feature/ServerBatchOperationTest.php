<?php

namespace Tests\Feature;

use App\Http\Middleware\Admin;
use App\Models\ServerV2node;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServerBatchOperationTest extends TestCase
{
    private const TYPES = ['shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria', 'anytls', 'v2node'];

    private const REALITY_PRIVATE_KEY = 'PRIVATEKEYPRIVATEKEYPRIVATEKEYPRIVATEKEYPRIVATEKEY==';
    private const REALITY_PUBLIC_KEY = 'PUBLICKEYPUBLICKEYPUBLICKEYPUBLICKEYPUBLICKEY==';
    private const REALITY_SHORT_ID = 'deadbeef';

    private $url;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->withoutMiddleware(Admin::class);
        $this->url = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/server/manage';

        foreach (self::TYPES as $type) {
            Schema::create('v2_server_' . $type, function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->string('host');
                $table->string('rate')->default('1');
                $table->boolean('show')->default(true);
                $table->integer('sort')->default(0);
                // SNI 在不同类型里的落点不同：独立列、tls_settings、tlsSettings
                $table->string('server_name')->nullable();
                $table->string('protocol')->nullable();
                $table->integer('tls')->nullable();
                $table->text('tls_settings')->nullable();
                $table->text('tlsSettings')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }
    }

    private function seed(string $type, int $id, string $name, array $attributes = []): void
    {
        DB::table('v2_server_' . $type)->insert(array_merge([
            'id' => $id,
            'name' => $name,
            'host' => $type . '.example',
            'rate' => '1',
            'show' => 1,
            'sort' => 0,
            'server_name' => $type . '-sni.example',
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }

    private function seedRealityV2node(): void
    {
        $this->seed('v2node', 1, 'reality-node', [
            'host' => 'v2node.example',
            'protocol' => 'vless',
            'tls' => 2,
            'tls_settings' => json_encode([
                'server_name' => 'reality-sni.example',
                'dest' => 'reality-sni.example',
                'private_key' => self::REALITY_PRIVATE_KEY,
                'public_key' => self::REALITY_PUBLIC_KEY,
                'short_id' => self::REALITY_SHORT_ID,
            ]),
        ]);
    }

    private function tlsSettings(string $type): array
    {
        return json_decode((string) DB::table('v2_server_' . $type)->where('id', 1)->value('tls_settings'), true) ?? [];
    }

    public function testBatchCopyCreatesHiddenCopiesForEverySelectedType(): void
    {
        foreach (self::TYPES as $type) {
            $this->seed($type, 1, $type . '-node');
        }
        $nodes = array_map(fn ($type) => ['type' => $type, 'id' => 1], self::TYPES);

        $response = $this->postJson($this->url . '/nodes/copy', ['nodes' => $nodes, 'confirm' => true])
            ->assertOk()->assertJsonPath('data.requested_count', 8)->assertJsonPath('data.created_count', 8);
        $this->assertCount(8, $response->json('data.nodes'));

        foreach (self::TYPES as $type) {
            $this->assertSame(2, DB::table('v2_server_' . $type)->count());
            $this->assertSame(1, (int) DB::table('v2_server_' . $type)->where('id', 1)->value('show'));
            // 副本必须先隐藏，否则复制出来就直接进入下发链路
            $this->assertSame(0, (int) DB::table('v2_server_' . $type)->where('id', '!=', 1)->value('show'));
            $this->assertSame($type . '-node', (string) DB::table('v2_server_' . $type)->where('id', '!=', 1)->value('name'));
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testBatchCopyRegeneratesRealityKeysByDefaultAndLeavesTheSourceAlone(): void
    {
        $this->seedRealityV2node();

        $this->postJson($this->url . '/nodes/copy', [
            'nodes' => [['type' => 'v2node', 'id' => 1]],
            'regenerate_reality_keys' => true,
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.created_count', 1);

        $settings = $this->tlsSettings('v2node');
        $this->assertNotSame(self::REALITY_PRIVATE_KEY, $settings['private_key']);
        $this->assertNotSame(self::REALITY_PUBLIC_KEY, $settings['public_key']);
        // short_id 的推导规则必须与 V2nodeController::save() 一致，副本不能沿用原值
        $this->assertNotSame(self::REALITY_SHORT_ID, $settings['short_id']);
        $this->assertSame(substr(sha1($settings['private_key']), 0, 8), $settings['short_id']);
        // SNI / REALITY 目标地址属于节点身份之外的信息，复制时保留
        $this->assertSame('reality-sni.example', $settings['server_name']);
        $this->assertSame('reality-sni.example', $settings['dest']);

        $original = json_decode((string) DB::table('v2_server_v2node')->where('id', 1)->value('tls_settings'), true);
        $this->assertSame(self::REALITY_PRIVATE_KEY, $original['private_key']);
        $this->assertSame(self::REALITY_PUBLIC_KEY, $original['public_key']);
        $this->assertSame(self::REALITY_SHORT_ID, $original['short_id']);
    }

    public function testBatchCopyKeepsRealityKeysWhenRegenerationIsNotRequested(): void
    {
        $this->seedRealityV2node();

        $this->postJson($this->url . '/nodes/copy', [
            'nodes' => [['type' => 'v2node', 'id' => 1]],
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.created_count', 1);

        $settings = $this->tlsSettings('v2node');
        $this->assertSame(self::REALITY_PRIVATE_KEY, $settings['private_key']);
        $this->assertSame(self::REALITY_PUBLIC_KEY, $settings['public_key']);
        $this->assertSame(self::REALITY_SHORT_ID, $settings['short_id']);
    }

    public function testBatchCopyLeavesNonRealityNodesUntouchedByTheKeyOption(): void
    {
        $this->seed('v2node', 1, 'plain-node', [
            'protocol' => 'vless',
            'tls' => 1,
            'tls_settings' => json_encode(['server_name' => 'plain-sni.example']),
        ]);

        $this->postJson($this->url . '/nodes/copy', [
            'nodes' => [['type' => 'v2node', 'id' => 1]],
            'regenerate_reality_keys' => true,
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.created_count', 1);

        // 非 REALITY 节点不该凭空长出密钥
        $this->assertArrayNotHasKey('private_key', $this->tlsSettings('v2node'));
    }

    public function testRegenerationIsLimitedToV2nodeVlessRealityNodes(): void
    {
        $keys = json_encode([
            'private_key' => self::REALITY_PRIVATE_KEY,
            'public_key' => self::REALITY_PUBLIC_KEY,
            'short_id' => self::REALITY_SHORT_ID,
        ]);
        // 同样是 REALITY，但一个是 v2node 下的别的协议，一个是独立的 vless 节点类型
        $this->seed('v2node', 1, 'vmess-reality', ['protocol' => 'vmess', 'tls' => 2, 'tls_settings' => $keys]);
        $this->seed('vless', 1, 'standalone-vless', ['tls' => 2, 'tls_settings' => $keys]);

        $this->postJson($this->url . '/nodes/copy', [
            'nodes' => [['type' => 'v2node', 'id' => 1], ['type' => 'vless', 'id' => 1]],
            'regenerate_reality_keys' => true,
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.created_count', 2);

        foreach (['v2node', 'vless'] as $type) {
            $settings = json_decode((string) DB::table('v2_server_' . $type)->where('id', '!=', 1)->value('tls_settings'), true);
            $this->assertSame(self::REALITY_PRIVATE_KEY, $settings['private_key'], $type . ' 不在 v2node+vless 范围内，密钥应原样保留');
            $this->assertSame(self::REALITY_PUBLIC_KEY, $settings['public_key']);
            $this->assertSame(self::REALITY_SHORT_ID, $settings['short_id']);
        }
    }

    public function testBatchCopyRequiresConfirmationAndRejectsEmptySelection(): void
    {
        $this->seed('vmess', 1, 'vmess-node');
        $this->postJson($this->url . '/nodes/copy', ['nodes' => [['type' => 'vmess', 'id' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertSame(1, DB::table('v2_server_vmess')->count());

        $this->postJson($this->url . '/nodes/copy', ['nodes' => [], 'confirm' => true])
            ->assertStatus(422)->assertJsonValidationErrors('nodes');
        $this->postJson($this->url . '/nodes/copy', [
            'nodes' => [['type' => 'not-a-type', 'id' => 1]], 'confirm' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('nodes.0.type');
        $this->assertSame(1, DB::table('v2_server_vmess')->count());
    }

    public function testDuplicatedSelectionProducesASingleCopy(): void
    {
        $this->seed('vmess', 1, 'vmess-node');
        $this->postJson($this->url . '/nodes/copy', [
            'nodes' => [['type' => 'vmess', 'id' => 1], ['type' => 'vmess', 'id' => 1]],
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.created_count', 1);
        $this->assertSame(2, DB::table('v2_server_vmess')->count());
    }

    public function testRejectedCopyRollsBackPreviouslyCreatedNodes(): void
    {
        $this->seed('shadowsocks', 1, 'ss-node');
        $this->seed('vmess', 1, 'vmess-node');

        $dispatcher = \App\Models\ServerVmess::getEventDispatcher();
        \App\Models\ServerVmess::setEventDispatcher(clone $dispatcher);
        \App\Models\ServerVmess::saving(function () { return false; });
        try {
            $this->postJson($this->url . '/nodes/copy', [
                'nodes' => [['type' => 'shadowsocks', 'id' => 1], ['type' => 'vmess', 'id' => 1]],
                'confirm' => true,
            ])->assertStatus(500)->assertJsonPath('message', '节点复制失败，本次操作已回滚');
            $this->assertSame(1, DB::table('v2_server_shadowsocks')->count());
            $this->assertSame(1, DB::table('v2_server_vmess')->count());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            \App\Models\ServerVmess::setEventDispatcher($dispatcher);
        }
    }

    public function testPreviewReportsPerTypeStorageWithoutWriting(): void
    {
        foreach (self::TYPES as $type) {
            $this->seed($type, 1, $type . '-node');
        }
        $nodes = array_map(fn ($type) => ['type' => $type, 'id' => 1], self::TYPES);

        $response = $this->postJson($this->url . '/tls-fields/preview', [
            'nodes' => $nodes,
            'server_name' => 'new-sni.example',
            'dest' => 'new-dest.example',
        ])->assertOk()->assertJsonPath('data.matched_count', 8);

        $byType = [];
        foreach ($response->json('data.nodes') as $node) {
            $byType[$node['type']] = $node;
        }
        // shadowsocks 没有 TLS 名称字段，要显式告知前端「不适用」而不是静默跳过
        $this->assertFalse($byType['shadowsocks']['server_name_applicable']);
        $this->assertFalse($byType['shadowsocks']['dest_applicable']);
        foreach (self::TYPES as $type) {
            if ($type === 'shadowsocks') {
                continue;
            }
            $this->assertTrue($byType[$type]['server_name_applicable']);
            $this->assertContains('server_name', $byType[$type]['changes']);
        }
        // Server Address 是 REALITY 目标地址，只有 v2node 有这个字段
        $this->assertTrue($byType['v2node']['dest_applicable']);
        $this->assertFalse($byType['vless']['dest_applicable']);
        $this->assertNotContains('dest', $byType['vless']['changes']);

        // 预演是只读的
        $this->assertSame('shadowsocks-sni.example', (string) DB::table('v2_server_shadowsocks')->where('id', 1)->value('server_name'));
        $this->assertSame('trojan-sni.example', (string) DB::table('v2_server_trojan')->where('id', 1)->value('server_name'));
        $this->assertSame('vless-sni.example', $this->tlsSettings('vless')['server_name']);
    }

    public function testApplyWritesSniIntoTheRightPlaceForEachType(): void
    {
        foreach (self::TYPES as $type) {
            $this->seed($type, 1, $type . '-node');
        }
        $nodes = array_map(fn ($type) => ['type' => $type, 'id' => 1], self::TYPES);

        $this->postJson($this->url . '/tls-fields/apply', [
            'nodes' => $nodes,
            'server_name' => 'new-sni.example',
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.updated_count', 7);

        $this->assertSame('new-sni.example', (string) DB::table('v2_server_trojan')->where('id', 1)->value('server_name'));
        $this->assertSame('new-sni.example', (string) DB::table('v2_server_tuic')->where('id', 1)->value('server_name'));
        $this->assertSame('new-sni.example', (string) DB::table('v2_server_hysteria')->where('id', 1)->value('server_name'));
        $this->assertSame('new-sni.example', (string) DB::table('v2_server_anytls')->where('id', 1)->value('server_name'));
        $this->assertSame('new-sni.example', $this->tlsSettings('vless')['server_name']);
        $this->assertSame('new-sni.example', $this->tlsSettings('v2node')['server_name']);
        $this->assertSame('new-sni.example', json_decode((string) DB::table('v2_server_vmess')->where('id', 1)->value('tlsSettings'), true)['server_name']);
        // shadowsocks 没有该字段，保持原样
        $this->assertSame('shadowsocks-sni.example', (string) DB::table('v2_server_shadowsocks')->where('id', 1)->value('server_name'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testApplyWritesServerAddressOnlyForV2nodeAndLeavesSniAloneWhenBlank(): void
    {
        foreach (self::TYPES as $type) {
            if ($type === 'v2node') {
                continue;
            }
            $this->seed($type, 1, $type . '-node');
        }
        $this->seedRealityV2node();
        $nodes = array_map(fn ($type) => ['type' => $type, 'id' => 1], self::TYPES);

        $this->postJson($this->url . '/tls-fields/apply', [
            'nodes' => $nodes,
            'server_name' => '',
            'dest' => 'target.example',
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.updated_count', 1);

        $this->assertSame('target.example', $this->tlsSettings('v2node')['dest']);
        // 只填了 Server Address，SNI 必须原样保留
        $this->assertSame('reality-sni.example', $this->tlsSettings('v2node')['server_name']);
        $this->assertSame('vless-sni.example', $this->tlsSettings('vless')['server_name']);
        $this->assertSame('trojan-sni.example', (string) DB::table('v2_server_trojan')->where('id', 1)->value('server_name'));
    }

    public function testApplyRejectsEmptyInputAndMissingConfirmation(): void
    {
        $this->seed('trojan', 1, 'trojan-node');
        $payload = ['nodes' => [['type' => 'trojan', 'id' => 1]], 'server_name' => '', 'dest' => ''];
        $this->postJson($this->url . '/tls-fields/apply', $payload + ['confirm' => true])
            ->assertStatus(422)->assertJsonPath('message', '请至少填写 Server Name(SNI) 或 Server Address 中的一项');
        $this->postJson($this->url . '/tls-fields/preview', $payload)->assertStatus(422);

        $this->postJson($this->url . '/tls-fields/apply', [
            'nodes' => [['type' => 'trojan', 'id' => 1]], 'server_name' => 'new.example',
        ])->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertSame('trojan-sni.example', (string) DB::table('v2_server_trojan')->where('id', 1)->value('server_name'));
    }

    public function testApplyRejectsWhitespaceAndUnknownNodes(): void
    {
        $this->seed('trojan', 1, 'trojan-node');
        $this->postJson($this->url . '/tls-fields/apply', [
            'nodes' => [['type' => 'trojan', 'id' => 1]], 'server_name' => 'bad sni', 'confirm' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('server_name');

        $this->postJson($this->url . '/tls-fields/apply', [
            'nodes' => [['type' => 'trojan', 'id' => 99]], 'server_name' => 'new.example', 'confirm' => true,
        ])->assertStatus(422)->assertJsonPath('message', '选中的节点已不存在：trojan #99');
        $this->assertSame('trojan-sni.example', (string) DB::table('v2_server_trojan')->where('id', 1)->value('server_name'));
    }

    public function testApplyRollsBackEverythingWhenOneNodeFailsToPersist(): void
    {
        $this->seed('trojan', 1, 'trojan-node');
        $this->seed('tuic', 1, 'tuic-node');

        $dispatcher = \App\Models\ServerTuic::getEventDispatcher();
        \App\Models\ServerTuic::setEventDispatcher(clone $dispatcher);
        \App\Models\ServerTuic::saving(function () { return false; });
        try {
            $this->postJson($this->url . '/tls-fields/apply', [
                'nodes' => [['type' => 'trojan', 'id' => 1], ['type' => 'tuic', 'id' => 1]],
                'server_name' => 'new.example',
                'confirm' => true,
            ])->assertStatus(500)->assertJsonPath('message', '节点保存失败，本次操作已回滚');
            $this->assertSame('trojan-sni.example', (string) DB::table('v2_server_trojan')->where('id', 1)->value('server_name'));
            $this->assertSame('tuic-sni.example', (string) DB::table('v2_server_tuic')->where('id', 1)->value('server_name'));
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            \App\Models\ServerTuic::setEventDispatcher($dispatcher);
        }
    }

    public function testUnpersistedSniIsDetectedAndEntireApplyRollsBack(): void
    {
        $this->seed('trojan', 1, 'trojan-node');
        $this->seed('tuic', 1, 'tuic-node');
        DB::statement('CREATE TRIGGER restore_tuic_sni AFTER UPDATE OF server_name ON v2_server_tuic
            BEGIN UPDATE v2_server_tuic SET server_name = OLD.server_name WHERE id = NEW.id; END');

        $this->postJson($this->url . '/tls-fields/apply', [
            'nodes' => [['type' => 'trojan', 'id' => 1], ['type' => 'tuic', 'id' => 1]],
            'server_name' => 'new.example',
            'confirm' => true,
        ])->assertStatus(500)->assertJsonPath('message', '节点保存后复核不一致，本次操作已回滚');
        $this->assertSame('trojan-sni.example', (string) DB::table('v2_server_trojan')->where('id', 1)->value('server_name'));
        $this->assertSame('tuic-sni.example', (string) DB::table('v2_server_tuic')->where('id', 1)->value('server_name'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testSelectionIsCappedToKeepBulkWritesBounded(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 201; $i++) {
            $nodes[] = ['type' => 'vmess', 'id' => $i];
        }
        $this->postJson($this->url . '/nodes/copy', ['nodes' => $nodes, 'confirm' => true])
            ->assertStatus(422)->assertJsonValidationErrors('nodes');
    }

    public function testRealityKeyStorageIsUsableByTheSubscriptionBuilder(): void
    {
        $this->seedRealityV2node();
        $this->postJson($this->url . '/nodes/copy', [
            'nodes' => [['type' => 'v2node', 'id' => 1]],
            'regenerate_reality_keys' => true,
            'confirm' => true,
        ])->assertOk();

        $copy = ServerV2node::where('id', '!=', 1)->first();
        $this->assertNotNull($copy);
        // 副本必须能被订阅构建链路直接读出来，键名与 save() 写入的一致
        $this->assertSame($copy->tls_settings['private_key'], $this->tlsSettings('v2node')['private_key']);
        $this->assertArrayHasKey('public_key', $copy->tls_settings);
        $this->assertArrayHasKey('short_id', $copy->tls_settings);
    }
}
