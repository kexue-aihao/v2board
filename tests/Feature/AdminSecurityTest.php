<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminAccessService;
use App\Services\AdminSecuritySchema;
use App\Services\AuthService;
use App\Services\SecurityAuditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Tests\Support\AuditedTestJob;
use Tests\Support\AdminSecurityFixture;
use Tests\TestCase;

class AdminSecurityTest extends TestCase
{
    private $base;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array',
            'v2board.site_status' => 'normal', 'logging.default' => 'null', 'admin_security.audit_key' => 'test-audit-key']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id'); $table->string('email'); $table->boolean('is_admin')->default(0);
            $table->boolean('is_staff')->default(0); $table->boolean('banned')->default(0);
            $table->string('password')->nullable(); $table->string('password_algo')->nullable(); $table->string('password_salt')->nullable();
            $table->string('token')->nullable(); $table->string('uuid')->nullable();
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        DB::table('v2_user')->insert(['id' => 1, 'email' => 'founder@example.test', 'is_admin' => 1]);
        AdminSecurityFixture::install();
        foreach (AdminAccessService::ASSIGNABLE as $index => $role) DB::table('v2_user')->insert([
            'id' => $index + 2, 'email' => $role . '@example.test', 'is_admin' => 1, 'admin_role' => $role, 'admin_version' => 1,
        ]);
        DB::table('v2_user')->insert(['id' => 6, 'email' => 'ordinary@example.test']);
        DB::table('v2_user')->insert(['id' => 7, 'email' => 'legacy@example.test', 'is_admin' => 1]);
        $this->base = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
    }

    private function headers(int $id): array
    {
        return ['Authorization' => (new AuthService(User::findOrFail($id)))->generateAuthData(Request::create('/'), true)['auth_data']];
    }

    public function testMenusAndPrivateAssetsFollowAllFiveRoles(): void
    {
        $expected = ['super' => '/dashboard', 'operations' => '/server/manage', 'finance' => '/order', 'support' => '/ticket', 'marketing' => '/plan'];
        foreach ($expected as $role => $landing) {
            $id = array_search($role, array_keys($expected), true) + 1;
            $headers = $this->headers($id);
            $bootstrap = $this->getJson($this->base . '/security/bootstrap', $headers)->assertOk()->assertJsonPath('data.role', $role)->assertJsonPath('data.landing', $landing);
            $menus = array_column($bootstrap->json('data.menus'), 'href');
            $this->assertNotContains('/security/administrators', $menus);
            if ($role === 'super') {
                $this->assertSame(['type' => 'item', 'title' => '仪表盘', 'href' => '/dashboard'], $bootstrap->json('data.menus')[0]);
            }
            if ($role !== 'super') {
                $this->assertNotContains('/security/audit', $menus);
                $this->assertNotContains('/user', $menus);
            }
            $asset = $this->get($this->base . '/security/asset?role=super', $headers)->assertOk();
            $this->assertStringContainsString('no-store', $asset->headers->get('Cache-Control'));
            $this->assertSame(file_get_contents(resource_path('admin/build/' . $role . '.js')), $asset->getContent());
        }
        $this->get($this->base . '/security/asset')->assertForbidden();
    }

    public function testDirectInterfacesCannotBypassRolesOrLegacyStaffFlags(): void
    {
        $forbidden = [
            2 => ['/order/fetch', '/plan/fetch', '/ticket/fetch', '/security/audit', '/user/fetch'],
            3 => ['/config/fetch', '/server/manage/getNodes', '/plan/fetch', '/ticket/fetch', '/security/audit'],
            4 => ['/config/fetch', '/order/fetch', '/user/fetch', '/security/audit'],
            5 => ['/order/fetch', '/config/fetch', '/server/manage/getNodes', '/ticket/fetch', '/security/audit'],
        ];
        foreach ($forbidden as $id => $paths) {
            $headers = $this->headers($id);
            foreach ($paths as $path) $this->getJson($this->base . $path, $headers)->assertForbidden();
        }
        $this->postJson($this->base . '/ticket/close', ['id' => 1], $this->headers(4))->assertForbidden();
        $this->postJson('/api/v1/staff/user/update', ['id' => 1, 'password' => 'attacker-password'], $this->headers(4))->assertForbidden();
        $this->postJson('/api/v1/staff/notice/save', [], $this->headers(4))->assertForbidden();
        $this->getJson($this->base . '/security/bootstrap', $this->headers(6))->assertForbidden();
        $this->getJson($this->base . '/security/bootstrap', $this->headers(7))->assertForbidden();
    }

    public function testRoleAssignmentsAreSuperOnlyAndRevokeAlreadyIssuedSessions(): void
    {
        $old = $this->headers(2); $super = $this->headers(1);
        $this->postJson($this->base . '/security/administrators/role', ['user_id' => 2, 'role' => 'finance'], $this->headers(3))->assertForbidden();
        $this->postJson($this->base . '/security/administrators/role', ['user_id' => 2, 'role' => 'finance'], $super)->assertOk();
        $this->getJson($this->base . '/security/bootstrap', $old)->assertForbidden();
        $this->getJson($this->base . '/security/bootstrap', $this->headers(2))->assertOk()->assertJsonPath('data.role', 'finance');
        $this->postJson($this->base . '/security/administrators/role', ['user_id' => 2, 'role' => null], $super)->assertOk();
        $this->getJson($this->base . '/security/bootstrap', $this->headers(2))->assertForbidden();
        $this->assertDatabaseHas('v2_admin_audit', ['event' => 'administrator.role', 'actor_id' => 1]);
    }

    public function testCannotCreateDemoteOrDeleteSuperThroughApi(): void
    {
        $super = $this->headers(1);
        foreach ([['user_id' => 1, 'role' => 'finance'], ['user_id' => 2, 'role' => 'super']] as $data) {
            $this->postJson($this->base . '/security/administrators/role', $data, $super)->assertStatus(422);
        }
        $this->postJson($this->base . '/user/update', ['id' => 2, 'is_admin' => 0], $super)->assertStatus(422);
        $this->postJson($this->base . '/user/delUser', ['id' => 1], $super)->assertForbidden();
        $this->postJson($this->base . '/user/update', ['id' => 1, 'banned' => 1], $super)->assertForbidden();
        $this->postJson($this->base . '/config/save', ['admin_2fa_force_enable' => 0], $this->headers(2))->assertForbidden();
        $this->assertSame('super', AdminAccessService::role(User::find(1)));
    }

    public function testRepeatingUpgradeDoesNotReassignRolesOrResetAudit(): void
    {
        $before = DB::table('v2_admin_audit')->count();
        DB::table('v2_user')->where('id', 2)->update(['admin_role' => 'finance', 'admin_version' => 9]);
        AdminSecuritySchema::apply();
        AdminSecuritySchema::apply();
        $this->assertDatabaseHas('v2_user', ['id' => 2, 'admin_role' => 'finance', 'admin_version' => 9]);
        $this->assertSame($before, DB::table('v2_admin_audit')->count());
    }

    public function testAuditCannotBeDeletedOrUpdatedEvenWithQueryBuilder(): void
    {
        foreach (['delete', 'update'] as $method) {
            try {
                $query = DB::table('v2_admin_audit')->where('id', 1);
                $method === 'delete' ? $query->delete() : $query->update(['event' => 'forged']);
                $this->fail('Append-only database trigger did not reject ' . $method);
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('append only', $error->getMessage());
            }
        }
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testAuditCapturesChangesAndFailureWithoutSecrets(): void
    {
        $request = Request::create('/audit-test', 'POST', ['password' => 'never-record-me']);
        $this->app->instance('request', $request);
        SecurityAuditService::run($request, AdminAccessService::actor(User::find(1)), function () {
            User::find(6)->update(['email' => 'changed@example.test']);
            return response(['data' => true]);
        });
        try {
            SecurityAuditService::run($request, AdminAccessService::actor(User::find(1)), function () {
                User::find(6)->update(['email' => 'rolled-back@example.test']);
                throw new \RuntimeException('never-record-me');
            });
        } catch (\RuntimeException $error) {}
        $this->assertDatabaseHas('v2_user', ['id' => 6, 'email' => 'changed@example.test']);
        $payload = DB::table('v2_admin_audit')->pluck('payload')->implode('');
        $this->assertStringContainsString('ordinary@example.test', $payload);
        $this->assertStringContainsString('changed@example.test', $payload);
        $this->assertStringNotContainsString('never-record-me', $payload);
        $this->assertDatabaseHas('v2_admin_audit', ['event' => 'request.finish', 'result' => 'failure']);
        $this->assertNull($request->attributes->get('security_audit_context'));
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testAuditUnavailablePreventsBusinessWrite(): void
    {
        DB::table('v2_admin_audit_head')->delete();
        $ran = false;
        try {
            SecurityAuditService::run(Request::create('/audit-test', 'POST'), null, function () use (&$ran) { $ran = true; });
            $this->fail('Missing audit head must fail closed');
        } catch (\RuntimeException $error) { $this->assertFalse($ran); }
    }

    public function testAuditVerificationDetectsMissingTailAndChangedIndexes(): void
    {
        SecurityAuditService::append('test', 'success');
        DB::unprepared('DROP TRIGGER v2_admin_audit_no_delete');
        DB::table('v2_admin_audit')->where('id', 2)->delete();
        $this->assertFalse(SecurityAuditService::verify()['valid']);
    }

    public function testUnknownActionsAreDeniedAndSelectionDependenciesAreNarrow(): void
    {
        foreach (AdminAccessService::ASSIGNABLE as $role) {
            $this->assertFalse(AdminAccessService::allows($role, 'V1\\Admin\\OrderController@newUnreviewedMethod'));
        }
        $this->assertTrue(AdminAccessService::allows('finance', 'V1\\Admin\\SecurityController@planOptions'));
        $this->assertFalse(AdminAccessService::allows('finance', 'V1\\Admin\\PlanController@fetch'));
        $this->assertTrue(AdminAccessService::allows('marketing', 'V1\\Admin\\SecurityController@groupOptions'));
        $this->assertFalse(AdminAccessService::allows('marketing', 'V1\\Admin\\Server\\GroupController@fetch'));
        $this->assertFalse(AdminAccessService::allows('support', 'V1\\Admin\\TicketController@close'));
    }

    public function testCompletionAuditFailureRollsBackBusinessChanges(): void
    {
        $request = Request::create('/audit-test', 'POST');
        $this->app->instance('request', $request);
        try {
            SecurityAuditService::run($request, AdminAccessService::actor(User::find(1)), function () {
                User::find(6)->update(['email' => 'must-rollback@example.test']);
                // Fail the completion write after the business operation. The
                // test fault itself is transactional, so failure can be logged.
                DB::table('v2_admin_audit_head')->delete();
                return response(['data' => true]);
            });
            $this->fail('Completion audit must fail closed');
        } catch (\RuntimeException $error) {
            $this->assertDatabaseHas('v2_user', ['id' => 6, 'email' => 'ordinary@example.test']);
            $this->assertDatabaseHas('v2_admin_audit', ['event' => 'request.finish', 'result' => 'failure']);
            $this->assertNull($request->attributes->get('security_audit_context'));
            $this->assertTrue(SecurityAuditService::verify()['valid']);
        }
    }

    public function testNestedSyncJobsPreserveActorAndOuterChanges(): void
    {
        $request = Request::create('/audit-jobs', 'POST');
        $this->app->instance('request', $request);
        SecurityAuditService::run($request, AdminAccessService::actor(User::find(1)), function () {
            Queue::connection('sync')->push(new AuditedTestJob(true));
            User::find(6)->update(['email' => 'http@example.test']);
            return response(['data' => true]);
        });
        $this->assertSame(2, DB::table('v2_admin_audit')->where('event', 'job.finish')->where('actor_id', 1)->count());
        $this->assertSame(2, DB::table('v2_admin_audit')->where('event', 'job.changes')->count());
        $this->assertSame(1, DB::table('v2_admin_audit')->where('event', 'business.changes')->count());
        $changes = DB::table('v2_admin_audit')->whereIn('event', ['job.changes', 'business.changes'])->pluck('payload')->implode('');
        foreach (['child@example.test', 'parent@example.test', 'http@example.test'] as $email) $this->assertStringContainsString($email, $changes);
        $this->assertNull($request->attributes->get('security_audit_queue_stack'));
        $this->assertNull($request->attributes->get('security_audit_context'));
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testFailedJobRestoresContextAndDoesNotLeakExceptionSecrets(): void
    {
        $request = Request::create('/audit-jobs', 'POST');
        $this->app->instance('request', $request);
        $actor = AdminAccessService::actor(User::find(1));
        $original = ['actor' => $actor, 'request_id' => str_repeat('a', 32), 'action' => 'App\\Http\\Controllers\\V1\\Admin\\UserController@sendMail', 'changes' => [], 'statements' => []];
        $request->attributes->set('security_audit_context', $original);
        try {
            Queue::connection('sync')->push(new AuditedTestJob(false, true));
            $this->fail('Expected job failure');
        } catch (\RuntimeException $error) {
            $this->assertSame($original, $request->attributes->get('security_audit_context'));
        }
        $this->assertDatabaseHas('v2_admin_audit', ['event' => 'job.finish', 'result' => 'failure', 'actor_id' => 1]);
        $queued = DB::table('v2_admin_audit')->where('event', 'job.queued')->first();
        $finished = DB::table('v2_admin_audit')->where('event', 'job.finish')->first();
        $this->assertSame('提交后台任务：向用户发送邮件', json_decode($queued->payload, true)['description']);
        $this->assertSame('完成后台任务：向用户发送邮件', json_decode($finished->payload, true)['description']);
        $this->assertStringNotContainsString('secret-job-failure', DB::table('v2_admin_audit')->pluck('payload')->implode(''));
        $this->assertNull($request->attributes->get('security_audit_queue_stack'));
    }

    public function testQueuedJobCannotRunAfterRoleRevocation(): void
    {
        $request = Request::create('/audit-jobs');
        $this->app->instance('request', $request);
        $origin = ['actor' => AdminAccessService::actor(User::find(2)), 'request_id' => str_repeat('b', 32)];
        DB::table('v2_user')->where('id', 2)->increment('admin_version');
        $job = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn(['security_audit' => $origin]);
        $job->shouldReceive('resolveName')->andReturn(AuditedTestJob::class);
        $job->shouldReceive('getJobId')->andReturn('revoked-job');
        try {
            Event::dispatch(new JobProcessing('database', $job));
            $this->fail('Revoked actor must be rejected before execution');
        } catch (\Illuminate\Auth\Access\AuthorizationException $error) {
            Event::dispatch(new JobExceptionOccurred('database', $job, $error));
        }
        $this->assertDatabaseHas('v2_admin_audit', ['event' => 'job.denied', 'actor_id' => 2]);
        $this->assertNull($request->attributes->get('security_audit_context'));
        $this->assertNull($request->attributes->get('security_audit_queue_stack'));
    }

    public function testAdminLoginFailuresAreAuditedAtBothEntrypoints(): void
    {
        User::find(1)->update(['password' => password_hash('correct-password', PASSWORD_BCRYPT)]);
        foreach ([$this->base . '/passport/auth/login', '/api/v1/passport/auth/login'] as $url) {
            $before = DB::table('v2_admin_audit')->where('event', 'request.finish')->where('result', 'failure')->count();
            $this->postJson($url, ['email' => 'founder@example.test', 'password' => 'do-not-log-this-password'])->assertStatus(401);
            $this->assertSame($before + 1, DB::table('v2_admin_audit')->where('event', 'request.finish')->where('result', 'failure')->count());
        }
        $payload = DB::table('v2_admin_audit')->pluck('payload')->implode('');
        $this->assertStringContainsString('founder@example.test', $payload);
        $this->assertStringNotContainsString('do-not-log-this-password', $payload);
        $count = DB::table('v2_admin_audit')->count();
        $this->postJson('/api/v1/passport/auth/login', ['email' => 'not-registered@example.test', 'password' => 'wrong-password'])->assertStatus(401);
        $this->assertSame($count, DB::table('v2_admin_audit')->count());
    }

    public function testUserPortalSecurityActionIsAuditedForAdministrator(): void
    {
        $headers = $this->headers(2);
        $this->postJson('/api/v1/user/removeActiveSession', ['session_id' => 'already-removed'], $headers)->assertOk();
        $this->assertDatabaseHas('v2_admin_audit', ['actor_id' => 2, 'event' => 'request.finish', 'result' => 'success']);
        $this->assertStringContainsString('UserController@removeActiveSession', DB::table('v2_admin_audit')->pluck('payload')->implode(''));
    }

    public function testArchiveWritesPrivateEvidenceAndRetainsOriginalRows(): void
    {
        config(['admin_security.archive_disk' => 'audit-test']);
        Storage::fake('audit-test');
        $this->beforeApplicationDestroyed(function () { Storage::disk('audit-test')->deleteDirectory('security-audit'); });
        SecurityAuditService::append('archive.fixture', 'success');
        $original = DB::table('v2_admin_audit')->orderBy('id')->get()->toJson();
        $last = DB::table('v2_admin_audit')->max('id');
        $this->artisan('security:audit-archive', ['--after' => 0, '--limit' => 100])->assertExitCode(0);
        $files = Storage::disk('audit-test')->allFiles();
        $this->assertCount(1, $files);
        $this->assertSame('private', Storage::disk('audit-test')->getVisibility($files[0]));
        $lines = explode("\n", trim(Storage::disk('audit-test')->get($files[0])));
        $this->assertCount($last + 1, $lines);
        $this->assertSame($last, json_decode($lines[0], true)['last']);
        $this->assertSame($original, DB::table('v2_admin_audit')->where('id', '<=', $last)->orderBy('id')->get()->toJson());
        $this->assertTrue(SecurityAuditService::verify()['valid']);
        $this->artisan('security:audit-archive', ['--after' => -1])->assertExitCode(1);
    }

    public function testFinanceOrderDetailOnlyExposesRequiredAccountIdentity(): void
    {
        Schema::create('v2_order', function (Blueprint $table) {
            $table->increments('id'); $table->integer('user_id'); $table->integer('invite_user_id')->nullable();
            $table->string('trade_no'); $table->text('surplus_order_ids')->nullable();
        });
        Schema::create('v2_commission_log', function (Blueprint $table) { $table->increments('id'); $table->string('trade_no'); });
        Schema::create('v2_payment_attempt', function (Blueprint $table) { $table->increments('id'); $table->integer('order_id'); });
        DB::table('v2_order')->insert(['id' => 1, 'user_id' => 6, 'invite_user_id' => 2, 'trade_no' => 'test-trade']);
        $data = $this->postJson($this->base . '/order/detail', ['id' => 1], $this->headers(3))->assertOk()->json('data');
        $this->assertSame(['id' => 6, 'email' => 'ordinary@example.test'], $data['user']);
        $this->assertSame(['id' => 2, 'email' => 'operations@example.test'], $data['invite_user']);
    }

    public function testOperationsCannotReadOrOverwriteExecutableThemeFields(): void
    {
        foreach (['default', 'ez', 'signature'] as $theme) config(['theme.' . $theme => ['theme_color' => 'default', 'custom_html' => '<script>private-test</script>']]);
        $headers = $this->headers(2);
        $themes = $this->getJson($this->base . '/theme/getThemes', $headers)->assertOk()->json('data.themes');
        $this->assertNotContains('custom_html', array_column($themes['default']['configs'], 'field_name'));
        $values = $this->postJson($this->base . '/theme/getThemeConfig', ['name' => 'default'], $headers)->assertOk()->json('data');
        $this->assertSame('default', $values['theme_color']);
        $this->assertArrayNotHasKey('custom_html', $values);
        $this->postJson($this->base . '/theme/saveThemeConfig', ['name' => 'default', 'config' => base64_encode(json_encode(['custom_html' => '<script>attack</script>']))], $headers)->assertForbidden();
    }

    public function testRatePreviewsSkipSnapshotsAndBindingsOnlyCaptureSelectedNodes(): void
    {
        Schema::create('v2_rate_node_policy', function (Blueprint $table) { $table->string('node_type'); $table->integer('node_id'); $table->string('mode'); });
        DB::table('v2_rate_node_policy')->insert([['node_type' => 'vless', 'node_id' => 1, 'mode' => 'off'], ['node_type' => 'vless', 'node_id' => 2, 'mode' => 'global']]);
        $request = Request::create('/rate-test', 'POST', ['nodes' => [['type' => 'vless', 'id' => 1]]]);
        $route = new \Illuminate\Routing\Route('POST', '/rate-test', ['uses' => 'App\\Http\\Controllers\\V1\\Admin\\RateController@previewBinding', 'controller' => 'App\\Http\\Controllers\\V1\\Admin\\RateController@previewBinding']);
        $request->setRouteResolver(function () use ($route) { return $route; });
        $this->assertSame([], \App\Services\SecurityAuditSnapshot::capture($request));
        $route->setAction(['uses' => 'App\\Http\\Controllers\\V1\\Admin\\RateController@applyBinding', 'controller' => 'App\\Http\\Controllers\\V1\\Admin\\RateController@applyBinding']);
        $snapshot = \App\Services\SecurityAuditSnapshot::capture($request);
        $this->assertCount(1, $snapshot['v2_rate_node_policy']);
        $this->assertSame(1, $snapshot['v2_rate_node_policy']['vless:1']['node_id']);
    }

    public function testOrdinaryUserEditsStillSaveWithoutLegacyPrivilegeFields(): void
    {
        Schema::table('v2_user', function (Blueprint $table) { $table->integer('group_id')->nullable(); $table->integer('invite_user_id')->nullable(); });
        $super = $this->headers(1);
        // Legacy clients may echo unchanged flags; the middleware removes them.
        $this->postJson($this->base . '/user/update', ['id' => 6, 'email' => 'updated@example.test', 'banned' => 0, 'is_admin' => 0, 'is_staff' => 0], $super)->assertOk();
        $this->postJson($this->base . '/user/update', ['id' => 6, 'email' => 'updated-again@example.test', 'banned' => 0], $super)->assertOk();
        $this->assertDatabaseHas('v2_user', ['id' => 6, 'email' => 'updated-again@example.test', 'is_admin' => 0]);
    }

    private function prepareUserEdit(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->integer('group_id')->nullable(); $table->integer('invite_user_id')->nullable();
        });
    }

    public function testUserEditorAssignsAllRolesAndRevokesExistingSessions(): void
    {
        $this->prepareUserEdit();
        $super = $this->headers(1);
        foreach (array_merge(AdminAccessService::ASSIGNABLE, [null]) as $index => $role) {
            $old = $this->headers(6);
            $this->postJson($this->base . '/user/update', ['id' => 6, 'email' => 'edited@example.test', 'banned' => 0, 'admin_role' => $role], $super)->assertOk();
            $this->assertDatabaseHas('v2_user', ['id' => 6, 'admin_role' => $role, 'is_admin' => $role ? 1 : 0, 'is_staff' => 0, 'admin_version' => $index + 1]);
            $this->assertFalse(AuthService::decryptAuthData($old['Authorization']));
        }
        $record = DB::table('v2_admin_audit')->where('event', 'administrator.role')->orderByDesc('id')->first();
        $payload = json_decode($record->payload, true);
        $this->assertSame('修改管理员身份：运营管理员 → 普通用户（ID 6）', $payload['description']);
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testSavingAnUnchangedRoleKeepsItsVersionAndSession(): void
    {
        $this->prepareUserEdit();
        $old = $this->headers(2);
        $this->postJson($this->base . '/user/update', ['id' => 2, 'email' => 'edited@example.test', 'banned' => 0, 'admin_role' => 'operations'], $this->headers(1))->assertOk();
        $this->assertDatabaseHas('v2_user', ['id' => 2, 'admin_role' => 'operations', 'admin_version' => 1]);
        $this->getJson($this->base . '/security/bootstrap', $old)->assertOk();
        $this->assertSame(0, DB::table('v2_admin_audit')->where('event', 'administrator.role')->count());
    }

    public function testUserEditorRejectsPrivilegeEscalationAndProtectsTheFounder(): void
    {
        $this->prepareUserEdit();
        $super = $this->headers(1);
        $params = ['id' => 6, 'email' => 'ordinary@example.test', 'banned' => 0, 'admin_role' => 'finance'];
        foreach ([2, 3, 4, 5, 6] as $id) $this->postJson($this->base . '/user/update', $params, $this->headers($id))->assertForbidden();
        $this->postJson($this->base . '/user/update', array_merge($params, ['admin_role' => 'super']), $super)->assertStatus(422);
        $this->postJson($this->base . '/user/update', array_merge($params, ['banned' => 1]), $super)->assertStatus(422);
        $this->postJson($this->base . '/user/update', array_merge($params, ['id' => 1, 'email' => 'founder@example.test']), $super)->assertForbidden();
        $this->assertDatabaseHas('v2_user', ['id' => 6, 'is_admin' => 0, 'banned' => 0, 'admin_role' => null]);
        $this->assertDatabaseHas('v2_user', ['id' => 1, 'is_admin' => 1, 'admin_role' => null]);
    }

    public function testFailedUserEditLeavesRoleAndSessionUntouched(): void
    {
        $this->prepareUserEdit();
        $old = $this->headers(2);
        $super = $this->headers(1);
        $this->postJson($this->base . '/user/update', ['id' => 2, 'email' => 'invalid', 'banned' => 0, 'admin_role' => 'finance'], $super)->assertStatus(422);
        Event::listen('eloquent.updated: ' . User::class, function ($user) {
            if ((int)$user->id === 2 && $user->admin_role === 'finance') DB::table('v2_admin_audit_head')->delete();
        });
        $this->postJson($this->base . '/user/update', ['id' => 2, 'email' => 'edited@example.test', 'banned' => 0, 'admin_role' => 'finance'], $super)->assertStatus(500);
        $this->assertDatabaseHas('v2_user', ['id' => 2, 'email' => 'operations@example.test', 'admin_role' => 'operations', 'admin_version' => 1]);
        $this->getJson($this->base . '/security/bootstrap', $old)->assertOk();
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testChineseActionsAreStoredSignedAndSearchable(): void
    {
        $this->prepareUserEdit();
        $super = $this->headers(1);
        $this->postJson($this->base . '/user/update', ['id' => 6, 'email' => 'edited@example.test', 'banned' => 0, 'admin_role' => 'support'], $super)->assertOk();
        $records = $this->getJson($this->base . '/security/audit?' . http_build_query(['keyword' => '修改用户资料', 'event' => 'request.finish']), $super)->assertOk()->json('data');
        $this->assertCount(1, $records);
        $this->assertSame('修改用户资料（ID 6）', $records[0]['description']);
        $this->assertSame('超级管理员', $records[0]['role_label']);
        $this->assertSame('成功', $records[0]['result_label']);
        $this->assertSame($records[0]['description'], json_decode($records[0]['payload'], true)['description']);
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testBanningAndRevokingIdentityOnlyRemovesSessionsAfterSuccessfulSave(): void
    {
        $this->prepareUserEdit();
        $old = $this->headers(2);
        $super = $this->headers(1);
        $fail = true;
        Event::listen('eloquent.updated: ' . User::class, function ($user) use (&$fail) {
            if ($fail && (int)$user->id === 2 && !$user->is_admin) DB::table('v2_admin_audit_head')->delete();
        });
        $params = ['id' => 2, 'email' => 'edited@example.test', 'banned' => 1, 'admin_role' => null];
        $this->postJson($this->base . '/user/update', $params, $super)->assertStatus(500);
        $this->assertDatabaseHas('v2_user', ['id' => 2, 'email' => 'operations@example.test', 'banned' => 0, 'admin_role' => 'operations']);
        $this->getJson($this->base . '/security/bootstrap', $old)->assertOk();
        $fail = false;
        $this->postJson($this->base . '/user/update', $params, $super)->assertOk();
        $this->assertDatabaseHas('v2_user', ['id' => 2, 'banned' => 1, 'admin_role' => null, 'is_admin' => 0]);
        $this->assertFalse(AuthService::decryptAuthData($old['Authorization']));
        $record = DB::table('v2_admin_audit')->where('event', 'administrator.role')->orderByDesc('id')->first();
        $this->assertSame('修改管理员身份：运维管理员 → 普通用户（ID 2）', json_decode($record->payload, true)['description']);
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testLegacyAuditActionsDisplayInChineseWithoutRewritingTheChain(): void
    {
        $head = DB::table('v2_admin_audit_head')->where('id', 1)->first();
        $sequence = $head->sequence + 1;
        $payload = ['version' => 1, 'sequence' => $sequence, 'request_id' => str_repeat('a', 32), 'actor_id' => 1,
            'role' => 'super', 'event' => 'request.finish', 'result' => 'success', 'time' => time(),
            'details' => ['action' => 'App\\Http\\Controllers\\V1\\Admin\\UserController@update', 'input' => ['id' => 6]]];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = hash_hmac('sha256', $head->hash . "\n" . $json, config('admin_security.audit_key'));
        DB::table('v2_admin_audit')->insert(['id' => $sequence, 'request_id' => $payload['request_id'], 'actor_id' => 1,
            'role' => 'super', 'event' => 'request.finish', 'result' => 'success', 'created_at' => $payload['time'],
            'payload' => $json, 'previous_hash' => $head->hash, 'hash' => $hash]);
        DB::table('v2_admin_audit_head')->where('id', 1)->update(['sequence' => $sequence, 'hash' => $hash]);
        $records = $this->getJson($this->base . '/security/audit?' . http_build_query(['keyword' => '修改用户资料']), $this->headers(1))->assertOk()->json('data');
        $this->assertCount(1, $records);
        $this->assertSame('修改用户资料（ID 6）', $records[0]['description']);
        $this->assertSame($json, DB::table('v2_admin_audit')->where('id', $sequence)->value('payload'));
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testOperationsCannotChangeRewardRulesThroughLegacyConfigEndpoint(): void
    {
        $headers = $this->headers(2);
        $this->postJson($this->base . '/config/save', ['reward_enable' => 0], $headers)->assertForbidden();
        $data = $this->getJson($this->base . '/config/fetch?key=rewards', $headers)->assertOk()->json('data');
        $this->assertArrayNotHasKey('rewards', $data);
    }

    public function testTelegramRuleServiceRequiresTheMarketingRoleAndAuditsChanges(): void
    {
        $service = new \App\Services\TrafficRewardService();
        foreach ([2, 3, 4, 6, 7] as $id) {
            try {
                $service->saveGameRuleForAdministrator(User::find($id), 'dice', 12, 2);
                $this->fail('Unauthorized rule change');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('仅超级管理员或运营管理员', $error->getMessage());
            }
        }
        \Illuminate\Support\Facades\File::shouldReceive('put')->once()->withArgs(function ($path, $content, $flags) {
            return $path === base_path('config/v2board.php') && strpos($content, '12.00') !== false && $flags === LOCK_EX;
        })->andReturn(100);
        \Illuminate\Support\Facades\Artisan::shouldReceive('call')->with('config:cache')->once()->andReturn(0);
        $result = $service->saveGameRuleForAdministrator(User::find(5), 'dice', 12, 2);
        $this->assertSame('12.00', $result['dice']['win_probability']);
        $this->assertDatabaseHas('v2_admin_audit', ['actor_id' => 5, 'event' => 'reward.rules.change', 'result' => 'success']);
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    public function testNewRatePolicySnapshotDoesNotAttributeConcurrentRowsToTheActor(): void
    {
        Schema::create('v2_rate_policy', function (Blueprint $table) { $table->increments('id'); $table->string('name'); });
        DB::table('v2_rate_policy')->insert(['id' => 1, 'name' => 'existing']);
        $request = Request::create('/rate-test', 'POST');
        $action = 'App\\Http\\Controllers\\V1\\Admin\\RateController@savePolicy';
        $route = new \Illuminate\Routing\Route('POST', '/rate-test', ['uses' => $action, 'controller' => $action]);
        $request->setRouteResolver(function () use ($route) { return $route; });
        $this->assertSame([], \App\Services\SecurityAuditSnapshot::capture($request));
        DB::table('v2_rate_policy')->insert([['id' => 2, 'name' => 'this-request'], ['id' => 3, 'name' => 'concurrent-request']]);
        $after = \App\Services\SecurityAuditSnapshot::capture($request, response(['data' => ['id' => 2]]));
        $this->assertCount(1, $after['v2_rate_policy']);
        $this->assertSame('this-request', $after['v2_rate_policy'][2]['name']);
    }
}
