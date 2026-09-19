<?php

namespace Tests\Unit;

use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'logging.default' => 'stderr',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
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
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_subscription', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
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
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function testReadingTheSelectedPrimaryDoesNotSwitchBackToAnOlderPlan(): void
    {
        $user = $this->user(['plan_id' => 2]);
        $older = $this->subscription($user, ['plan_id' => 1]);
        $selected = $this->subscription($user, ['plan_id' => 2, 'is_primary' => true]);

        $actual = (new SubscriptionService())->ensurePrimary($user);

        $this->assertSame($selected->id, $actual->id);
        $this->assertTrue($selected->fresh()->is_primary);
        $this->assertFalse($older->fresh()->is_primary);
        $this->assertSame(2, $user->fresh()->plan_id);
        $this->assertSame($selected->token, $user->fresh()->token);
    }

    public function testExistingSubscriptionRepairsAnEmptyUserPlanAndMissingPrimaryFlag(): void
    {
        $user = $this->user(['plan_id' => null]);
        $paid = $this->subscription($user, [
            'plan_id' => 2,
            'group_id' => 3,
            'transfer_enable' => 50 * 1073741824,
            'expired_at' => time() + 86400 * 30,
        ]);

        $actual = (new SubscriptionService())->ensurePrimary($user);

        $this->assertNotNull($actual);
        $this->assertSame($paid->id, $actual->id);
        $this->assertTrue($paid->fresh()->is_primary);
        $this->assertSame(2, $user->fresh()->plan_id);
        $this->assertSame(3, $user->fresh()->group_id);
        $this->assertSame(50 * 1073741824, $user->fresh()->transfer_enable);
        $this->assertSame($paid->expired_at, $user->fresh()->expired_at);
        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
    }

    public function testSelectingTheCurrentPrimaryRepeatedlyKeepsTheSelectedPlan(): void
    {
        $user = $this->user();
        $selected = $this->subscription($user, ['is_primary' => true]);
        $newer = $this->subscription($user, ['plan_id' => 2]);
        $service = new SubscriptionService();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $actual = $service->setPrimary($user, $selected);
            $this->assertTrue($actual->is_primary);
            $this->assertSame($selected->id, $service->ensurePrimary($user)->id);
            $this->assertFalse($newer->fresh()->is_primary);
            $this->assertSame(1, $user->fresh()->plan_id);
            $this->assertSame(1, Subscription::where('user_id', $user->id)->where('is_primary', true)->count());
        }
    }

    public function testSelectingAStaleSubscriptionRechecksWhetherItWasRevoked(): void
    {
        $user = $this->user();
        $primary = $this->subscription($user, ['is_primary' => true]);
        $target = $this->subscription($user, ['plan_id' => 2]);
        DB::table('v2_subscription')->where('id', $target->id)->update(['status' => 'revoked']);

        try {
            (new SubscriptionService())->setPrimary($user, $target);
            $this->fail('A revoked subscription must not become primary through a stale model.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertFalse($target->fresh()->is_primary);
        $this->assertSame(1, $user->fresh()->plan_id);
    }

    public function testMissingPrimaryPrefersTheNewestUsableSubscription(): void
    {
        $user = $this->user();
        $this->subscription($user, ['plan_id' => 1]);
        $newestActive = $this->subscription($user, ['plan_id' => 2]);
        $this->subscription($user, ['plan_id' => 3, 'expired_at' => time() - 86400]);
        $this->subscription($user, ['plan_id' => 4, 'status' => 'revoked']);

        $actual = (new SubscriptionService())->ensurePrimary($user);

        $this->assertSame($newestActive->id, $actual->id);
        $this->assertSame(2, $user->fresh()->plan_id);
        $this->assertSame(1, Subscription::where('user_id', $user->id)->where('is_primary', true)->count());
    }

    public function testAnExplicitPrimaryIsKeptEvenWhenItHasExpired(): void
    {
        $user = $this->user();
        $this->subscription($user, ['plan_id' => 1]);
        $selected = $this->subscription($user, [
            'plan_id' => 2,
            'is_primary' => true,
            'status' => 'expired',
            'expired_at' => time() - 86400,
        ]);

        $actual = (new SubscriptionService())->ensurePrimary($user);

        $this->assertSame($selected->id, $actual->id);
        $this->assertSame(2, $user->fresh()->plan_id);
    }

    public function testRevokedSubscriptionsAreNotSelectedOrRecreatedFromTheUserMirror(): void
    {
        $user = $this->user();
        $revoked = $this->subscription($user, ['is_primary' => true, 'status' => 'revoked']);

        $actual = (new SubscriptionService())->ensurePrimary($user);

        $this->assertNull($actual);
        $this->assertSame('revoked', $revoked->fresh()->status);
        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
    }

    public function testLegacyMigrationUsesTheCurrentUserPlanInsteadOfAStaleCaller(): void
    {
        $user = $this->user(['plan_id' => 1]);
        DB::table('v2_user')->where('id', $user->id)->update([
            'plan_id' => 2,
            'group_id' => 3,
            'transfer_enable' => 50 * 1073741824,
            'token' => 'current-token',
            'uuid' => 'current-uuid',
        ]);

        $actual = (new SubscriptionService())->ensurePrimary($user);

        $this->assertSame(2, $actual->plan_id);
        $this->assertSame(3, $actual->group_id);
        $this->assertSame(50 * 1073741824, $actual->transfer_enable);
        $this->assertSame('current-token', $actual->token);
        $this->assertSame('current-uuid', $actual->uuid);
        $this->assertSame(2000000000 + $user->id, $actual->node_user_id);
        $this->assertTrue($actual->is_primary);
        $this->assertSame(2, $user->fresh()->plan_id);
        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
    }

    public function testAStaleCallerCannotRestoreAPlanThatWasRemoved(): void
    {
        $user = $this->user(['plan_id' => 1]);
        DB::table('v2_user')->where('id', $user->id)->update(['plan_id' => null]);

        $actual = (new SubscriptionService())->ensurePrimary($user);

        $this->assertNull($actual);
        $this->assertSame(0, Subscription::where('user_id', $user->id)->count());
        $this->assertNull($user->fresh()->plan_id);
    }

    public function testAUserWithoutAPlanOrSubscriptionStaysUnsubscribed(): void
    {
        $user = $this->user(['plan_id' => null]);

        $this->assertNull((new SubscriptionService())->ensurePrimary($user));
        $this->assertSame(0, Subscription::where('user_id', $user->id)->count());
    }

    private function user(array $attributes = []): User
    {
        $id = DB::table('v2_user')->insertGetId(array_merge([
            'plan_id' => 1,
            'group_id' => 1,
            'transfer_enable' => 10 * 1073741824,
            'token' => 'legacy-token',
            'uuid' => 'legacy-uuid',
            'expired_at' => time() + 86400,
            'created_at' => time() - 86400,
            'updated_at' => time() - 86400,
        ], $attributes));

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
            'status' => 'active',
            'is_primary' => false,
            'expired_at' => time() + 86400,
            'created_at' => time() - 86400,
            'updated_at' => time() - 86400,
        ], $attributes));

        return Subscription::findOrFail($id);
    }
}
