<?php

namespace Tests\Feature;

use App\Services\ExternalSubscriptionParser;
use App\Services\ExternalSubscriptionService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 外部订阅：解析、抓取入库、以及挂进订阅时那一行的形状。
 *
 * 抓取用 Guzzle 的 MockHandler 注入：跑的是真实的 Guzzle 调用链（状态码、响应体大小、
 * 超时参数都在里面），只是不发真请求。
 */
class ExternalSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

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
    }

    private function service(string $body, int $status = 200): ExternalSubscriptionService
    {
        $stack = HandlerStack::create(new MockHandler([new Response($status, [], $body)]));

        return new ExternalSubscriptionService(new Client(['handler' => $stack]));
    }

    // ---------------------------------------------------------------- 解析器

    public function testParserReadsBase64UriListAndKeepsTheRawUri(): void
    {
        $body = base64_encode(implode("\n", [
            'vless://11111111-2222-3333-4444-555555555555@a.example:443?security=tls&type=ws&path=%2Fws&sni=a.example#HK-01',
            'trojan://secret@b.example:443?security=tls&sni=b.example#JP-02'
        ]));

        $result = (new ExternalSubscriptionParser())->parse($body);

        $this->assertTrue($result['base64']);
        $this->assertSame(2, $result['count']);
        $this->assertSame('vless', $result['nodes'][0]['protocol']);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $result['nodes'][0]['uuid']);
        $this->assertSame('a.example', $result['nodes'][0]['host']);
        $this->assertSame(443, $result['nodes'][0]['port']);
        $this->assertSame(1, $result['nodes'][0]['tls']);
        $this->assertSame('ws', $result['nodes'][0]['network']);
        $this->assertSame('/ws', $result['nodes'][0]['path']);
        $this->assertSame('HK-01', $result['nodes'][0]['name']);
        $this->assertStringStartsWith('vless://', $result['nodes'][0]['raw_uri']);

        $this->assertSame('trojan', $result['nodes'][1]['protocol']);
        $this->assertSame('secret', $result['nodes'][1]['password']);
    }

    public function testParserReadsPlainTextAndSkipsWhatItCannotRead(): void
    {
        $body = "not-a-uri\n"
            . "vmess://" . base64_encode(json_encode(['ps' => 'vmess 01', 'add' => 'c.example', 'port' => '8443', 'id' => 'uuid-x', 'net' => 'grpc', 'tls' => 'tls', 'sni' => 'c.example'])) . "\n"
            . "ss://" . base64_encode('aes-256-gcm:pass123') . "@d.example:8388#ss-01\n"
            . "clash://something\n";

        $result = (new ExternalSubscriptionParser())->parse($body);

        $this->assertFalse($result['base64']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(2, $result['skipped']);

        $this->assertSame('vmess', $result['nodes'][0]['protocol']);
        $this->assertSame('c.example', $result['nodes'][0]['host']);
        $this->assertSame('uuid-x', $result['nodes'][0]['uuid']);
        $this->assertSame(1, $result['nodes'][0]['tls']);

        $this->assertSame('shadowsocks', $result['nodes'][1]['protocol']);
        $this->assertSame('aes-256-gcm', $result['nodes'][1]['cipher']);
        $this->assertSame('pass123', $result['nodes'][1]['password']);
    }

    public function testParserTreatsVmessNoneAsPlaintext(): void
    {
        $body = "vmess://" . base64_encode(json_encode(['add' => 'c.example', 'port' => 80, 'id' => 'uuid', 'tls' => 'none']));

        $this->assertSame(0, (new ExternalSubscriptionParser())->parse($body)['nodes'][0]['tls']);
    }

    public function testParserDropsDuplicatesAndCapsTheNodeCount(): void
    {
        $line = 'trojan://p@dup.example:443#dupe';
        $this->assertSame(1, (new ExternalSubscriptionParser())->parse($line . "\n" . $line)['count']);

        $lines = [];
        for ($i = 0; $i < ExternalSubscriptionParser::MAX_NODES + 50; $i++) {
            $lines[] = 'trojan://p@node' . $i . '.example:443#n' . $i;
        }
        $this->assertSame(ExternalSubscriptionParser::MAX_NODES, (new ExternalSubscriptionParser())->parse(implode("\n", $lines))['count']);
    }

    // ---------------------------------------------------------------- 抓取入库

    public function testRefreshImportsNodesAndRecordsStatus(): void
    {
        $service = new ExternalSubscriptionService();
        $id = $service->saveSource(['name' => '机场 1', 'url' => 'https://sub.example/a', 'group_id' => 2, 'enabled' => 1]);

        $body = base64_encode("trojan://p@a.example:443#A\nvless://u@b.example:443?security=tls#B");
        $result = $this->service($body)->refresh($id);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['count']);
        $this->assertSame(2, DB::table('v2_external_node')->where('source_id', $id)->count());

        $source = DB::table('v2_external_source')->where('id', $id)->first();
        $this->assertSame('ok', $source->last_status);
        $this->assertSame(2, (int) $source->node_count);
        $this->assertNull($source->last_error);
        $this->assertGreaterThan(0, (int) $source->last_fetch_at);
    }

    public function testFailedRefreshKeepsThePreviouslyImportedNodes(): void
    {
        $service = new ExternalSubscriptionService();
        $id = $service->saveSource(['name' => '机场 1', 'url' => 'https://sub.example/a', 'group_id' => 2]);
        $this->service(base64_encode('trojan://p@a.example:443#A'))->refresh($id);
        $this->assertSame(1, DB::table('v2_external_node')->where('source_id', $id)->count());

        // 源抽风：HTTP 500
        $result = $this->service('boom', 500)->refresh($id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HTTP 500', $result['error']);
        // 关键：已经导入的节点不能被清掉，否则用户的备用线路会凭空消失
        $this->assertSame(1, DB::table('v2_external_node')->where('source_id', $id)->count());
        $source = DB::table('v2_external_source')->where('id', $id)->first();
        $this->assertSame('error', $source->last_status);
        $this->assertNotNull($source->last_error);
    }

    public function testUnsupportedSubscriptionFormatIsReportedInsteadOfImportingNothing(): void
    {
        $service = new ExternalSubscriptionService();
        $id = $service->saveSource(['name' => '机场 2', 'url' => 'https://sub.example/b']);

        $result = $this->service("proxies:\n  - name: x\n")->refresh($id);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('没有解析出任何节点', $result['error']);
        $this->assertSame(0, DB::table('v2_external_node')->where('source_id', $id)->count());
    }

    public function testDryRunParsesWithoutWritingAnything(): void
    {
        $service = new ExternalSubscriptionService();
        $id = $service->saveSource(['name' => '机场 1', 'url' => 'https://sub.example/a']);

        $result = $this->service(base64_encode('trojan://p@a.example:443#A'))->refresh($id, true);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('A', $result['nodes'][0]['name']);
        $this->assertSame(0, DB::table('v2_external_node')->count());
        $this->assertSame('never', DB::table('v2_external_source')->where('id', $id)->value('last_status'));
    }

    public function testSourceUrlMustBeHttp(): void
    {
        $this->expectException(HttpException::class);
        (new ExternalSubscriptionService())->normalizeSource(['name' => 'x', 'url' => 'file:///etc/passwd']);
    }

    // ---------------------------------------------------------------- 注入订阅

    public function testServerRowsOnlyIncludeEnabledSourcesOfTheUsersGroups(): void
    {
        $service = new ExternalSubscriptionService();
        $mine = $service->saveSource(['name' => '我的机场', 'url' => 'https://sub.example/a', 'group_id' => 2, 'enabled' => 1]);
        $other = $service->saveSource(['name' => '别人的', 'url' => 'https://sub.example/b', 'group_id' => 3, 'enabled' => 1]);
        $off = $service->saveSource(['name' => '停用的', 'url' => 'https://sub.example/c', 'group_id' => 2, 'enabled' => 0]);
        foreach ([$mine, $other, $off] as $index => $id) {
            $this->service(base64_encode('trojan://p@node' . $index . '.example:443#N' . $index))->refresh($id);
        }

        $rows = $service->serverRowsForGroups([2]);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('external', $row['type']);
        $this->assertSame('trojan', $row['protocol']);
        $this->assertSame('【过渡】N0', $row['name']);
        $this->assertLessThan(0, $row['id'], '注入行的 id 取负数，避免与真实节点撞号');
        $this->assertStringStartsWith('trojan://', $row['_external_uri']);
        $this->assertSame('1', $row['rate'], '过渡节点不参与计费，但显示 1 而不是 0');
        // getAvailableServers() 之后要靠这两个字段算 is_online 与 cache_key
        $this->assertArrayHasKey('last_check_at', $row);
        $this->assertArrayHasKey('updated_at', $row);
        // 凭据交给渲染器：Clash 系与 sing-box 靠它用对方机场的 uuid/密码渲染
        $this->assertSame('p', $row['_external_creds']['password']);
        // 字段映射：builder 会读的这些键要在
        $this->assertArrayHasKey('tls', $row);
        $this->assertArrayHasKey('tls_settings', $row);
        $this->assertArrayHasKey('network', $row);
        $this->assertArrayHasKey('networkSettings', $row);
        $this->assertArrayHasKey('server_name', $row);

        $this->assertSame([], $service->serverRowsForGroups([]));
        $this->assertSame([], $service->serverRowsForGroups([999]));
    }

    public function testShadowsocks2022NodesAreNotHandedToTheClashFamily(): void
    {
        $service = new ExternalSubscriptionService();
        $id = $service->saveSource(['name' => 'ss', 'url' => 'https://sub.example/s', 'group_id' => 1]);
        $body = base64_encode(
            "ss://" . base64_encode('2022-blake3-aes-128-gcm:pw') . "@a.example:8388#legacy-2022\n"
            . "ss://" . base64_encode('aes-256-gcm:pw2') . "@b.example:8388#plain-ss"
        );
        $this->service($body)->refresh($id);

        $rows = $service->serverRowsForGroups([1]);
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = $row;
        }

        // 2022 系列的密码是「服务端密钥:用户密钥」，builder 会拿 created_at 现算用户密钥 ——
        // 外部节点没有那套上下文，交出去只会渲染出一个连不上的节点，所以不给凭据。
        $this->assertArrayNotHasKey('_external_creds', $byName['【过渡】legacy-2022']);
        $this->assertArrayHasKey('_external_creds', $byName['【过渡】plain-ss']);
        // 两者都仍然带原始 URI：v2ray 系客户端照常能看到它们
        $this->assertStringStartsWith('ss://', $byName['【过渡】legacy-2022']['_external_uri']);
    }

    public function testDeletingASourceRemovesItsNodes(): void
    {
        $service = new ExternalSubscriptionService();
        $id = $service->saveSource(['name' => '机场', 'url' => 'https://sub.example/a', 'group_id' => 1]);
        $this->service(base64_encode('trojan://p@a.example:443#A'))->refresh($id);

        $this->assertTrue($service->deleteSource($id));
        $this->assertSame(0, DB::table('v2_external_node')->count());
        $this->assertSame(0, DB::table('v2_external_source')->count());
    }
}
