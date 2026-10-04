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
                $table->integer('parent_id')->nullable();
                $table->string('network')->nullable();
                $table->text('network_settings')->nullable();
                $table->text('networkSettings')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }
    }

    private function seedNode(string $type, int $id, string $name, array $attributes = []): void
    {
        DB::table('v2_server_' . $type)->insert(array_merge([
            'id' => $id,
            'name' => $name,
            'host' => $type . '.example',
            'rate' => '1',
            'show' => 1,
            'sort' => 0,
            'server_name' => $type . '-sni.example',
            'tls_settings' => json_encode(['server_name' => $type . '-sni.example']),
            'tlsSettings' => json_encode(['server_name' => $type . '-sni.example']),
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }

    private function seedRealityV2node(): void
    {
        $this->seedNode('v2node', 1, 'reality-node', [
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

    private function tlsSettings(string $type, int $id = 1): array
    {
        return json_decode((string) DB::table('v2_server_' . $type)->where('id', $id)->value('tls_settings'), true) ?? [];
    }

    public function testBatchCopyCreatesHiddenCopiesForEverySelectedType(): void
    {
        foreach (self::TYPES as $type) {
            $this->seedNode($type, 1, $type . '-node');
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

        $response = $this->postJson($this->url . '/nodes/copy', [
            'nodes' => [['type' => 'v2node', 'id' => 1]],
            'regenerate_reality_keys' => true,
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.created_count', 1);

        $settings = $this->tlsSettings('v2node', $response->json('data.nodes.0.id'));
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
        $this->seedNode('v2node', 1, 'plain-node', [
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
        $this->seedNode('v2node', 1, 'vmess-reality', ['protocol' => 'vmess', 'tls' => 2, 'tls_settings' => $keys]);
        $this->seedNode('vless', 1, 'standalone-vless', ['tls' => 2, 'tls_settings' => $keys]);

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

    public function testBatchRenamePreviewKeepsTheSeparatorAndDoesNotWrite(): void
    {
        $this->seedNode('vmess', 1, 'old-a');
        $this->seedNode('vmess', 2, 'old-b');
        $selection = [
            ['type' => 'vmess', 'id' => 1, 'name' => 'old-a'],
            ['type' => 'vmess', 'id' => 2, 'name' => 'old-b'],
        ];
        $before = DB::table('v2_server_vmess')->get()->toArray();

        $response = $this->postJson($this->url . '/rename/preview', [
            'nodes' => $selection,
            'prefix' => 'Hong Kong',
            'suffix' => 'v1',
            'separator' => ' | ',
            'start_number' => 1,
            'number_width' => 1,
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.matched_count', 2)
            ->assertJsonPath('data.changed_count', 2)
            ->assertJsonPath('data.nodes.0.new_name', 'Hong Kong | 1 | v1')
            ->assertJsonPath('data.nodes.1.new_name', 'Hong Kong | 2 | v1');

        $this->assertSame(' | ', $response->json('data.separator'));
        $this->assertEquals($before, DB::table('v2_server_vmess')->get()->toArray());
    }

    public function testBatchRenameAppliesInSelectionOrderAndSupportsZeroPadding(): void
    {
        $this->seedNode('v2node', 1, 'old-v2');
        $this->seedNode('vmess', 1, 'old-vmess');
        $selection = [
            ['type' => 'v2node', 'id' => 1, 'name' => 'old-v2'],
            ['type' => 'vmess', 'id' => 1, 'name' => 'old-vmess'],
        ];

        $this->postJson($this->url . '/rename/apply', [
            'nodes' => $selection,
            'prefix' => 'Hong Kong',
            'suffix' => 'v1',
            'separator' => ' | ',
            'start_number' => 7,
            'number_width' => 3,
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.updated_count', 2)
            ->assertJsonPath('data.nodes.0.name', 'Hong Kong | 007 | v1')
            ->assertJsonPath('data.nodes.1.name', 'Hong Kong | 008 | v1');

        $this->assertSame('Hong Kong | 007 | v1', (string) DB::table('v2_server_v2node')->where('id', 1)->value('name'));
        $this->assertSame('Hong Kong | 008 | v1', (string) DB::table('v2_server_vmess')->where('id', 1)->value('name'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testBatchRenameRejectsAChangedNameAfterPreview(): void
    {
        $this->seedNode('trojan', 1, 'before');
        $selection = [['type' => 'trojan', 'id' => 1, 'name' => 'before']];
        $format = ['prefix' => 'Hong Kong', 'suffix' => 'v1', 'separator' => ' | ', 'start_number' => 1, 'number_width' => 1];
        $this->postJson($this->url . '/rename/preview', array_merge(['nodes' => $selection], $format))->assertOk();
        DB::table('v2_server_trojan')->where('id', 1)->update(['name' => 'changed-elsewhere']);

        $this->postJson($this->url . '/rename/apply', array_merge(['nodes' => $selection, 'confirm' => true], $format))
            ->assertStatus(409);
        $this->assertSame('changed-elsewhere', (string) DB::table('v2_server_trojan')->where('id', 1)->value('name'));
    }

    public function testBatchRenameRollsBackWhenOneNodeCannotBeSaved(): void
    {
        $this->seedNode('shadowsocks', 1, 'ss-old');
        $this->seedNode('vmess', 1, 'vmess-old');
        $dispatcher = \App\Models\ServerVmess::getEventDispatcher();
        \App\Models\ServerVmess::setEventDispatcher(clone $dispatcher);
        \App\Models\ServerVmess::saving(function () { return false; });
        try {
            $this->postJson($this->url . '/rename/apply', [
                'nodes' => [
                    ['type' => 'shadowsocks', 'id' => 1, 'name' => 'ss-old'],
                    ['type' => 'vmess', 'id' => 1, 'name' => 'vmess-old'],
                ],
                'prefix' => 'Hong Kong', 'suffix' => 'v1', 'separator' => ' | ',
                'start_number' => 1, 'number_width' => 1, 'confirm' => true,
            ])->assertStatus(500)->assertJsonPath('message', '节点名称保存失败，本次操作已回滚');
            $this->assertSame('ss-old', (string) DB::table('v2_server_shadowsocks')->where('id', 1)->value('name'));
            $this->assertSame('vmess-old', (string) DB::table('v2_server_vmess')->where('id', 1)->value('name'));
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            \App\Models\ServerVmess::setEventDispatcher($dispatcher);
        }
    }

    public function testBatchCopyRequiresConfirmationAndRejectsEmptySelection(): void
    {
        $this->seedNode('vmess', 1, 'vmess-node');
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
        $this->seedNode('vmess', 1, 'vmess-node');
        $this->postJson($this->url . '/nodes/copy', [
            'nodes' => [['type' => 'vmess', 'id' => 1], ['type' => 'vmess', 'id' => 1]],
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.created_count', 1);
        $this->assertSame(2, DB::table('v2_server_vmess')->count());
    }

    public function testRejectedCopyRollsBackPreviouslyCreatedNodes(): void
    {
        $this->seedNode('shadowsocks', 1, 'ss-node');
        $this->seedNode('vmess', 1, 'vmess-node');

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

    public function testRatePreviewReadsEveryTypeWithoutWritingAndSkipsEquivalentValues(): void
    {
        $selection = [];
        $before = [];
        foreach (self::TYPES as $type) {
            $this->seedNode($type, 1, $type . '-node', ['rate' => $type === 'vmess' ? '1.500' : '1', 'show' => 0]);
            $selection[] = ['type' => $type, 'id' => 1];
            $before[$type] = DB::table('v2_server_' . $type)->get()->toArray();
        }
        $response = $this->postJson($this->url . '/rate/preview', ['nodes' => $selection, 'rate' => '1.5'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.rate', '1.50')->assertJsonPath('data.matched_count', 8)->assertJsonPath('data.changed_count', 7);
        foreach ($response->json('data.nodes') as $node) {
            $this->assertSame($node['type'] !== 'vmess', $node['changed']);
            $this->assertSame('1.50', $node['new_rate']);
            $this->assertEquals($before[$node['type']], DB::table('v2_server_' . $node['type'])->get()->toArray());
        }
    }

    public function testRateApplyChangesOnlySelectedNodesAndPreservesOtherFieldsAndChildren(): void
    {
        $selection = [];
        $before = [];
        $children = [];
        foreach (self::TYPES as $type) {
            $this->seedNode($type, 1, $type . '-node', ['rate' => $type === 'vmess' ? '1.50' : '2']);
            $this->seedNode($type, 2, $type . '-child', ['rate' => '3', 'parent_id' => 1]);
            $selection[] = ['type' => $type, 'id' => 1];
            $before[$type] = (array) DB::table('v2_server_' . $type)->where('id', 1)->first();
            $children[$type] = (array) DB::table('v2_server_' . $type)->where('id', 2)->first();
        }
        $response = $this->postJson($this->url . '/rate/apply', ['nodes' => $selection, 'rate' => '1.5', 'confirm' => true])
            ->assertOk()->assertJsonPath('data.rate', '1.50')->assertJsonPath('data.matched_count', 8)
            ->assertJsonPath('data.updated_count', 7);
        $this->assertCount(7, $response->json('data.nodes'));
        foreach (self::TYPES as $type) {
            $after = (array) DB::table('v2_server_' . $type)->where('id', 1)->first();
            $before[$type]['rate'] = '1.50';
            unset($before[$type]['updated_at'], $after['updated_at']);
            $this->assertSame($before[$type], $after);
            $this->assertSame($children[$type], (array) DB::table('v2_server_' . $type)->where('id', 2)->first());
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testRateApplyDeduplicatesNodesAndDoesNotWriteAlreadyMatchingRates(): void
    {
        $this->seedNode('trojan', 1, 'unchanged', ['rate' => '1.500']);
        DB::statement("CREATE TRIGGER forbid_rate_write BEFORE UPDATE ON v2_server_trojan BEGIN SELECT RAISE(ABORT, 'must skip unchanged'); END");
        $params = ['nodes' => [['type' => 'trojan', 'id' => 1], ['type' => 'trojan', 'id' => 1]], 'rate' => '1.5', 'confirm' => true];
        $this->postJson($this->url . '/rate/apply', $params)->assertOk()
            ->assertJsonPath('data.requested_count', 2)->assertJsonPath('data.matched_count', 1)->assertJsonPath('data.updated_count', 0);
        $this->assertDatabaseHas('v2_server_trojan', ['id' => 1, 'rate' => '1.500']);
    }

    public function testRateAcceptsPositiveDecimalBoundariesAndNormalizesForStorage(): void
    {
        $this->seedNode('v2node', 1, 'node');
        foreach (['0.01', '1', 1.25, '00000001.50', '99999999.99'] as $rate) {
            $this->postJson($this->url . '/rate/apply', [
                'nodes' => [['type' => 'v2node', 'id' => 1]], 'rate' => $rate, 'confirm' => true,
            ])->assertOk()->assertJsonPath('data.rate', number_format((float) $rate, 2, '.', ''));
            $this->assertDatabaseHas('v2_server_v2node', ['id' => 1, 'rate' => number_format((float) $rate, 2, '.', '')]);
        }
    }

    /** @dataProvider invalidBatchRates */
    public function testInvalidRatesAreRejectedByPreviewAndApply($rate): void
    {
        $this->seedNode('vmess', 1, 'node');
        foreach (['preview', 'apply'] as $action) {
            $this->postJson($this->url . '/rate/' . $action, [
                'nodes' => [['type' => 'vmess', 'id' => 1]], 'rate' => $rate, 'confirm' => true,
            ])->assertStatus(422)->assertJsonValidationErrors('rate');
        }
        $this->assertDatabaseHas('v2_server_vmess', ['id' => 1, 'rate' => '1']);
    }

    public static function invalidBatchRates(): array
    {
        return [
            'empty' => [''], 'null' => [null], 'zero' => [0], 'decimal zero' => ['0.00'],
            'negative' => [-1], 'too small' => ['0.001'], 'too precise' => ['1.234'],
            'too large' => ['100000000'], 'scientific' => ['1e2'], 'nan' => ['NaN'],
            'infinity' => ['Infinity'], 'boolean' => [true], 'array' => [['1']],
            'signed' => ['+1'], 'trailing dot' => ['1.'],
        ];
    }

    public function testRateApplyRequiresConfirmationAndValidSelectionAndAdminAccess(): void
    {
        $this->seedNode('trojan', 1, 'node');
        $params = ['nodes' => [['type' => 'trojan', 'id' => 1]], 'rate' => '2'];
        $this->postJson($this->url . '/rate/apply', $params)->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->postJson($this->url . '/rate/preview', ['nodes' => $params['nodes']])->assertStatus(422)->assertJsonValidationErrors('rate');
        foreach ([[], [['type' => 'unknown', 'id' => 1]], [['type' => 'trojan', 'id' => 0]],
            [['type' => 'trojan', 'id' => 404]], array_fill(0, 201, ['type' => 'trojan', 'id' => 1])] as $selection) {
            foreach (['preview', 'apply'] as $action) {
                $this->postJson($this->url . '/rate/' . $action, ['nodes' => $selection, 'rate' => '2', 'confirm' => true])->assertStatus(422);
            }
        }
        $this->withMiddleware(Admin::class);
        foreach (['preview', 'apply'] as $action) {
            $this->postJson($this->url . '/rate/' . $action, $params + ['confirm' => true])->assertStatus(403);
        }
        $this->assertDatabaseHas('v2_server_trojan', ['id' => 1, 'rate' => '1']);
    }

    public function testRateSaveFailureRollsBackEarlierNodes(): void
    {
        $this->seedNode('trojan', 1, 'first', ['rate' => '1']);
        $this->seedNode('tuic', 1, 'second', ['rate' => '3']);
        DB::statement("CREATE TRIGGER fail_rate BEFORE UPDATE OF rate ON v2_server_tuic BEGIN SELECT RAISE(ABORT, 'rate test failure'); END");
        $this->postJson($this->url . '/rate/apply', [
            'nodes' => [['type' => 'trojan', 'id' => 1], ['type' => 'tuic', 'id' => 1]], 'rate' => '2', 'confirm' => true,
        ])->assertStatus(500);
        $this->assertDatabaseHas('v2_server_trojan', ['id' => 1, 'rate' => '1']);
        $this->assertDatabaseHas('v2_server_tuic', ['id' => 1, 'rate' => '3']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testRatePersistenceMismatchRollsBackTheWholeBatch(): void
    {
        $this->seedNode('trojan', 1, 'first', ['rate' => '1']);
        $this->seedNode('tuic', 1, 'second', ['rate' => '3']);
        DB::statement('CREATE TRIGGER restore_rate AFTER UPDATE OF rate ON v2_server_tuic
            BEGIN UPDATE v2_server_tuic SET rate = OLD.rate WHERE id = NEW.id; END');
        $this->postJson($this->url . '/rate/apply', [
            'nodes' => [['type' => 'trojan', 'id' => 1], ['type' => 'tuic', 'id' => 1]], 'rate' => '2', 'confirm' => true,
        ])->assertStatus(500);
        $this->assertDatabaseHas('v2_server_trojan', ['id' => 1, 'rate' => '1']);
        $this->assertDatabaseHas('v2_server_tuic', ['id' => 1, 'rate' => '3']);
    }

    public function testInspectionReadsAllTypesWithoutWritingOrExposingTlsSecrets(): void
    {
        $before = [];
        foreach (self::TYPES as $type) {
            $this->seedNode($type, 1, $type . '-node', [
                'protocol' => 'vless',
                'tls' => $type === 'v2node' ? 2 : 1,
                'tlsSettings' => json_encode(['serverName' => 'vmess-sni.example']),
                'tls_settings' => json_encode([
                    'server_name' => $type . '-sni.example', 'dest' => 'target.example',
                    'private_key' => 'SECRET-PRIVATE-KEY', 'tls_key' => 'SECRET-TLS-KEY',
                    'dns_env' => 'SECRET-DNS-TOKEN',
                ]),
            ]);
            $before[$type] = DB::table('v2_server_' . $type)->get()->toArray();
        }
        $selection = array_map(fn ($type) => ['type' => $type, 'id' => 1], self::TYPES);
        $response = $this->postJson($this->url . '/tls-fields/inspect', [
            'nodes' => $selection,
            // 查询接口不能把额外提交的值当作写入请求。
            'server_name' => 'overwrite.example', 'dest' => 'overwrite.example', 'confirm' => true,
        ])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.matched_count', 8);

        foreach ($response->json('data.nodes') as $node) {
            $type = $node['type'];
            $this->assertSame($type === 'shadowsocks' ? null : $type . '-sni.example', $node['server_name']);
            $this->assertSame($type !== 'shadowsocks', $node['server_name_applicable']);
            $this->assertSame($type === 'v2node', $node['dest_applicable']);
            $this->assertSame($type === 'v2node' ? 'target.example' : null, $node['dest']);
            $this->assertSame($type === 'v2node' ? 'vless' : $type, $node['protocol']);
            $this->assertFalse($node['server_name_conflict']);
            $this->assertArrayNotHasKey('tls_settings', $node);
            $this->assertEquals($before[$type], DB::table('v2_server_' . $type)->get()->toArray());
        }
        $this->assertStringNotContainsString('SECRET-', $response->getContent());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testInspectionDistinguishesSavedValuesFromTlsAndRealityApplicability(): void
    {
        $this->seedNode('vmess', 1, 'tls-disabled', [
            'tls' => 0, 'tlsSettings' => json_encode(['serverName' => 'saved.example']),
        ]);
        $this->seedNode('vless', 1, 'standalone-reality', ['tls' => 2]);
        $this->seedNode('v2node', 1, 'ordinary-tls', [
            'tls' => 1, 'protocol' => 'vmess', 'tls_settings' => json_encode(['dest' => 'saved-target.example']),
        ]);
        $this->seedNode('v2node', 2, 'reality-empty', ['tls' => 2, 'protocol' => 'vless', 'tls_settings' => null]);
        $selection = [['type' => 'vmess', 'id' => 1], ['type' => 'vless', 'id' => 1],
            ['type' => 'v2node', 'id' => 1], ['type' => 'v2node', 'id' => 2]];
        $response = $this->postJson($this->url . '/tls-fields/inspect', ['nodes' => $selection])->assertOk();
        $response->assertJsonPath('data.nodes.0.tls_mode', 'none')
            ->assertJsonPath('data.nodes.0.server_name', 'saved.example')
            ->assertJsonPath('data.nodes.1.tls_mode', 'reality')
            ->assertJsonPath('data.nodes.1.dest_applicable', false)
            ->assertJsonPath('data.nodes.2.tls_mode', 'tls')
            ->assertJsonPath('data.nodes.2.dest_applicable', false)
            ->assertJsonPath('data.nodes.3.dest_applicable', true)
            ->assertJsonPath('data.nodes.3.dest', '')
            ->assertJsonPath('data.nodes.3.server_name', '');
    }

    /** @dataProvider vmessInspectionSettings */
    public function testInspectionSupportsBothVmessSniKeysAndReportsConflicts(array $settings, string $sni, bool $conflict): void
    {
        $this->seedNode('vmess', 1, 'vmess-node', ['tls' => 1, 'tlsSettings' => json_encode($settings)]);
        $response = $this->postJson($this->url . '/tls-fields/inspect', [
            'nodes' => [['type' => 'vmess', 'id' => 1]],
        ])->assertOk()->assertJsonPath('data.nodes.0.server_name', $sni)
            ->assertJsonPath('data.nodes.0.server_name_conflict', $conflict);
        $this->assertSame($conflict ? $settings : [], $response->json('data.nodes.0.server_name_values'));
    }

    public static function vmessInspectionSettings(): array
    {
        return [
            'legacy' => [['serverName' => 'legacy.example'], 'legacy.example', false],
            'batch' => [['server_name' => 'batch.example'], 'batch.example', false],
            'matching' => [['serverName' => 'same.example', 'server_name' => 'same.example'], 'same.example', false],
            'conflicting' => [['serverName' => 'legacy.example', 'server_name' => 'batch.example'], 'legacy.example', true],
            'blank legacy' => [['serverName' => '', 'server_name' => 'batch.example'], 'batch.example', false],
        ];
    }

    public function testInspectionRereadsCurrentValuesAndDeduplicatesWithinEachType(): void
    {
        $this->seedNode('trojan', 1, 'trojan-node');
        $params = ['nodes' => [['type' => 'trojan', 'id' => 1], ['type' => 'trojan', 'id' => 1]]];
        $this->postJson($this->url . '/tls-fields/inspect', $params)->assertOk()
            ->assertJsonPath('data.matched_count', 1)->assertJsonPath('data.nodes.0.server_name', 'trojan-sni.example');
        DB::table('v2_server_trojan')->where('id', 1)->update(['server_name' => 'updated.example']);
        $this->postJson($this->url . '/tls-fields/inspect', $params)->assertOk()
            ->assertJsonPath('data.nodes.0.server_name', 'updated.example');
    }

    public function testInspectionValidatesSelectionAndRequiresAdminAuthentication(): void
    {
        foreach ([[], [['type' => 'unknown', 'id' => 1]], [['type' => 'vmess', 'id' => 0]],
            [['type' => 'vmess', 'id' => 999]], array_fill(0, 201, ['type' => 'vmess', 'id' => 1])] as $selection) {
            $this->postJson($this->url . '/tls-fields/inspect', ['nodes' => $selection])->assertStatus(422);
        }
        $this->withMiddleware(Admin::class);
        $this->postJson($this->url . '/tls-fields/inspect', ['nodes' => [['type' => 'vmess', 'id' => 1]]])
            ->assertStatus(403);
    }

    public function testPreviewReportsPerTypeStorageWithoutWriting(): void
    {
        foreach (self::TYPES as $type) {
            $this->seedNode($type, 1, $type . '-node');
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
            $this->seedNode($type, 1, $type . '-node');
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
            $this->seedNode($type, 1, $type . '-node');
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
        $this->seedNode('trojan', 1, 'trojan-node');
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
        $this->seedNode('trojan', 1, 'trojan-node');
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
        $this->seedNode('trojan', 1, 'trojan-node');
        $this->seedNode('tuic', 1, 'tuic-node');

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
        $this->seedNode('trojan', 1, 'trojan-node');
        $this->seedNode('tuic', 1, 'tuic-node');
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
        $this->assertSame($copy->tls_settings['private_key'], $this->tlsSettings('v2node', (int) $copy->id)['private_key']);
        $this->assertArrayHasKey('public_key', $copy->tls_settings);
        $this->assertArrayHasKey('short_id', $copy->tls_settings);
    }

    // ---- 批量删除 ----

    public function testBatchDeleteRemovesOnlyTheSelectionAndReportsChildren(): void
    {
        $this->seedNode('v2node', 1, 'parent-node');
        $this->seedNode('v2node', 2, 'child-node', ['parent_id' => 1]);
        $this->seedNode('vmess', 1, 'vmess-node');

        $this->postJson($this->url . '/nodes/delete', [
            'nodes' => [['type' => 'v2node', 'id' => 1]],
            'confirm' => true,
        ])->assertOk()
            ->assertJsonPath('data.deleted_count', 1)
            ->assertJsonPath('data.nodes.0.name', 'parent-node')
            ->assertJsonPath('data.nodes.0.published', true)
            ->assertJsonPath('data.nodes.0.child_count', 1);

        $this->assertNull(DB::table('v2_server_v2node')->where('id', 1)->first());
        // 子节点不跟着删，只是失去父节点；没勾选的类型更不该被碰
        $this->assertNotNull(DB::table('v2_server_v2node')->where('id', 2)->first());
        $this->assertNotNull(DB::table('v2_server_vmess')->where('id', 1)->first());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testBatchDeleteReportsHiddenNodesAsNotPublished(): void
    {
        $this->seedNode('v2node', 1, 'hidden-node', ['show' => 0]);

        $this->postJson($this->url . '/nodes/delete', [
            'nodes' => [['type' => 'v2node', 'id' => 1]],
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.nodes.0.published', false);
    }

    public function testBatchDeleteRequiresConfirmationAndRejectsUnknownNodes(): void
    {
        $this->seedNode('trojan', 1, 'trojan-node');

        $this->postJson($this->url . '/nodes/delete', ['nodes' => [['type' => 'trojan', 'id' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->postJson($this->url . '/nodes/delete', ['nodes' => [], 'confirm' => true])
            ->assertStatus(422)->assertJsonValidationErrors('nodes');
        $this->postJson($this->url . '/nodes/delete', [
            'nodes' => [['type' => 'trojan', 'id' => 99]], 'confirm' => true,
        ])->assertStatus(422)->assertJsonPath('message', '选中的节点已不存在：trojan #99');

        $this->assertNotNull(DB::table('v2_server_trojan')->where('id', 1)->first());
    }

    public function testBatchDeleteRollsBackEverythingWhenOneNodeFailsToDelete(): void
    {
        $this->seedNode('v2node', 1, 'v2node-node');
        $this->seedNode('trojan', 1, 'trojan-node');

        $dispatcher = \App\Models\ServerTrojan::getEventDispatcher();
        \App\Models\ServerTrojan::setEventDispatcher(clone $dispatcher);
        \App\Models\ServerTrojan::deleting(function () { return false; });
        try {
            $this->postJson($this->url . '/nodes/delete', [
                'nodes' => [['type' => 'v2node', 'id' => 1], ['type' => 'trojan', 'id' => 1]],
                'confirm' => true,
            ])->assertStatus(500)->assertJsonPath('message', '节点删除失败，本次操作已回滚');

            $this->assertNotNull(DB::table('v2_server_v2node')->where('id', 1)->first());
            $this->assertNotNull(DB::table('v2_server_trojan')->where('id', 1)->first());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            \App\Models\ServerTrojan::setEventDispatcher($dispatcher);
        }
    }

    // ---- 批量下发协议配置（传输协议 + 协议配置 JSON） ----

    public function testProtocolPreviewReportsPerTypeStorageWithoutWriting(): void
    {
        $this->seedNode('v2node', 1, 'v2node-node', ['network' => 'tcp']);
        $this->seedNode('vmess', 1, 'vmess-node', ['network' => 'tcp']);
        $this->seedNode('tuic', 1, 'tuic-node');

        $this->postJson($this->url . '/protocol/preview', [
            'nodes' => [
                ['type' => 'v2node', 'id' => 1],
                ['type' => 'vmess', 'id' => 1],
                ['type' => 'tuic', 'id' => 1],
            ],
            'network' => 'ws',
            'network_settings' => ['path' => '/ws'],
        ])->assertOk()
            ->assertJsonPath('data.matched_count', 3)
            ->assertJsonPath('data.applicable_count', 2)
            ->assertJsonPath('data.changed_count', 2)
            ->assertJsonPath('data.nodes.0.network', 'tcp')
            ->assertJsonPath('data.nodes.0.new_network', 'ws')
            ->assertJsonPath('data.nodes.1.applicable', true)
            // tuic 的表里没有这两列：标不适用，而不是报错或静默当成已改
            ->assertJsonPath('data.nodes.2.applicable', false)
            ->assertJsonPath('data.nodes.2.new_network', null);

        $this->assertSame('tcp', (string) DB::table('v2_server_v2node')->where('id', 1)->value('network'));
        $this->assertNull(DB::table('v2_server_v2node')->where('id', 1)->value('network_settings'));
    }

    public function testProtocolApplyWritesEachTypeIntoItsOwnColumn(): void
    {
        foreach (['v2node', 'vmess', 'vless', 'trojan', 'tuic'] as $type) {
            $this->seedNode($type, 1, $type . '-node');
        }

        $this->postJson($this->url . '/protocol/apply', [
            'nodes' => [
                ['type' => 'v2node', 'id' => 1],
                ['type' => 'vmess', 'id' => 1],
                ['type' => 'vless', 'id' => 1],
                ['type' => 'trojan', 'id' => 1],
                ['type' => 'tuic', 'id' => 1],
            ],
            'network' => 'ws',
            'network_settings' => ['path' => '/ws', 'headers' => ['Host' => 'a.example']],
            'confirm' => true,
        ])->assertOk()
            ->assertJsonPath('data.requested_count', 5)
            ->assertJsonPath('data.updated_count', 4);

        // vmess 的列名是驼峰，其余三类是下划线
        $storages = [
            'v2node' => 'network_settings',
            'vmess' => 'networkSettings',
            'vless' => 'network_settings',
            'trojan' => 'network_settings',
        ];
        foreach ($storages as $type => $column) {
            $this->assertSame('ws', (string) DB::table('v2_server_' . $type)->where('id', 1)->value('network'), $type);
            $this->assertSame(
                ['path' => '/ws', 'headers' => ['Host' => 'a.example']],
                json_decode((string) DB::table('v2_server_' . $type)->where('id', 1)->value($column), true),
                $type
            );
        }
        $this->assertNull(DB::table('v2_server_tuic')->where('id', 1)->value('network'));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testProtocolApplyLeavesTheBlankFieldAlone(): void
    {
        $this->seedNode('v2node', 1, 'v2node-node', ['network' => 'tcp']);
        DB::table('v2_server_v2node')->where('id', 1)->update(['network_settings' => json_encode(['path' => '/old'])]);

        $this->postJson($this->url . '/protocol/apply', [
            'nodes' => [['type' => 'v2node', 'id' => 1]],
            'network' => 'ws',
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.updated_count', 1);

        $this->assertSame('ws', (string) DB::table('v2_server_v2node')->where('id', 1)->value('network'));
        // 只填了传输协议时，原有的协议配置必须原样留着
        $this->assertSame(
            ['path' => '/old'],
            json_decode((string) DB::table('v2_server_v2node')->where('id', 1)->value('network_settings'), true)
        );
    }

    public function testProtocolApplyAcceptsRawJsonText(): void
    {
        $this->seedNode('v2node', 1, 'v2node-node');

        $this->postJson($this->url . '/protocol/apply', [
            'nodes' => [['type' => 'v2node', 'id' => 1]],
            'network_settings' => '{"path":"/raw"}',
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.updated_count', 1);

        $this->assertSame(
            ['path' => '/raw'],
            json_decode((string) DB::table('v2_server_v2node')->where('id', 1)->value('network_settings'), true)
        );
    }

    public function testProtocolApplyRejectsBlankInputBadJsonAndUnknownNetwork(): void
    {
        $this->seedNode('v2node', 1, 'v2node-node');
        $base = ['nodes' => [['type' => 'v2node', 'id' => 1]]];

        $this->postJson($this->url . '/protocol/apply', $base + ['network' => '', 'network_settings' => '', 'confirm' => true])
            ->assertStatus(422)->assertJsonPath('message', '请至少选择传输协议或填写协议配置中的一项');
        $this->postJson($this->url . '/protocol/preview', $base)->assertStatus(422);

        $this->postJson($this->url . '/protocol/apply', $base + ['network_settings' => '{ not json', 'confirm' => true])
            ->assertStatus(422)->assertJsonPath('message', '协议配置不是合法的 JSON 对象');
        $this->postJson($this->url . '/protocol/apply', $base + ['network' => 'quic', 'confirm' => true])
            ->assertStatus(422)->assertJsonValidationErrors('network');
        $this->postJson($this->url . '/protocol/apply', $base + ['network' => 'ws'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->postJson($this->url . '/protocol/apply', [
            'nodes' => [['type' => 'v2node', 'id' => 99]], 'network' => 'ws', 'confirm' => true,
        ])->assertStatus(422)->assertJsonPath('message', '选中的节点已不存在：v2node #99');

        $this->assertNull(DB::table('v2_server_v2node')->where('id', 1)->value('network'));
    }

    public function testProtocolApplyRollsBackEverythingWhenOneNodeFailsToPersist(): void
    {
        $this->seedNode('v2node', 1, 'v2node-node', ['network' => 'tcp']);
        $this->seedNode('vmess', 1, 'vmess-node', ['network' => 'tcp']);

        $dispatcher = \App\Models\ServerVmess::getEventDispatcher();
        \App\Models\ServerVmess::setEventDispatcher(clone $dispatcher);
        \App\Models\ServerVmess::saving(function () { return false; });
        try {
            $this->postJson($this->url . '/protocol/apply', [
                'nodes' => [['type' => 'v2node', 'id' => 1], ['type' => 'vmess', 'id' => 1]],
                'network' => 'ws',
                'confirm' => true,
            ])->assertStatus(500)->assertJsonPath('message', '节点保存失败，本次操作已回滚');

            $this->assertSame('tcp', (string) DB::table('v2_server_v2node')->where('id', 1)->value('network'));
            $this->assertSame('tcp', (string) DB::table('v2_server_vmess')->where('id', 1)->value('network'));
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            \App\Models\ServerVmess::setEventDispatcher($dispatcher);
        }
    }
}
