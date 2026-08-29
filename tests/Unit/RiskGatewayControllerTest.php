<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\RiskGatewayController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RiskGatewayControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:'
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('v2_subscribe_request_log', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('user_agent', 1000);
            $table->char('ua_hash', 64);
            $table->string('request_ip', 45);
            $table->integer('requested_at');
            $table->string('decision', 16)->default('allowed');
            $table->unsignedBigInteger('block_rule_id')->nullable();
            $table->string('block_scope', 16)->nullable();
            $table->text('block_reason')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_subscribe_block_rule', function (Blueprint $table) {
            $table->increments('id');
        });
    }

    public function testUserFilterDoesNotConflictWithAuthenticatedAdministrator(): void
    {
        $now = time();
        DB::table('v2_subscribe_request_log')->insert([
            'user_id' => 101,
            'subscription_id' => null,
            'user_agent' => 'GatewayTest/1.0',
            'ua_hash' => hash('sha256', 'gateway-test'),
            'request_ip' => '198.51.100.10',
            'requested_at' => $now,
            'decision' => 'allowed',
            'block_rule_id' => null,
            'block_scope' => null,
            'block_reason' => null,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        $request = Request::create('/?user_filter=101', 'GET');
        // This mirrors Admin middleware, which writes the authenticated admin
        // into the request's `user` key for legacy controllers.
        $request->merge(['user' => ['id' => 1, 'is_admin' => true]]);

        $response = (new RiskGatewayController())->auditRecords($request);
        $payload = json_decode($response->getContent(), true);

        $this->assertTrue($payload['available']);
        $this->assertSame(1, $payload['total']);
        $this->assertSame(101, $payload['data'][0]['user_id']);
    }

    public function testSummaryViewsExposeAggregatesWithoutMmdbProvenance(): void
    {
        $this->createSummaryTables();
        $now = time();
        $uaHash = hash('sha256', 'GatewayTest/2.0');

        DB::table('v2_subscribe_request_log')->insert([
            'user_id' => 102,
            'subscription_id' => 301,
            'user_agent' => 'GatewayTest/2.0',
            'ua_hash' => $uaHash,
            'request_ip' => '198.51.100.11',
            'requested_at' => $now,
            'decision' => 'allowed',
            'block_rule_id' => null,
            'block_scope' => null,
            'block_reason' => null,
            'created_at' => $now,
            'updated_at' => $now
        ]);
        $auditId = (int)DB::getPdo()->lastInsertId();
        DB::table('v2_subscribe_ip_summary')->insert([
            'user_id' => 102,
            'request_ip' => '198.51.100.11',
            'hit_count' => 2,
            'first_seen_at' => $now - 10,
            'last_seen_at' => $now,
            'recent_audit_id' => $auditId,
            'recent_subscription_id' => 301,
            'recent_user_agent' => 'GatewayTest/2.0',
            'recent_decision' => 'allowed',
            'created_at' => $now,
            'updated_at' => $now
        ]);
        DB::table('v2_subscribe_user_agent_summary')->insert([
            'user_id' => 102,
            'ua_hash' => $uaHash,
            'user_agent' => 'GatewayTest/2.0',
            'hit_count' => 2,
            'first_seen_at' => $now - 10,
            'last_seen_at' => $now,
            'recent_audit_id' => $auditId,
            'recent_subscription_id' => 301,
            'recent_request_ip' => '198.51.100.11',
            'recent_decision' => 'allowed',
            'created_at' => $now,
            'updated_at' => $now
        ]);

        $controller = new RiskGatewayController();
        $ipPayload = json_decode($controller->ipRecords(Request::create('/', 'GET'))->getContent(), true);
        $uaPayload = json_decode($controller->userAgentRecords(Request::create('/', 'GET'))->getContent(), true);

        $this->assertTrue($ipPayload['available']);
        $this->assertSame(1, $ipPayload['total']);
        $this->assertSame(2, $ipPayload['data'][0]['request_count']);
        $this->assertSame(301, $ipPayload['data'][0]['subscription_id']);
        $this->assertSame($auditId, $ipPayload['data'][0]['latest_audit_id']);
        $this->assertArrayNotHasKey('source', $ipPayload['data'][0]['ip_location']);
        $this->assertArrayNotHasKey('matched_sources', $ipPayload['data'][0]['ip_location']);
        $this->assertArrayNotHasKey('catalog_version', $ipPayload['data'][0]['ip_location']);

        $this->assertTrue($uaPayload['available']);
        $this->assertSame(1, $uaPayload['total']);
        $this->assertSame('198.51.100.11', $uaPayload['data'][0]['latest_request_ip']);
        $this->assertSame(301, $uaPayload['data'][0]['subscription_id']);
    }

    public function testSummaryViewExplainsWhenRawAuditsExistButInitialBackfillWasMissed(): void
    {
        $this->createSummaryTables();
        $now = time();
        DB::table('v2_subscribe_request_log')->insert([
            'user_id' => 104,
            'subscription_id' => null,
            'user_agent' => 'GatewayTest/4.0',
            'ua_hash' => hash('sha256', 'GatewayTest/4.0'),
            'request_ip' => '198.51.100.13',
            'requested_at' => $now,
            'decision' => 'allowed',
            'block_rule_id' => null,
            'block_scope' => null,
            'block_reason' => null,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        $response = (new RiskGatewayController())->ipRecords(Request::create('/', 'GET'));
        $payload = json_decode($response->getContent(), true);

        $this->assertTrue($payload['available']);
        $this->assertSame(0, $payload['total']);
        $this->assertStringContainsString('audit:backfill-summaries', $payload['error']);
    }

    public function testDetailAcceptsIdAndReturnsRelatedRawAuditRecords(): void
    {
        $now = time();
        foreach ([1, 2] as $offset) {
            DB::table('v2_subscribe_request_log')->insert([
                'user_id' => 103,
                'subscription_id' => 302,
                'user_agent' => 'GatewayTest/3.0',
                'ua_hash' => hash('sha256', 'GatewayTest/3.0'),
                'request_ip' => '198.51.100.12',
                'requested_at' => $now + $offset,
                'decision' => 'allowed',
                'block_rule_id' => null,
                'block_scope' => null,
                'block_reason' => null,
                'created_at' => $now + $offset,
                'updated_at' => $now + $offset
            ]);
        }

        $response = (new RiskGatewayController())->detail(Request::create(
            '/?id=2&summary_type=ip&pageSize=1',
            'GET'
        ));
        $payload = json_decode($response->getContent(), true);

        $this->assertTrue($payload['available']);
        $this->assertSame(2, $payload['data']['id']);
        $this->assertSame(2, $payload['data']['raw_total']);
        $this->assertCount(1, $payload['data']['raw_records']);
        $this->assertSame(2, $payload['data']['raw_records'][0]['id']);
    }

    private function createSummaryTables(): void
    {
        Schema::create('v2_subscribe_ip_summary', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('request_ip', 45);
            $table->unsignedBigInteger('hit_count');
            $table->unsignedBigInteger('first_seen_at');
            $table->unsignedBigInteger('last_seen_at');
            $table->unsignedBigInteger('recent_audit_id');
            $table->unsignedBigInteger('recent_subscription_id')->nullable();
            $table->string('recent_user_agent', 1000);
            $table->string('recent_decision', 16);
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->unique(['user_id', 'request_ip']);
        });
        Schema::create('v2_subscribe_user_agent_summary', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->char('ua_hash', 64);
            $table->string('user_agent', 1000);
            $table->unsignedBigInteger('hit_count');
            $table->unsignedBigInteger('first_seen_at');
            $table->unsignedBigInteger('last_seen_at');
            $table->unsignedBigInteger('recent_audit_id');
            $table->unsignedBigInteger('recent_subscription_id')->nullable();
            $table->string('recent_request_ip', 45);
            $table->string('recent_decision', 16);
            $table->integer('created_at');
            $table->integer('updated_at');
            $table->unique(['user_id', 'ua_hash']);
        });
    }
}
