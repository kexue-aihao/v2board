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

        $response = (new RiskGatewayController())->fetch($request);
        $payload = json_decode($response->getContent(), true);

        $this->assertTrue($payload['available']);
        $this->assertSame(1, $payload['total']);
        $this->assertSame(101, $payload['data'][0]['user_id']);
    }
}
