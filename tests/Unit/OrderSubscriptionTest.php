<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\User\UserController;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'logging.default' => 'stderr',
            'v2board.multi_subscription_enable' => 0,
            'v2board.new_order_event_id' => 0,
            'v2board.renew_order_event_id' => 0,
            'v2board.plan_change_enable' => 1,
            'v2board.surplus_enable' => 0,
            'v2board.show_subscribe_method' => 0,
            'v2board.subscribe_url' => 'https://example.test',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email')->nullable();
            $table->integer('plan_id')->nullable();
            $table->integer('group_id')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->bigInteger('transfer_enable')->default(0);
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->integer('expired_at')->nullable();
            $table->string('token');
            $table->string('uuid');
            $table->boolean('auto_renewal')->default(false);
            $table->boolean('banned')->default(false);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->integer('group_id')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->integer('transfer_enable');
            $table->integer('reset_traffic_method')->nullable();
        });
        Schema::create('v2_subscription', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('plan_id');
            $table->integer('group_id')->nullable();
            $table->integer('speed_limit')->nullable();
            $table->integer('device_limit')->nullable();
            $table->bigInteger('transfer_enable')->default(0);
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->integer('expired_at')->nullable();
            $table->string('token')->unique();
            $table->string('uuid');
            $table->bigInteger('node_user_id')->unique();
            $table->string('status')->default('active');
            $table->boolean('is_primary')->default(false);
            $table->boolean('auto_renewal')->default(false);
            $table->integer('started_at')->nullable();
            $table->integer('last_reset_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('plan_id');
            $table->integer('subscription_id')->nullable();
            $table->string('trade_no')->unique();
            $table->string('period');
            $table->integer('type');
            $table->integer('status')->default(1);
            $table->integer('total_amount')->default(1000);
            $table->integer('paid_at')->nullable();
            $table->string('callback_no')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });

        DB::table('v2_plan')->insert([
            ['id' => 1, 'name' => 'Original plan', 'group_id' => 1, 'transfer_enable' => 10],
            ['id' => 2, 'name' => 'Purchased plan', 'group_id' => 2, 'transfer_enable' => 50],
        ]);
    }

    /** @dataProvider missingPrimaryProvider */
    public function testOpeningARenewalRepairsTheUserBeforeCompletingTheOrder(?int $userPlanId, bool $explicitTarget): void
    {
        $user = $this->user($userPlanId);
        $subscription = $this->subscription($user);
        $expectedExpiry = strtotime('+1 month', $subscription->expired_at);
        $order = $this->order($user, [
            'subscription_id' => $explicitTarget ? $subscription->id : null,
        ]);

        $service = new OrderService($order);
        $this->assertNotFalse($service->open());

        $this->assertSame(3, $order->fresh()->status);
        $this->assertSame($subscription->id, $order->fresh()->subscription_id);
        $this->assertTrue($subscription->fresh()->is_primary);
        $this->assertSame(1, $user->fresh()->plan_id);
        $this->assertSame($expectedExpiry, $user->fresh()->expired_at);
        $this->assertSame($expectedExpiry, $subscription->fresh()->expired_at);
        $this->assertSame($subscription->token, $user->fresh()->token);

        $this->assertTrue($service->open());
        $this->assertSame($expectedExpiry, $subscription->fresh()->expired_at);
        $this->assertSame(1, Subscription::count());
    }

    public function missingPrimaryProvider(): array
    {
        return [
            'stale user plan, implicit renewal' => [1, false],
            'missing user plan, implicit renewal' => [null, false],
            'stale user plan, explicit renewal' => [1, true],
            'missing user plan, explicit renewal' => [null, true],
        ];
    }

    public function testReadingACompletedPlanChangeKeepsThePurchasedSubscription(): void
    {
        $user = $this->user();
        $older = $this->subscription($user);
        $primary = $this->subscription($user, ['is_primary' => true]);
        $order = $this->order($user, ['type' => 3, 'plan_id' => 2]);

        $this->assertNotFalse((new OrderService($order))->open());
        $this->assertSame(3, $order->fresh()->status);
        $this->assertSame(2, $user->fresh()->plan_id);

        $request = new Request();
        $request->user = ['id' => $user->id];
        $controller = new UserController();
        for ($read = 0; $read < 2; $read++) {
            $data = json_decode($controller->getSubscribe($request)->getContent(), true)['data'];
            $this->assertSame(2, $data['plan_id']);
            $this->assertSame('Purchased plan', $data['plan']['name']);
            $this->assertSame($primary->id, $data['subscription_id']);
            $this->assertSame(50 * 1073741824, $data['transfer_enable']);
            $this->assertSame(2, $user->fresh()->plan_id);
        }
        $this->assertFalse($older->fresh()->is_primary);
        $this->assertSame(1, $older->fresh()->plan_id);
    }

    public function testExplicitSecondaryRenewalKeepsTheUsersSelectedPrimary(): void
    {
        config(['v2board.multi_subscription_enable' => 1]);
        $user = $this->user();
        $primary = $this->subscription($user, ['is_primary' => true]);
        $secondary = $this->subscription($user, ['plan_id' => 2]);
        $expectedExpiry = strtotime('+1 month', $secondary->expired_at);
        $order = $this->order($user, ['plan_id' => 2, 'subscription_id' => $secondary->id]);

        $this->assertNotFalse((new OrderService($order))->open());

        $this->assertSame(3, $order->fresh()->status);
        $this->assertSame($secondary->id, $order->fresh()->subscription_id);
        $this->assertSame($expectedExpiry, $secondary->fresh()->expired_at);
        $this->assertFalse($secondary->fresh()->is_primary);
        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertSame(1, $user->fresh()->plan_id);
        $this->assertSame($primary->expired_at, $user->fresh()->expired_at);
    }

    /** @dataProvider ordinaryPurchaseProvider */
    public function testOrdinaryPurchaseUpdatesTheExistingSubscriptionAfterOrderReload(int $multiEnabled, bool $expired, int $planId): void
    {
        config(['v2board.multi_subscription_enable' => $multiEnabled]);
        $user = $this->user();
        $oldExpiry = time() + ($expired ? -86400 : 86400 * 10);
        DB::table('v2_user')->where('id', $user->id)->update(['expired_at' => $oldExpiry]);
        $user = $user->fresh();
        $primary = $this->subscription($user, ['is_primary' => true, 'expired_at' => $oldExpiry]);
        $order = $this->order($user, ['plan_id' => $planId]);

        // A normal purchase has neither subscription_id nor an explicit request for a new subscription.
        (new OrderService($order))->setOrderType($user);
        $order->save();
        $this->assertNotFalse((new OrderService($order->fresh()))->open());
        $completed = $order->fresh();

        $this->assertSame(3, $completed->status);
        $this->assertSame($primary->id, $completed->subscription_id);
        $this->assertSame(1, Subscription::count());
        $this->assertSame($planId, $user->fresh()->plan_id);
        $this->assertGreaterThan($oldExpiry, $user->fresh()->expired_at);
        $this->assertGreaterThan(time(), $user->fresh()->expired_at);
        $this->assertSame($primary->token, $user->fresh()->token);
        $this->assertSame($primary->uuid, $user->fresh()->uuid);

        $request = new Request();
        $request->user = ['id' => $user->id];
        $data = json_decode((new UserController())->getSubscribe($request)->getContent(), true)['data'];
        $this->assertSame($planId, $data['plan_id']);
        $this->assertSame($user->fresh()->expired_at, $data['expired_at']);
        $nodeUsers = (new ServerService())->getAvailableUsers([$planId]);
        $this->assertCount(1, $nodeUsers);
        $this->assertSame($primary->uuid, $nodeUsers->first()->uuid);

        $expiry = $user->fresh()->expired_at;
        $this->assertTrue((new OrderService($completed))->open());
        $this->assertSame($expiry, $user->fresh()->expired_at);
    }

    public function ordinaryPurchaseProvider(): array
    {
        return [
            'multiple subscriptions, expired plan change' => [1, true, 2],
            'multiple subscriptions, expired same plan' => [1, true, 1],
            'multiple subscriptions, active plan change' => [1, false, 2],
            'single subscription, expired plan change' => [0, true, 2],
        ];
    }

    public function testExplicitNewSubscriptionKeepsTheExistingPrimaryAfterOrderReload(): void
    {
        config(['v2board.multi_subscription_enable' => 1]);
        $user = $this->user();
        $primary = $this->subscription($user, ['is_primary' => true]);
        $order = $this->order($user, ['plan_id' => 2]);
        $service = new OrderService($order);
        $service->newSubscription = true;
        $service->setOrderType($user);
        $order->save();

        $this->assertNotFalse((new OrderService($order->fresh()))->open());

        $completed = $order->fresh();
        $this->assertSame(3, $completed->status);
        $this->assertNotSame($primary->id, $completed->subscription_id);
        $this->assertSame(2, Subscription::count());
        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertFalse($completed->subscription->is_primary);
        $this->assertSame(2, $completed->subscription->plan_id);
        $this->assertSame(1, $user->fresh()->plan_id);
        $this->assertSame($primary->expired_at, $user->fresh()->expired_at);
    }

    public function testSelectingASubscriptionReadBeforePaymentUsesThePurchasedEntitlements(): void
    {
        config(['v2board.multi_subscription_enable' => 1]);
        $user = $this->user();
        $primary = $this->subscription($user, ['is_primary' => true]);
        $targetBeforePayment = $this->subscription($user);
        $order = $this->order($user, ['plan_id' => 2, 'subscription_id' => $targetBeforePayment->id]);

        $this->assertNotFalse((new OrderService($order))->open());
        $paidSubscription = $targetBeforePayment->fresh();
        $this->assertSame(3, $order->fresh()->status);
        $this->assertSame(2, $paidSubscription->plan_id);

        // A primary-selection request may have loaded its models before the payment committed.
        $selected = (new \App\Services\SubscriptionService())->setPrimary($user, $targetBeforePayment);

        $this->assertTrue($selected->is_primary);
        $this->assertFalse($primary->fresh()->is_primary);
        $this->assertSame(2, $user->fresh()->plan_id);
        $this->assertSame(50 * 1073741824, $user->fresh()->transfer_enable);
        $this->assertSame($paidSubscription->expired_at, $user->fresh()->expired_at);
        $this->assertSame($paidSubscription->token, $user->fresh()->token);
    }

    private function user(?int $planId = 1): User
    {
        $id = DB::table('v2_user')->insertGetId([
            'email' => 'customer@example.test',
            'plan_id' => $planId,
            'group_id' => 1,
            'expired_at' => time() + 86400,
            'token' => 'user-token',
            'uuid' => 'user-uuid',
            'created_at' => time() - 86400,
            'updated_at' => time() - 86400,
        ]);
        return User::findOrFail($id);
    }

    private function subscription(User $user, array $attributes = []): Subscription
    {
        $number = Subscription::count() + 1;
        $id = DB::table('v2_subscription')->insertGetId(array_merge([
            'user_id' => $user->id,
            'plan_id' => 1,
            'group_id' => 1,
            'transfer_enable' => 10 * 1073741824,
            'token' => 'subscription-token-' . $number,
            'uuid' => 'subscription-uuid-' . $number,
            'node_user_id' => 1000000000 + $number,
            'expired_at' => time() + 86400 * 10,
            'created_at' => time() - 86400,
            'updated_at' => time() - 86400,
        ], $attributes));
        return Subscription::findOrFail($id);
    }

    private function order(User $user, array $attributes = []): Order
    {
        $id = DB::table('v2_order')->insertGetId(array_merge([
            'user_id' => $user->id,
            'plan_id' => 1,
            'trade_no' => 'paid-order-' . (Order::count() + 1),
            'period' => 'month_price',
            'type' => 2,
            'paid_at' => time() - 60,
            'callback_no' => 'verified-callback',
            'created_at' => time() - 120,
            'updated_at' => time() - 60,
        ], $attributes));
        return Order::findOrFail($id);
    }
}
