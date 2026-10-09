<?php

namespace Tests\Feature;

use App\Services\TelegramService;
use App\Services\TelegramWebhookService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class TelegramWebhookUpgradeTest extends TestCase
{
    private $originalBase;
    private $temporaryBase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalBase = $this->app->basePath();
        $this->temporaryBase = sys_get_temp_dir() . '/v2board-webhook-upgrade-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->temporaryBase . '/config');
        File::ensureDirectoryExists($this->temporaryBase . '/bootstrap/cache');
        File::put($this->temporaryBase . '/config/app.php', '<?php return ' . var_export(config('app'), true) . ';');
        $this->app->setBasePath($this->temporaryBase);
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBase);
        File::deleteDirectory($this->temporaryBase);
        parent::tearDown();
    }

    /** @dataProvider callbackCases */
    public function testUpgradeKeepsTheActualCallbackAndRebuildsTheSameSecret(string $registered, ?string $saved, string $expected, string $previousSecret): void
    {
        $config = ['app_url' => 'https://frontend.example.test', 'telegram_bot_token' => 'test-token',
            'telegram_webhook_url' => $saved, 'telegram_webhook_secret' => $previousSecret, 'app_name' => 'untouched'];
        config(['v2board' => $config]);
        File::put($this->temporaryBase . '/config/v2board.php', '<?php return ' . var_export($config, true) . ';');
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('getWebhookInfo')->once()->andReturn((object)['result' => (object)['url' => $registered]]);
        $telegram->shouldReceive('setWebhook')->once()->withArgs(function ($url, $extra) use ($expected, $previousSecret) {
            $this->assertSame($expected, $url);
            $disk = require $this->temporaryBase . '/config/v2board.php';
            $cached = require $this->app->getCachedConfigPath();
            $this->assertSame($disk, $cached['v2board'], 'workers must load the secret registered at Telegram');
            $this->assertSame($expected, $disk['telegram_webhook_url']);
            $this->assertSame('untouched', $disk['app_name']);
            $this->assertSame($disk['telegram_webhook_secret'], $extra['secret_token']);
            if ($previousSecret !== '') $this->assertSame($previousSecret, $extra['secret_token']);
            else $this->assertSame(64, strlen($extra['secret_token']));
            $this->assertArrayNotHasKey('drop_pending_updates', $extra);
            return true;
        })->andReturn((object)['ok' => true]);
        $this->assertSame($expected, (new TelegramWebhookService())->refresh($telegram));
    }

    public function callbackCases(): array
    {
        $backend = 'https://backend.example.test/api/v1/guest/telegram/webhook';
        return [
            'existing backend differs from frontend' => [$backend, null, $backend, 'keep-this-secret'],
            'remembered backend when callback absent' => ['', $backend, $backend, 'keep-this-secret'],
            'legacy query and missing secret' => [$backend . '?access_token=legacy', null, $backend, ''],
            'first registration' => ['', null, 'https://frontend.example.test/api/v1/guest/telegram/webhook', ''],
        ];
    }

    public function testCacheFailureCannotRegisterASecretWorkersHaveNotLoaded(): void
    {
        config(['v2board' => ['telegram_webhook_secret' => 'keep-this-secret']]);
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('getWebhookInfo')->once()->andReturn((object)['result' => (object)['url' => 'https://backend.example.test/api/v1/guest/telegram/webhook']]);
        $telegram->shouldNotReceive('setWebhook');
        Artisan::shouldReceive('call')->with('config:cache')->once()->andReturn(1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('配置缓存刷新失败');
        (new TelegramWebhookService())->refresh($telegram);
    }

    public function testTelegramFailureCannotSilentlyReplaceTheCallbackWithAppUrl(): void
    {
        config(['v2board' => ['app_url' => 'https://frontend.example.test', 'telegram_webhook_secret' => 'keep-this-secret']]);
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('getWebhookInfo')->once()->andThrow(new \RuntimeException('network unavailable'));
        $telegram->shouldNotReceive('setWebhook');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('network unavailable');
        (new TelegramWebhookService())->refresh($telegram);
    }

    /** @dataProvider publicEndpointCases */
    public function testPublicEndpointMustAcceptTheWorkerSecret(int $status, bool $error, $response, bool $healthy): void
    {
        $url = 'https://backend.example.test/api/v1/guest/telegram/webhook';
        config(['v2board.telegram_webhook_url' => $url, 'v2board.telegram_webhook_secret' => 'worker-secret']);
        $curl = Mockery::mock(\Curl\Curl::class);
        $curl->shouldReceive('setConnectTimeout')->once()->with(3);
        $curl->shouldReceive('setTimeout')->once()->with(10);
        $curl->shouldReceive('setHeader')->once()->with('X-Telegram-Bot-Api-Secret-Token', 'worker-secret');
        $curl->shouldReceive('setHeader')->once()->with('Content-Type', 'application/json');
        $curl->shouldReceive('post')->once()->with($url, '{}');
        $curl->shouldReceive('close')->once();
        $curl->httpStatusCode = $status;
        $curl->error = $error;
        $curl->response = $response;
        if (!$healthy) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('HTTP ' . $status);
        }
        (new TelegramWebhookService())->verify($curl);
        if ($healthy) $this->assertTrue(true);
    }

    public function publicEndpointCases(): array
    {
        return [
            'active worker accepts secret' => [200, false, (object)['data' => true], true],
            'stale worker secret rejected' => [401, true, (object)['message' => 'unauthorized'], false],
            'API proxy missing' => [404, true, '<html>frontend</html>', false],
            'frontend returns success HTML' => [200, false, '<html>frontend</html>', false],
            'incorrect JSON response' => [200, false, (object)['data' => false], false],
            'TLS or connection failure' => [0, true, null, false],
        ];
    }
}
