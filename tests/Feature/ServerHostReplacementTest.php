<?php

namespace Tests\Feature;

use App\Http\Middleware\Admin;
use App\Models\ServerVmess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ServerHostReplacementTest extends TestCase
{
    private const TYPES = ['shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria', 'anytls', 'v2node'];

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
                $table->string('server_name')->nullable();
                $table->text('tls_settings')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }
    }

    private function seedNodes(string $host): void
    {
        foreach (self::TYPES as $type) {
            DB::table('v2_server_' . $type)->insert([
                'id' => 1, 'name' => $type, 'host' => $host,
                'server_name' => 'sni.example', 'tls_settings' => '{"serverName":"sni.example"}',
            ]);
        }
    }

    public function testConfirmedExactReplacementPersistsAndAppearsInNodeList(): void
    {
        $this->seedNodes('old.example');
        $payload = ['mode' => 'exact', 'old_host' => 'old.example', 'new_host' => 'new.example'];
        $this->postJson($this->url . '/host/preview', $payload)->assertOk()->assertJsonPath('data.matched_count', 8);
        $this->assertDatabaseHas('v2_server_vmess', ['host' => 'old.example']);

        $this->postJson($this->url . '/host/replace', $payload + ['confirm' => true])->assertOk()->assertJsonPath('data.updated_count', 8);
        foreach (self::TYPES as $type) {
            $this->assertDatabaseHas('v2_server_' . $type, ['id' => 1, 'host' => 'new.example', 'server_name' => 'sni.example', 'tls_settings' => '{"serverName":"sni.example"}']);
        }
        $response = $this->getJson($this->url . '/getNodes')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(8, $response->json('data'));
        foreach ($response->json('data') as $node) {
            $this->assertSame('new.example', $node['host']);
        }
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testContainsReplacementPreservesSubdomainAndDoesNotReplaceUnmatchedNodes(): void
    {
        $this->seedNodes('hk.old.example');
        DB::table('v2_server_vmess')->insert(['id' => 2, 'name' => 'unmatched', 'host' => 'keep.example']);
        $payload = ['mode' => 'contains', 'old_host' => 'old.example', 'new_host' => 'new.example', 'confirm' => true];
        $this->postJson($this->url . '/host/replace', $payload)->assertOk()->assertJsonPath('data.updated_count', 8);
        $this->assertDatabaseHas('v2_server_vmess', ['id' => 1, 'host' => 'hk.new.example']);
        $this->assertDatabaseHas('v2_server_vmess', ['id' => 2, 'host' => 'keep.example']);
    }

    public function testConfirmationIsRequiredBeforeWriting(): void
    {
        $this->seedNodes('old.example');
        $this->postJson($this->url . '/host/replace', ['mode' => 'exact', 'old_host' => 'old.example', 'new_host' => 'new.example'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm');
        $this->assertDatabaseHas('v2_server_vmess', ['host' => 'old.example']);
    }

    public function testRejectedModelSaveRollsBackPreviouslyUpdatedProtocols(): void
    {
        $this->seedNodes('old.example');
        $dispatcher = ServerVmess::getEventDispatcher();
        ServerVmess::setEventDispatcher(clone $dispatcher);
        ServerVmess::saving(function () { return false; });
        try {
            $this->postJson($this->url . '/host/replace', [
                'mode' => 'exact', 'old_host' => 'old.example', 'new_host' => 'new.example', 'confirm' => true,
            ])->assertStatus(500)->assertJsonPath('message', '节点域名保存失败，本次替换已回滚');
            foreach (self::TYPES as $type) {
                $this->assertDatabaseHas('v2_server_' . $type, ['id' => 1, 'host' => 'old.example']);
            }
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            ServerVmess::setEventDispatcher($dispatcher);
        }
    }

    public function testUnpersistedHostIsDetectedAndEntireReplacementRollsBack(): void
    {
        $this->seedNodes('old.example');
        DB::statement('CREATE TRIGGER restore_vmess_host AFTER UPDATE OF host ON v2_server_vmess
            BEGIN UPDATE v2_server_vmess SET host = OLD.host WHERE id = NEW.id; END');
        $this->postJson($this->url . '/host/replace', [
            'mode' => 'exact', 'old_host' => 'old.example', 'new_host' => 'new.example', 'confirm' => true,
        ])->assertStatus(500)->assertJsonPath('message', '节点域名保存失败，本次替换已回滚');
        $this->assertDatabaseHas('v2_server_shadowsocks', ['id' => 1, 'host' => 'old.example']);
        $this->assertDatabaseHas('v2_server_vmess', ['id' => 1, 'host' => 'old.example']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testOverlongResultIsRejectedDuringPreviewAndRollsBackOnReplace(): void
    {
        $this->seedNodes('old.example');
        DB::table('v2_server_vmess')->where('id', 1)->update(['host' => str_repeat('a', 240) . '.old.example']);
        $payload = ['mode' => 'contains', 'old_host' => 'old.example', 'new_host' => 'replacement.example'];
        $this->postJson($this->url . '/host/preview', $payload)->assertStatus(422);
        $this->postJson($this->url . '/host/replace', $payload + ['confirm' => true])->assertStatus(422);
        $this->assertDatabaseHas('v2_server_shadowsocks', ['id' => 1, 'host' => 'old.example']);
        $this->assertDatabaseHas('v2_server_vmess', ['id' => 1, 'host' => str_repeat('a', 240) . '.old.example']);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function testNoMatchesReportsZeroWithoutModifyingNodes(): void
    {
        $this->seedNodes('keep.example');
        $payload = ['mode' => 'exact', 'old_host' => 'old.example', 'new_host' => 'new.example'];
        $this->postJson($this->url . '/host/preview', $payload)
            ->assertOk()->assertJsonPath('data.matched_count', 0)->assertJsonPath('data.nodes', []);
        $this->postJson($this->url . '/host/replace', $payload + ['confirm' => true])
            ->assertOk()->assertJsonPath('data.updated_count', 0)->assertJsonPath('data.nodes', []);
        $this->assertDatabaseHas('v2_server_vmess', ['host' => 'keep.example']);
    }
}
