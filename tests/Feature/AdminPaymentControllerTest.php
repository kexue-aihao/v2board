<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminPaymentControllerTest extends TestCase
{
    private $url;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.default' => 'array',
            'v2board.app_url' => 'https://panel.example.test',
            'v2board.payment_secure_driver_allowlist' => [],
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->withoutMiddleware();
        $this->url = '/api/v1/' . config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))) . '/payment';

        Schema::create('v2_payment', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('payment');
            $table->text('config');
            $table->string('uuid');
            $table->string('icon')->nullable();
            $table->string('notify_domain')->nullable();
            $table->integer('handling_fee_fixed')->nullable();
            $table->float('handling_fee_percent')->nullable();
            $table->boolean('enable')->default(false);
            $table->integer('sort')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_payment_attempt', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('payment_id');
            $table->string('status');
        });
    }

    /** @dataProvider epayDrivers */
    public function testSwitchingDriversReturnsTheSelectedFormWithoutOldCredentials(string $driver): void
    {
        $payment = $this->payment('AlipayF2F', ['app_id' => 'old-app', 'url' => 'old-url', 'key' => 'old-secret']);
        $form = $this->postJson($this->url . '/getPaymentForm', ['id' => $payment->id, 'payment' => $driver])
            ->assertOk()->json('data');

        $this->assertArrayHasKey('url', $form);
        $this->assertArrayHasKey('pid', $form);
        $this->assertArrayHasKey('key', $form);
        $this->assertArrayNotHasKey('app_id', $form);
        foreach (['url', 'key'] as $key) {
            $this->assertNotSame($payment->config[$key], $form[$key]['value'] ?? null);
        }
        $this->assertSame('AlipayF2F', $payment->fresh()->payment);
    }

    public static function epayDrivers(): array
    {
        return [['EPay'], ['EPayQrcode']];
    }

    public function testEditingTheSameDriverPrefillsSavedConfiguration(): void
    {
        $config = $this->epayConfig();
        $payment = $this->payment('EPay', $config);
        foreach ([['id' => $payment->id, 'payment' => 'EPay'], ['id' => $payment->id]] as $params) {
            $form = $this->postJson($this->url . '/getPaymentForm', $params)->assertOk()->json('data');
            foreach ($config as $key => $value) $this->assertSame($value, $form[$key]['value']);
        }
    }

    public function testNewPaymentsCanLoadTheRequestedDriver(): void
    {
        $this->postJson($this->url . '/getPaymentForm', ['payment' => 'EPay'])
            ->assertOk()->assertJsonPath('data.pid.label', 'PID');
    }

    public function testAnExistingIdCannotBypassDriverAvailabilityChecks(): void
    {
        $payment = $this->payment('AlipayF2F', []);
        $this->postJson($this->url . '/getPaymentForm', ['id' => $payment->id, 'payment' => 'BTCPay'])->assertStatus(422);
        $this->postJson($this->url . '/getPaymentForm', ['id' => $payment->id, 'payment' => 'MGate'])->assertStatus(422);
        config(['v2board.payment_secure_driver_allowlist' => ['BTCPay']]);
        $this->postJson($this->url . '/getPaymentForm', ['id' => $payment->id, 'payment' => 'BTCPay'])->assertOk();
    }

    public function testExistingUnallowlistedDriversCanStillShowTheirSavedForm(): void
    {
        $payment = $this->payment('BTCPay', []);
        $this->postJson($this->url . '/getPaymentForm', ['id' => $payment->id, 'payment' => 'BTCPay'])->assertOk();
    }

    public function testInvalidDriverAndMissingPaymentAreRejected(): void
    {
        $payment = $this->payment('EPay', $this->epayConfig());
        $this->postJson($this->url . '/getPaymentForm', ['id' => $payment->id, 'payment' => '../AlipayF2F'])->assertStatus(422);
        $this->postJson($this->url . '/getPaymentForm', ['id' => 999, 'payment' => 'EPay'])->assertStatus(404);
    }

    public function testEpayDriverAndConfigurationSurviveSaveFetchAndRepeatedSave(): void
    {
        $payment = $this->payment('AlipayF2F', ['app_id' => 'old-app']);
        $params = ['id' => $payment->id, 'name' => 'EPay channel', 'payment' => 'EPay', 'config' => $this->epayConfig()];
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($this->url . '/save', $params)->assertOk()->assertJsonPath('data', true);
            $record = $this->getJson($this->url . '/fetch')->assertOk()->json('data.0');
            $this->assertSame('EPay', $record['payment']);
            $this->assertSame($params['config'], $record['config']);
            $this->assertStringContainsString('/notify/EPay/', $record['notify_url']);
            $form = $this->postJson($this->url . '/getPaymentForm', ['id' => $record['id'], 'payment' => $record['payment']])
                ->assertOk()->json('data');
            foreach ($params['config'] as $key => $value) $this->assertSame($value, $form[$key]['value']);
        }
    }

    private function payment(string $driver, array $config): Payment
    {
        return Payment::create(['name' => 'Test channel', 'payment' => $driver, 'config' => $config, 'uuid' => 'testuuid']);
    }

    private function epayConfig(): array
    {
        return ['url' => 'https://gateway.example.test', 'pid' => 'merchant-1', 'key' => 'test-secret', 'type' => 'alipay'];
    }
}
