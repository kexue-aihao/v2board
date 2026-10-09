<?php

namespace Tests\Feature;

use App\Http\Controllers\V1\Admin\ConfigController;
use App\Http\Controllers\V1\Guest\TelegramController;
use App\Models\User;
use App\Services\SecurityAuditService;
use App\Services\WebmanRuntimeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Support\AdminSecurityFixture;
use Tests\TestCase;

class AdminConfigurationRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'logging.default' => 'null', 'admin_security.audit_key' => 'runtime-test-key']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id'); $table->string('email');
            $table->boolean('is_admin')->default(0); $table->boolean('is_staff')->default(0);
            $table->boolean('banned')->default(0);
        });
        DB::table('v2_user')->insert(['id' => 1, 'email' => 'admin@example.test', 'is_admin' => 1]);
        AdminSecurityFixture::install();
    }

    public function testRebuildingConfigurationKeepsTheActiveRequestAndAuditTransaction(): void
    {
        User::findOrFail(1);
        $application = $this->app;
        $connection = DB::connection();
        $events = Model::getEventDispatcher();
        $request = Request::create('/configuration-runtime', 'POST');
        $this->app->instance('request', $request);
        $actor = ['id' => 1, 'admin_role' => 'super', 'admin_version' => 1];

        $response = SecurityAuditService::run($request, $actor, function () use ($application, $connection, $events, $request) {
            $this->assertSame(1, $connection->transactionLevel());
            $this->assertSame(0, Artisan::call('config:cache'));
            $this->assertSame($application, app(), 'config:cache must restore the running application');
            $this->assertSame($application, Facade::getFacadeApplication());
            $this->assertSame($request, request());
            $this->assertSame($connection, DB::connection());
            $this->assertSame($connection, User::resolveConnection());
            $this->assertSame($events, Model::getEventDispatcher());
            return response(['data' => true]);
        }, ['action' => 'App\\Http\\Controllers\\V1\\Admin\\ConfigController@setTelegramWebhook']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertDatabaseHas('v2_admin_audit', ['event' => 'request.finish', 'result' => 'success', 'actor_id' => 1]);
        $this->assertTrue(SecurityAuditService::verify()['valid']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     * @dataProvider webhookSetupCases
     */
    public function testWebhookSetupPersistsTheNewTokenAndAcceptsTheNewSecretWithoutBreakingAudit(bool $cacheFails): void
    {
        $curl = Mockery::mock('overload:Curl\\Curl');
        $registeredSecret = null;
        $methods = [];
        $curl->shouldReceive('get')->once()->withArgs(function ($url) use (&$registeredSecret, &$methods) {
            $this->assertStringStartsWith('https://api.telegram.org/botnew-token/', $url);
            $methods[] = basename(parse_url($url, PHP_URL_PATH));
            if (strpos($url, '/setWebhook?') !== false) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame('https://admin.example.test/api/v1/guest/telegram/webhook', $query['url']);
                $this->assertSame(64, strlen($query['secret_token']));
                $registeredSecret = $query['secret_token'];
            }
            return true;
        })->andSet('response', (object)['ok' => true, 'result' => true]);
        $curl->shouldReceive('close')->once();
        $originalBase = $this->app->basePath();
        $testBase = sys_get_temp_dir() . '/v2board-webhook-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($testBase . '/config');
        File::ensureDirectoryExists($testBase . '/bootstrap/cache');
        File::put($testBase . '/config/app.php', '<?php return ' . var_export(config('app'), true) . ';');
        $previous = ['app_url' => 'https://frontend.example.test', 'telegram_bot_token' => 'old-token', 'telegram_webhook_secret' => 'old-secret'];
        File::put($testBase . '/config/v2board.php', '<?php return ' . var_export($previous, true) . ';');
        config(['v2board' => $previous]);
        $this->app->setBasePath($testBase);
        try {
            if ($cacheFails) Artisan::shouldReceive('call')->with('config:cache')->once()->andThrow(new \RuntimeException('cache write failed'));
            $request = Request::create('https://admin.example.test/config/setTelegramWebhook', 'POST', ['telegram_bot_token' => ' new-token ']);
            $this->app->instance('request', $request);
            $this->app['url']->setRequest($request);
            $actor = ['id' => 1, 'admin_role' => 'super', 'admin_version' => 1];
            try {
                $response = SecurityAuditService::run($request, $actor, function () use ($request) {
                    return (new ConfigController())->setTelegramWebhook($request);
                }, ['action' => 'App\\Http\\Controllers\\V1\\Admin\\ConfigController@setTelegramWebhook']);
                $this->assertFalse($cacheFails, 'Cache failure must not be reported as successful setup');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $this->assertTrue($cacheFails);
                $this->assertSame(500, $error->getStatusCode());
                $this->assertStringContainsString('配置缓存刷新失败', $error->getMessage());
                $this->assertSame('new-token', (require $testBase . '/config/v2board.php')['telegram_bot_token']);
                $this->assertSame(0, DB::connection()->transactionLevel());
                $this->assertDatabaseHas('v2_admin_audit', ['event' => 'request.finish', 'result' => 'failure', 'actor_id' => 1]);
                $this->assertTrue(SecurityAuditService::verify()['valid']);
                return;
            }
            $this->assertSame(['getMe', 'setMyCommands', 'setWebhook'], $methods);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['data' => true], json_decode($response->getContent(), true));
            $saved = require $testBase . '/config/v2board.php';
            $cached = require $this->app->getCachedConfigPath();
            $this->assertSame('new-token', $saved['telegram_bot_token']);
            $this->assertSame('https://admin.example.test/api/v1/guest/telegram/webhook', $saved['telegram_webhook_url']);
            $this->assertSame($registeredSecret, $saved['telegram_webhook_secret']);
            $this->assertSame($saved, $cached['v2board']);
            $this->assertSame($registeredSecret, config('v2board.telegram_webhook_secret'));
            $this->assertSame(0, DB::connection()->transactionLevel());
            $this->assertDatabaseHas('v2_admin_audit', ['event' => 'request.finish', 'result' => 'success', 'actor_id' => 1]);
            $this->assertTrue(SecurityAuditService::verify()['valid']);
            // An empty update verifies the secret without sending bot messages.
            $incoming = Request::create('/api/v1/guest/telegram/webhook', 'POST', [], [], [], ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $registeredSecret]);
            $this->app->instance('request', $incoming);
            $this->assertSame(200, (new TelegramController())->webhook($incoming)->getStatusCode());
        } finally {
            $this->app->setBasePath($originalBase);
            File::deleteDirectory($testBase);
        }
    }

    public function webhookSetupCases(): array
    {
        return ['cache rebuilt' => [false], 'cache write failed' => [true]];
    }

    public function testFpmIgnoresAStaleWebmanProcessId(): void
    {
        Cache::put('WEBMANPID', 12345);
        $this->assertFalse(WebmanRuntimeService::scheduleRestart());
        WebmanRuntimeService::afterResponseSent();
        $this->assertSame(12345, Cache::get('WEBMANPID'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWebmanDefersGracefulReloadAndUsesItsOwnMaster(): void
    {
        require __DIR__ . '/../Support/FakeWebmanPosix.php';
        define('isWEBMAN', true);
        $GLOBALS['webman_test_signals'] = [];
        Cache::put('WEBMANPID', 54321);
        $this->assertTrue(WebmanRuntimeService::scheduleRestart());
        $this->assertSame([], $GLOBALS['webman_test_signals'], 'No signal during the controller or audit transaction');
        WebmanRuntimeService::afterResponseSent();
        $this->assertSame([[12345, SIGUSR2]], $GLOBALS['webman_test_signals']);
        WebmanRuntimeService::afterResponseSent();
        $this->assertCount(1, $GLOBALS['webman_test_signals'], 'The next response must not repeat the reload');
    }

    public function testConfigurationReadFailurePreservesTheLiveApplicationAndConnection(): void
    {
        $connection = DB::connection();
        $events = Model::getEventDispatcher();
        $request = Request::create('/configuration-runtime', 'POST');
        $this->app->instance('request', $request);
        $originalBase = $this->app->basePath();
        $testBase = sys_get_temp_dir() . '/v2board-config-failure-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($testBase . '/config');
        File::ensureDirectoryExists($testBase . '/bootstrap/cache');
        File::put($testBase . '/config/app.php', '<?php return [];');
        File::put($testBase . '/config/broken.php', '<?php throw new \\RuntimeException("invalid configuration");');
        $this->app->setBasePath($testBase);
        try {
            try {
                Artisan::call('config:cache');
                $this->fail('Expected a configuration read error');
            } catch (\RuntimeException $error) {
                $this->assertSame('invalid configuration', $error->getMessage());
            }
            $this->assertSame($this->app, app());
            $this->assertSame($this->app, Facade::getFacadeApplication());
            $this->assertSame($request, request());
            $this->assertSame($connection, DB::connection());
            $this->assertSame($events, Model::getEventDispatcher());
        } finally {
            $this->app->setBasePath($originalBase);
            File::deleteDirectory($testBase);
        }
    }
}
