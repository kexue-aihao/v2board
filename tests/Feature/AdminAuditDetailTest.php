<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\AdminAccessService;
use App\Services\AuthService;
use App\Services\SecurityAuditBusiness;
use App\Services\SecurityAuditMutation;
use App\Services\SecurityAuditService;
use App\Services\ServerBatchOperationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AdminSecurityFixture;
use Tests\TestCase;

class AdminAuditDetailTest extends TestCase
{
    private $base;
    private $mysqlDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array',
            'logging.default' => 'null', 'v2board.site_status' => 'normal', 'v2board.currency' => 'CNY', 'admin_security.audit_key' => 'detail-test-key']);
        DB::purge('sqlite'); DB::reconnect('sqlite');
        // Optional integration run against a disposable local MySQL/MariaDB.
        // Always create our own random database; never clear an existing one.
        if (getenv('ADMIN_AUDIT_MYSQL_PORT')) {
            config(['database.connections.audit_mysql' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int)getenv('ADMIN_AUDIT_MYSQL_PORT'),
                'username' => getenv('ADMIN_AUDIT_MYSQL_USER') ?: 'root', 'password' => getenv('ADMIN_AUDIT_MYSQL_PASSWORD') ?: '',
                'database' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
            ]]);
            $name = 'audit_detail_test_' . bin2hex(random_bytes(8));
            DB::connection('audit_mysql')->statement('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
            $this->mysqlDatabase = $name;
            DB::purge('audit_mysql');
            config(['database.default' => 'audit_mysql', 'database.connections.audit_mysql.database' => $name]);
        }
        Schema::create('v2_user', function (Blueprint $t) {
            $t->increments('id'); $t->string('email'); $t->boolean('is_admin')->default(0); $t->boolean('is_staff')->default(0);
            $t->boolean('banned')->default(0); $t->string('password')->nullable(); $t->string('password_algo')->nullable(); $t->string('password_salt')->nullable();
            $t->string('token')->nullable(); $t->string('uuid')->nullable(); $t->integer('plan_id')->nullable(); $t->integer('group_id')->nullable();
            $t->bigInteger('transfer_enable')->default(107374182400); $t->integer('device_limit')->default(3); $t->integer('speed_limit')->nullable();
            $t->integer('balance')->default(12345); $t->integer('expired_at')->nullable(); $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        DB::table('v2_user')->insert([['id' => 1, 'email' => 'founder@example.test', 'is_admin' => 1], ['id' => 6, 'email' => 'member@example.test', 'is_admin' => 0]]);
        AdminSecurityFixture::install();
        DB::table('v2_user')->insert(['id' => 2, 'email' => 'ops@example.test', 'is_admin' => 1, 'admin_role' => 'operations', 'admin_version' => 1]);
        $this->base = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->mysqlDatabase && preg_match('/^audit_detail_test_[a-f0-9]{16}$/', $this->mysqlDatabase)) {
                $this->app->make('request')->attributes->remove('security_audit_context');
                DB::connection('audit_mysql')->statement('DROP DATABASE `' . $this->mysqlDatabase . '`');
                DB::purge('audit_mysql');
            }
        } finally { parent::tearDown(); }
    }

    private function headers(int $id = 1): array
    {
        return ['Authorization' => (new AuthService(User::findOrFail($id)))->generateAuthData(Request::create('/'), true)['auth_data']];
    }

    private function audited(string $action, array $input, callable $callback, string $method = 'POST')
    {
        $request = Request::create('/detail-fixture', $method, $input);
        $action = 'App\\Http\\Controllers\\V1\\Admin\\' . $action;
        $route = new Route($method, '/detail-fixture', ['uses' => $action, 'controller' => $action]);
        $request->setRouteResolver(function () use ($route) { return $route; });
        $this->app->instance('request', $request);
        return SecurityAuditService::run($request, AdminAccessService::actor(User::find(1)), $callback);
    }

    private function finish(): array
    {
        return json_decode(DB::table('v2_admin_audit')->where('event', 'request.finish')->orderByDesc('id')->first()->payload, true);
    }

    private function items(string $event = 'business.changes'): array
    {
        $items = [];
        foreach (DB::table('v2_admin_audit')->where('event', $event)->get() as $row) {
            $items = array_merge($items, json_decode($row->payload, true)['details']['business']['items']);
        }
        return $items;
    }

    public function testUserEditRecordsRealValuesUnitsAndSensitiveChangeMarkers(): void
    {
        $this->audited('UserController@update', ['id' => 6], function () {
            User::find(6)->update(['transfer_enable' => 214748364800, 'device_limit' => 5, 'balance' => 23456, 'password' => 'never-persist-password']);
            return response(['data' => true]);
        });
        $items = $this->items(); $fields = array_column($items[0]['fields'], null, 'field');
        $this->assertSame('100 GB', $fields['transfer_enable']['before_label']);
        $this->assertSame('200 GB', $fields['transfer_enable']['after_label']);
        $this->assertSame('123.45 CNY', $fields['balance']['before_label']);
        $this->assertSame('234.56 CNY', $fields['balance']['after_label']);
        $this->assertSame('5 台', $fields['device_limit']['after_label']);
        $this->assertTrue($fields['password']['sensitive']);
        $this->assertNull($fields['password']['after']);
        $this->assertSame('已修改', $fields['password']['after_label']);
        $this->assertArrayNotHasKey('updated_at', $fields);
        $this->assertStringNotContainsString('never-persist-password', DB::table('v2_admin_audit')->pluck('payload')->implode(''));
        $this->assertSame('founder@example.test', $this->finish()['details']['business']['actor']['email']);
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testNoOpDoesNotManufactureAChangeFromTimestampWrites(): void
    {
        $this->audited('UserController@update', ['id' => 6], function () {
            SecurityAuditMutation::update(User::where('id', 6), ['device_limit' => 3]);
            return response(['data' => true]);
        });
        $this->assertSame([], $this->items());
        $this->assertSame('no_change', $this->finish()['details']['business']['state']);
        $this->assertSame(1, $this->finish()['details']['business']['result']['unchanged_count']);
    }

    public function testMoreThanOneThousandBuilderChangesAreCompleteAndFilterable(): void
    {
        $rows = [];
        for ($i = 100; $i < 1305; $i++) $rows[] = ['id' => $i, 'email' => 'member' . $i . '@example.test', 'plan_id' => 7];
        foreach (array_chunk($rows, 200) as $chunk) DB::table('v2_user')->insert($chunk);
        $this->audited('UserController@update', [], function () {
            SecurityAuditMutation::update(User::where('plan_id', 7), ['device_limit' => 5]);
            DB::table('v2_user')->insert(['id' => 2000, 'email' => 'later@example.test', 'plan_id' => 7]);
            return response(['data' => true]);
        });
        $items = $this->items();
        $this->assertCount(1205, $items);
        $this->assertSame(range(100, 1304), array_column(array_column($items, 'object'), 'id'));
        $this->assertCount(13, $this->finish()['details']['business']['detail_records']);
        $this->assertDatabaseHas('v2_user', ['id' => 2000, 'device_limit' => 3]);
        $finishedId = DB::table('v2_admin_audit')->where('event', 'request.finish')->max('id');
        $records = $this->getJson($this->base . '/security/audit?' . http_build_query(['view' => 'operations', 'module' => 'users', 'object' => '1304', 'field' => 'device_limit']), $this->headers())->assertOk()->json('data');
        $this->assertCount(1, $records);
        $this->assertSame($finishedId, $records[0]['id']);
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testDeletedObjectsKeepTheirBeforeSnapshotAndRollbacksKeepOnlyAttempts(): void
    {
        $this->audited('UserController@delUser', ['id' => 6], function () {
            SecurityAuditMutation::delete(User::where('id', 6));
            return response(['data' => true]);
        });
        $item = $this->items()[0];
        $this->assertSame('deleted', $item['operation']);
        $this->assertSame('member@example.test', $item['object']['name']);
        $this->assertSame('member@example.test', array_column($item['fields'], null, 'field')['email']['before']);
        $this->assertDatabaseMissing('v2_user', ['id' => 6]);
        DB::table('v2_user')->insert(['id' => 6, 'email' => 'restored@example.test']);
        try {
            $this->audited('UserController@update', ['id' => 6], function () {
                SecurityAuditMutation::update(User::where('id', 6), ['email' => 'must-rollback@example.test']);
                SecurityAuditService::effect('远程效果已发生', ['目标编号' => 6], 'success', true);
                throw new \RuntimeException('secret-exception-text');
            });
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {}
        $this->assertDatabaseHas('v2_user', ['id' => 6, 'email' => 'restored@example.test']);
        $failed = $this->finish();
        $this->assertSame(0, $failed['details']['business']['counts']['updated']);
        $this->assertSame('远程效果已发生', $failed['details']['business']['effects'][0]['label']);
        $payloads = DB::table('v2_admin_audit')->pluck('payload')->implode('');
        $this->assertStringNotContainsString('must-rollback@example.test', $payloads);
        $this->assertStringNotContainsString('secret-exception-text', $payloads);
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testHttp200BusinessFailureIsNotMarkedSuccessful(): void
    {
        $this->audited('ExternalSourceController@refreshSource', ['id' => 9], function () {
            return response(['data' => ['source_id' => 9, 'ok' => false, 'error' => 'https://example.test/token-secret']]);
        });
        $this->assertSame('failure', $this->finish()['result']);
        $this->assertSame('failure', $this->finish()['details']['business']['state']);
        $this->assertStringNotContainsString('token-secret', DB::table('v2_admin_audit')->pluck('payload')->implode(''));
    }

    public function testNewQueryBuilderObjectsUseTheirOwnIdAndHideEmbeddedCredentials(): void
    {
        Schema::create('v2_external_source', function (Blueprint $t) {
            $t->increments('id'); $t->string('name'); $t->string('url'); $t->integer('group_id'); $t->integer('enabled');
            $t->string('remark')->nullable(); $t->integer('created_at'); $t->integer('updated_at');
        });
        $this->audited('ExternalSourceController@saveSource', [], function () {
            $id = (new \App\Services\ExternalSubscriptionService())->saveSource(['name' => '备用源', 'url' => 'https://user:secret-url-password@source.test/sub-secret?token=secret-query', 'group_id' => 1]);
            DB::table('v2_external_source')->insert(['name' => '其他请求', 'url' => 'https://other.test', 'group_id' => 2, 'enabled' => 1, 'created_at' => time(), 'updated_at' => time()]);
            return response(['data' => ['id' => $id]]);
        });
        $items = $this->items();
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]['object']['id']);
        $this->assertSame('备用源', $items[0]['object']['name']);
        $all = DB::table('v2_admin_audit')->pluck('payload')->implode('');
        $this->assertStringNotContainsString('secret-url-password', $all);
        $this->assertStringNotContainsString('secret-query', $all);
        $this->assertStringNotContainsString('sub-secret', $all);
    }

    public function testCompositeNodeKeysDoNotChangeAnotherProtocolsBinding(): void
    {
        Schema::create('v2_rate_node_policy', function (Blueprint $t) { $t->string('node_type'); $t->integer('node_id'); $t->string('mode'); });
        DB::table('v2_rate_node_policy')->insert([['node_type' => 'vless', 'node_id' => 1, 'mode' => 'off'], ['node_type' => 'trojan', 'node_id' => 1, 'mode' => 'global']]);
        $this->audited('RateController@applyBinding', ['nodes' => [['type' => 'vless', 'id' => 1]]], function () {
            SecurityAuditMutation::update(DB::table('v2_rate_node_policy')->where('node_type', 'vless')->where('node_id', 1), ['mode' => 'policy'], 'node_id');
            return response(['data' => true]);
        });
        $this->assertDatabaseHas('v2_rate_node_policy', ['node_type' => 'trojan', 'node_id' => 1, 'mode' => 'global']);
        $items = $this->items(); $this->assertCount(1, $items);
        $this->assertSame('vless:1', $items[0]['object']['identity']);
        $this->assertSame('指定策略', array_column($items[0]['fields'], null, 'field')['mode']['after_label']);
    }

    public function testNodeBatchIncludesUnchangedMembersAndPreviewDoesNotClaimExecution(): void
    {
        Schema::create('v2_server_vless', function (Blueprint $t) {
            $t->increments('id'); $t->string('name'); $t->decimal('rate', 8, 2); $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        DB::table('v2_server_vless')->insert([['id' => 1, 'name' => '香港 01', 'rate' => 1], ['id' => 2, 'name' => '香港 02', 'rate' => 2]]);
        $nodes = [['type' => 'vless', 'id' => 1], ['type' => 'vless', 'id' => 2]];
        $this->audited('Server\\ManageController@applyRate', ['nodes' => $nodes], function () use ($nodes) {
            return response(['data' => (new ServerBatchOperationService())->applyRate($nodes, '2')]);
        });
        $this->assertCount(1, $this->items());
        $batch = $this->items('business.batch'); $this->assertCount(1, $batch);
        $this->assertSame('unchanged', $batch[0]['operation']);
        $this->assertSame('vless:2', $batch[0]['object']['identity']);
        $this->assertSame(1, $this->finish()['details']['business']['result']['unchanged_count']);
        $this->audited('Server\\ManageController@previewRate', ['nodes' => $nodes], function () use ($nodes) {
            return response(['data' => (new ServerBatchOperationService())->previewRate($nodes, '3')]);
        });
        $this->assertDatabaseHas('v2_server_vless', ['id' => 1, 'rate' => 2]);
        $this->assertSame('preview', $this->finish()['details']['business']['operation']);
        $batch = $this->items('business.batch');
        $this->assertSame('preview', end($batch)['operation']);
    }

    public function testActualPlanForcedSyncIncludesEveryAffectedUser(): void
    {
        Schema::create('v2_plan', function (Blueprint $t) {
            $t->increments('id'); $t->string('name'); $t->integer('group_id'); $t->integer('transfer_enable');
            $t->integer('device_limit'); $t->integer('speed_limit'); $t->integer('show')->default(0);
            $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        DB::table('v2_plan')->insert(['id' => 1, 'name' => '标准套餐', 'group_id' => 1, 'transfer_enable' => 100, 'device_limit' => 3, 'speed_limit' => 100]);
        Schema::create('v2_server_group', function (Blueprint $t) { $t->increments('id'); $t->string('name'); });
        DB::table('v2_server_group')->insert([['id' => 1, 'name' => '普通节点'], ['id' => 2, 'name' => '高级节点']]);
        DB::table('v2_user')->where('id', 6)->update(['plan_id' => 1]);
        $this->postJson($this->base . '/plan/save', ['id' => 1, 'name' => '标准套餐', 'group_id' => 2, 'transfer_enable' => 200, 'device_limit' => 5, 'speed_limit' => 200, 'force_update' => 1], $this->headers())->assertOk();
        $this->assertDatabaseHas('v2_user', ['id' => 6, 'transfer_enable' => 214748364800, 'device_limit' => 5]);
        $users = array_values(array_filter($this->items(), function ($item) { return $item['object']['type'] === 'v2_user'; }));
        $this->assertCount(1, $users);
        $this->assertSame('200 GB', array_column($users[0]['fields'], null, 'field')['transfer_enable']['after_label']);
        $this->assertSame('高级节点（2）', array_column($users[0]['fields'], null, 'field')['group_id']['after_label']);
        $this->assertSame('v2_plan', $this->finish()['details']['business']['items'][0]['object']['type']);
    }

    public function testTextAndNestedSecretsKeepOnlySafeEvidence(): void
    {
        $this->audited('NoticeController@save', ['id' => 1, 'vendor_data' => 'new-unrecognized-private-value'], function () {
            SecurityAuditService::change('v2_notice', 1, 'updated', ['id' => 1, 'title' => '通知', 'content' => 'old-private-body'], ['id' => 1, 'title' => '新通知', 'content' => 'new-private-body']);
            SecurityAuditService::change('v2_external_node', 2, 'created', null, ['id' => 2, 'name' => '外部节点', 'payload' => json_encode(['raw_uri' => 'trojan://raw-secret@node.test:443'])]);
            SecurityAuditService::change('v2_user', 6, 'updated', ['vendor_data' => 'old-unrecognized-private-value'], ['vendor_data' => 'new-unrecognized-private-value']);
            return response(['data' => true]);
        });
        $payloads = DB::table('v2_admin_audit')->pluck('payload')->implode('');
        foreach (['old-private-body', 'new-private-body', 'raw-secret'] as $secret) $this->assertStringNotContainsString($secret, $payloads);
        $this->assertStringNotContainsString('unrecognized-private-value', $payloads);
        $fields = array_column($this->items()[0]['fields'], null, 'field');
        $this->assertSame(mb_strlen('new-private-body'), $fields['content']['after']['length']);
        $this->assertSame(hash('sha256', 'new-private-body'), $fields['content']['after']['sha256']);
    }

    public function testQueuedMailRetainsRecipientAndTitleWithoutItsBody(): void
    {
        Queue::fake();
        $this->audited('UserController@sendMail', [], function () {
            // Use the real payload builder while leaving delivery outside this test.
            $job = new SendEmailJob(['email' => 'recipient@example.test', 'subject' => '测试通知', 'template_name' => 'notify', 'template_value' => ['content' => 'mail-body-secret']]);
            $queue = new \Illuminate\Queue\SyncQueue();
            $method = new \ReflectionMethod($queue, 'createPayload'); $method->setAccessible(true);
            $payload = json_decode($method->invoke($queue, $job, 'send_email'), true);
            $this->assertSame('recipient@example.test', $payload['security_audit']['job_metadata']['email']);
            return response(['data' => true]);
        });
        $queued = json_decode(DB::table('v2_admin_audit')->where('event', 'job.queued')->first()->payload, true);
        $this->assertSame('测试通知', $queued['details']['business']['job']['metadata']['subject']);
        $this->assertStringNotContainsString('mail-body-secret', DB::table('v2_admin_audit')->pluck('payload')->implode(''));
    }

    public function testDetailPaginationPermissionsAndHistoricalHashRemainIntact(): void
    {
        $old = DB::table('v2_admin_audit')->where('id', 1)->first();
        $this->audited('UserController@update', ['id' => 6], function () { User::find(6)->update(['device_limit' => 5]); return response(['data' => true]); });
        $id = DB::table('v2_admin_audit')->where('event', 'request.finish')->max('id');
        $headers = $this->headers();
        $page = $this->getJson($this->base . '/security/audit/detail?id=' . $id . '&limit=1', $headers)->assertOk()->json();
        $this->assertCount(1, $page['data']); $this->assertTrue($page['has_more']);
        $next = $this->getJson($this->base . '/security/audit/detail?id=' . $id . '&after=' . $page['next_after'], $headers)->assertOk()->json();
        $this->assertCount(2, $next['data']); $this->assertFalse($next['has_more']);
        $this->assertSame($page['request_id'], $next['request_id']);
        $this->getJson($this->base . '/security/audit/detail?id=' . $id, $this->headers(2))->assertForbidden();
        $this->assertSame($old->payload, DB::table('v2_admin_audit')->where('id', 1)->value('payload'));
        $this->assertSame($old->hash, DB::table('v2_admin_audit')->where('id', 1)->value('hash'));
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testCaughtSavepointFailureRemovesOnlyRolledBackChanges(): void
    {
        $this->audited('UserController@update', ['id' => 6], function () {
            User::find(6)->update(['device_limit' => 4]);
            try {
                DB::transaction(function () {
                    User::find(6)->update(['email' => 'rolled-back@example.test']);
                    SecurityAuditService::effect('外部服务已接受请求', ['数量' => 1], 'success', true);
                    throw new \RuntimeException('nested failure');
                });
            } catch (\RuntimeException $error) {}
            User::find(6)->update(['balance' => 20000]);
            return response(['data' => true]);
        });
        $this->assertDatabaseHas('v2_user', ['id' => 6, 'email' => 'member@example.test', 'device_limit' => 4, 'balance' => 20000]);
        $this->assertCount(2, $this->items());
        $this->assertSame('外部服务已接受请求', $this->finish()['details']['business']['effects'][0]['label']);
        $this->assertDatabaseHas('v2_admin_audit', ['event' => 'business.effect']);
        $this->assertStringNotContainsString('rolled-back@example.test', DB::table('v2_admin_audit')->pluck('payload')->implode(''));
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testLargeNoOpBatchPreservesEveryObject(): void
    {
        for ($i = 100; $i < 1201; $i++) DB::table('v2_user')->insert(['id' => $i, 'email' => "same{$i}@example.test", 'plan_id' => 7]);
        $this->audited('UserController@update', [], function () {
            SecurityAuditMutation::update(User::where('plan_id', 7), ['device_limit' => 3]);
            return response(['data' => true]);
        });
        $this->assertCount(1101, $this->items('business.batch'));
        $this->assertSame(range(100, 1200), array_column(array_column($this->items('business.batch'), 'object'), 'id'));
        $this->assertSame('no_change', $this->finish()['details']['business']['state']);
        $this->assertSame(1101, $this->finish()['details']['business']['result']['unchanged_count']);
    }

    public function testQueueRetryPreservesIdentityAndDoesNotReportRolledBackWrites(): void
    {
        $request = Request::create('/worker'); $this->app->instance('request', $request);
        $origin = ['actor' => AdminAccessService::actor(User::find(1)), 'request_id' => str_repeat('c', 32),
            'action' => 'App\\Http\\Controllers\\V1\\Admin\\UserController@update', 'job_ref' => 'job-retry-123', 'batch_id' => str_repeat('c', 32), 'channel' => '异步任务'];
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn(['security_audit' => $origin]);
        $job->shouldReceive('resolveName')->andReturn('FixtureJob');
        $job->shouldReceive('getJobId')->andReturn('123');
        $job->shouldReceive('maxTries')->andReturn(3);
        $attempt = 1;
        $job->shouldReceive('attempts')->andReturnUsing(function () use (&$attempt) { return $attempt; });
        \Illuminate\Support\Facades\Event::dispatch(new \Illuminate\Queue\Events\JobProcessing('database', $job));
        try {
            DB::transaction(function () {
                User::find(6)->update(['email' => 'failed-job@example.test']);
                throw new \RuntimeException('queue-secret');
            });
        } catch (\RuntimeException $error) {
            \Illuminate\Support\Facades\Event::dispatch(new \Illuminate\Queue\Events\JobExceptionOccurred('database', $job, $error));
        }
        $attempt = 2;
        \Illuminate\Support\Facades\Event::dispatch(new \Illuminate\Queue\Events\JobProcessing('database', $job));
        DB::transaction(function () { User::find(6)->update(['device_limit' => 5]); });
        \Illuminate\Support\Facades\Event::dispatch(new \Illuminate\Queue\Events\JobProcessed('database', $job));
        $this->assertCount(1, $this->items('job.changes'));
        $payloads = DB::table('v2_admin_audit')->pluck('payload')->implode('');
        $this->assertStringNotContainsString('failed-job@example.test', $payloads);
        $this->assertStringNotContainsString('queue-secret', $payloads);
        $rows = $this->getJson($this->base . '/security/audit?view=operations&job_ref=job-retry-123', $this->headers())->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame([2, 1], array_column(array_column(array_column($rows, 'business'), 'job'), 'attempt'));
        $this->assertSame(['success', 'failure'], array_column($rows, 'result'));
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testMailCaughtTransportFailureStillRecordsFailedJob(): void
    {
        Schema::create('v2_mail_log', function (Blueprint $t) {
            $t->increments('id'); $t->string('email'); $t->string('subject'); $t->string('template_name');
            $t->text('error')->nullable(); $t->integer('created_at')->nullable(); $t->integer('updated_at')->nullable();
        });
        \Illuminate\Support\Facades\Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp-password-secret'));
        $this->audited('UserController@sendMail', [], function () {
            Queue::connection('sync')->push(new SendEmailJob(['email' => 'recipient@example.test', 'subject' => '通知', 'template_name' => 'notify', 'template_value' => ['content' => 'private body']]));
            return response(['data' => true]);
        });
        $row = DB::table('v2_admin_audit')->where('event', 'job.finish')->first();
        $business = json_decode($row->payload, true)['details']['business'];
        $this->assertSame('failure', $row->result);
        $this->assertSame('recipient@example.test', $business['job']['metadata']['email']);
        $this->assertNotSame('[redacted]', $business['job']['ref']);
        $this->assertSame('queued', $this->finish()['details']['business']['state']);
        $this->assertStringNotContainsString('smtp-password-secret', DB::table('v2_admin_audit')->pluck('payload')->implode(''));
    }

    public function testBatchDetailAndOriginalExportRetainIndependentRequests(): void
    {
        $batch = str_repeat('d', 32);
        foreach ([4, 5] as $limit) {
            $request = Request::create('/batch', 'POST', ['id' => 6]);
            $request->headers->set('X-Audit-Batch-ID', $batch);
            $this->app->instance('request', $request);
            SecurityAuditService::run($request, AdminAccessService::actor(User::find(1)), function () use ($limit) {
                User::find(6)->update(['device_limit' => $limit]); return response(['data' => true]);
            }, ['action' => 'App\\Http\\Controllers\\V1\\Admin\\UserController@update']);
        }
        $rows = $this->getJson($this->base . '/security/audit?view=operations&batch_id=' . $batch, $this->headers())->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $detail = $this->getJson($this->base . '/security/audit/detail?id=' . $rows[0]['id'] . '&scope=batch', $this->headers())->assertOk()->json('data');
        $this->assertCount(6, $detail);
        $this->assertCount(2, array_unique(array_column($detail, 'request_id')));
        $export = $this->postJson($this->base . '/security/audit/export?batch_id=' . $batch, [], $this->headers())->assertOk()->getContent();
        $exported = array_map(function ($line) { return json_decode($line, true); }, explode("\n", trim($export)));
        $this->assertCount(6, $exported);
        foreach ($exported as $record) {
            $this->assertSame(DB::table('v2_admin_audit')->where('id', $record['id'])->value('payload'), $record['payload']);
            $this->assertSame(DB::table('v2_admin_audit')->where('id', $record['id'])->value('hash'), $record['hash']);
        }
    }

    public function testRegisteredAdminRoutesHaveKnownModulesAndSpecificActions(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (!in_array('admin', $route->gatherMiddleware(), true)) continue;
            $action = $route->getActionName();
            $definition = SecurityAuditBusiness::definition($action, Request::create('/coverage', $route->methods()[0]));
            $this->assertNotSame('other', $definition['module'], $action);
            $this->assertStringNotContainsString('后台操作', $definition['action_label'], $action);
        }
        $this->assertSame('update', SecurityAuditBusiness::definition('App\\Http\\Controllers\\V1\\Admin\\ConfigController@save')['operation']);
        $this->assertSame('delete', SecurityAuditBusiness::definition('App\\Http\\Controllers\\V1\\Admin\\Server\\ManageController@deleteNodes')['operation']);
    }

    public function testAuditTriggersRejectUpdateAndDeleteOnBothDatabaseDrivers(): void
    {
        $original = DB::table('v2_admin_audit')->where('id', 1)->first();
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('v2_admin_audit')->where('id', 1);
                if ($operation === 'update') $query->update(['result' => 'forged']);
                else $query->delete();
                $this->fail('The audit trigger must reject ' . $operation);
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('append only', $error->getMessage());
            }
        }
        $this->assertSame($original->hash, DB::table('v2_admin_audit')->where('id', 1)->value('hash'));
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testLargeRelatedEffectSetIsStoredInBoundedRecords(): void
    {
        $this->audited('UserController@allDel', [], function () {
            for ($i = 1; $i <= 1101; $i++) SecurityAuditService::effect('关联日志清理', ['用户编号' => $i, '删除数量' => 2]);
            return response(['data' => true]);
        });
        $effects = [];
        foreach (DB::table('v2_admin_audit')->where('event', 'business.effect')->get() as $record) {
            $part = json_decode($record->payload, true)['details']['business']['effects'];
            $this->assertLessThanOrEqual(100, count($part));
            $effects = array_merge($effects, $part);
        }
        $this->assertCount(1101, $effects);
        $this->assertSame(range(1, 1101), array_column(array_column($effects, 'values'), '用户编号'));
        $this->assertSame(1101, $this->finish()['details']['business']['effects_total']);
        $this->assertCount(20, $this->finish()['details']['business']['effects']);
    }
}
