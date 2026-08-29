<?php

namespace Tests\Unit;

use App\Models\SubscribeBlockRule;
use App\Models\Subscription;
use App\Services\SubscribeAuditService;
use App\Services\SubscribeGatewayService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;
use Tests\TestCase;

class SubscribeGatewayServiceTest extends TestCase
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

        Schema::create('v2_subscribe_block_rule', function (Blueprint $table) {
            $table->increments('id');
            $table->string('scope', 16);
            $table->integer('user_id')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 1000)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->text('reason')->nullable();
            $table->integer('blocked_by')->nullable();
            $table->integer('blocked_at')->nullable();
            $table->integer('expires_at')->nullable();
            $table->integer('released_by')->nullable();
            $table->integer('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });

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
    }

    public function testItUsesTheDocumentedBlockScopePriority(): void
    {
        $request = $this->request();
        $user = $this->user(101);
        $subscription = $this->subscription(201);
        $audit = new SubscribeAuditService();

        $this->rule('user_agent', ['user_agent_hash' => $audit->userAgentHash($request)]);
        $this->rule('ip', ['ip' => '198.51.100.20']);
        $this->rule('user', ['user_id' => 101]);
        $this->rule('subscription', ['subscription_id' => 201]);

        $result = (new SubscribeGatewayService())->inspect($request, $user, $subscription);

        $this->assertSame('blocked', $result['decision']);
        $this->assertSame('subscription', $result['block_scope']);
    }

    public function testItIgnoresExpiredRulesAndWritesBlockedAuditFields(): void
    {
        $request = $this->request();
        $user = $this->user(102);
        $subscription = $this->subscription(202);
        $this->rule('user', ['user_id' => 102, 'expires_at' => time() - 1]);

        $result = (new SubscribeGatewayService())->inspect($request, $user, $subscription);
        $this->assertSame('allowed', $result['decision']);

        $log = (new SubscribeAuditService())->record($request, $user, $subscription, [
            'decision' => 'blocked',
            'block_rule_id' => 99,
            'block_scope' => 'subscription',
            'block_reason' => 'manual review'
        ]);

        $this->assertSame('blocked', $log->decision);
        $this->assertSame(99, $log->block_rule_id);
        $this->assertSame('subscription', $log->block_scope);
        $this->assertSame('manual review', $log->block_reason);
    }

    private function request(): Request
    {
        return Request::create('/subscribe', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.20',
            'HTTP_USER_AGENT' => 'GatewayTest/1.0'
        ]);
    }

    private function user(int $id): stdClass
    {
        $user = new stdClass();
        $user->id = $id;
        return $user;
    }

    private function subscription(int $id): Subscription
    {
        $subscription = new Subscription();
        $subscription->id = $id;
        return $subscription;
    }

    private function rule(string $scope, array $attributes = []): SubscribeBlockRule
    {
        return SubscribeBlockRule::create(array_merge([
            'scope' => $scope,
            'status' => 'active',
            'reason' => 'test',
            'blocked_at' => time(),
            'created_at' => time(),
            'updated_at' => time()
        ], $attributes));
    }
}
