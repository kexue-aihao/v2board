<?php

namespace Tests\Unit;

use App\Jobs\OrderHandleJob;
use App\Models\Order;
use App\Models\ResellerAccount;
use App\Models\ResellerOrder;
use App\Models\ResellerPayment;
use App\Services\OrderService;
use App\Services\ResellerOrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderPaymentRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        Crypt::swap(new Encrypter(str_repeat('k', 32), 'AES-256-CBC'));
        Log::swap(new NullLogger());

        Schema::create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            $table->string('trade_no')->unique();
            $table->integer('user_id');
            $table->integer('total_amount');
            $table->integer('status')->default(0);
            $table->integer('paid_at')->nullable();
            $table->string('callback_no')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_reseller_payment', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('reseller_id');
            $table->string('uuid');
            $table->string('driver');
            $table->boolean('enabled')->default(true);
            $table->text('config_encrypted');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_reseller_order', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('reseller_id');
            $table->integer('platform_order_id');
            $table->integer('reseller_payment_id');
            $table->integer('amount_snapshot');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function testPaidOrderCanRetryAfterDispatchFails(): void
    {
        $order = $this->order();
        $dispatches = [];
        $this->failFirstDispatch($order, $dispatches);

        $this->assertFalse((new OrderService($order))->paid('gateway-transaction'));
        $paid = $order->fresh();
        $this->assertSame(1, (int)$paid->status);
        $this->assertNotNull($paid->paid_at);
        $this->assertSame('gateway-transaction', $paid->callback_no);

        // The original model is still pending; paid() must reload the saved state.
        $this->assertTrue((new OrderService($order))->paid('gateway-transaction'));

        $this->assertCount(2, $dispatches);
        $this->assertSame($paid->paid_at, $order->fresh()->paid_at);
        $this->assertSame($paid->callback_no, $order->fresh()->callback_no);
    }

    public function testRetryDoesNotReplaceTheRecordedPayment(): void
    {
        Bus::fake();
        $order = $this->order([
            'status' => 1,
            'paid_at' => 1700000000,
            'callback_no' => 'original-transaction',
        ]);

        $this->assertTrue((new OrderService($order))->paid('retry-transaction'));

        Bus::assertDispatched(OrderHandleJob::class, 1);
        $this->assertSame(1700000000, (int)$order->fresh()->paid_at);
        $this->assertSame('original-transaction', $order->fresh()->callback_no);
        $this->assertSame(1, (int)$order->fresh()->status);
    }

    /** @dataProvider terminalStatuses */
    public function testPaidCallbackDoesNotReopenTerminalOrders(int $status): void
    {
        Bus::fake();
        $order = $this->order(['paid_at' => 1700000000, 'callback_no' => 'recorded-transaction']);
        DB::table('v2_order')->where('id', $order->id)->update(['status' => $status]);

        $this->assertTrue((new OrderService($order))->paid('retry-transaction'));

        Bus::assertNothingDispatched();
        $this->assertSame($status, (int)$order->fresh()->status);
        $this->assertSame(1700000000, (int)$order->fresh()->paid_at);
        $this->assertSame('recorded-transaction', $order->fresh()->callback_no);
    }

    public function testSignedResellerCallbackRetriesFailedDispatch(): void
    {
        $order = $this->order();
        [$store, $payment, $callback] = $this->resellerCheckout($order);
        $dispatches = [];
        $this->failFirstDispatch($order, $dispatches);
        $service = new ResellerOrderService();

        try {
            $service->notify($store, $payment->uuid, $callback);
            $this->fail('A failed dispatch must not acknowledge the payment callback.');
        } catch (HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('Order opening failed', $e->getMessage());
        }
        $paid = $order->fresh();
        $this->assertSame(1, (int)$paid->status);
        $this->assertSame('gateway-transaction', $paid->callback_no);

        $this->assertSame('success', $service->notify($store, $payment->uuid, $callback));

        $this->assertCount(2, $dispatches);
        $this->assertSame($paid->paid_at, $order->fresh()->paid_at);
        $this->assertSame($paid->callback_no, $order->fresh()->callback_no);
    }

    /** @dataProvider terminalStatuses */
    public function testSignedResellerCallbackDoesNotReopenTerminalOrders(int $status): void
    {
        Bus::fake();
        $order = $this->order(['status' => $status]);
        [$store, $payment, $callback] = $this->resellerCheckout($order);

        $this->assertSame('success', (new ResellerOrderService())->notify($store, $payment->uuid, $callback));

        Bus::assertNothingDispatched();
        $this->assertSame($status, (int)$order->fresh()->status);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertNull($order->fresh()->callback_no);
    }

    public function testInvalidResellerSignatureCannotRetryAPaidOrder(): void
    {
        Bus::fake();
        $order = $this->order(['status' => 1]);
        [$store, $payment, $callback] = $this->resellerCheckout($order);
        $callback['sign'] = str_repeat('0', 32);

        try {
            (new ResellerOrderService())->notify($store, $payment->uuid, $callback);
            $this->fail('An invalid signature must be rejected before retrying an order.');
        } catch (HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame('Payment verification failed', $e->getMessage());
        }

        Bus::assertNothingDispatched();
        $this->assertSame(1, (int)$order->fresh()->status);
    }

    public function terminalStatuses(): array
    {
        return ['cancelled' => [2], 'completed' => [3], 'discounted' => [4]];
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'trade_no' => 'retry-test-order',
            'user_id' => 1,
            'total_amount' => 1234,
            'status' => 0,
        ], $attributes));
    }

    private function failFirstDispatch(Order $order, array &$dispatches): void
    {
        Bus::shouldReceive('dispatch')->andReturnUsing(function ($job) use ($order, &$dispatches) {
            $this->assertInstanceOf(OrderHandleJob::class, $job);
            $this->assertSame('order_handle', $job->queue);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame(1, (int)$order->fresh()->status);
            $dispatches[] = $job;
            if (count($dispatches) === 1) {
                throw new RuntimeException('Queue is temporarily unavailable');
            }
        });
    }

    private function resellerCheckout(Order $order): array
    {
        $store = new ResellerAccount();
        $store->id = 1;
        $payment = ResellerPayment::create([
            'reseller_id' => $store->id,
            'uuid' => 'retry-test-payment',
            'driver' => 'EPay',
            'enabled' => true,
            'config_encrypted' => Crypt::encryptString(json_encode([
                'pid' => 'retry-test-merchant',
                'key' => 'retry-test-secret',
            ])),
        ]);
        ResellerOrder::create([
            'reseller_id' => $store->id,
            'platform_order_id' => $order->id,
            'reseller_payment_id' => $payment->id,
            'amount_snapshot' => $order->total_amount,
        ]);
        $callback = [
            'pid' => 'retry-test-merchant',
            'out_trade_no' => $order->trade_no,
            'trade_no' => 'gateway-transaction',
            'trade_status' => 'TRADE_SUCCESS',
            'money' => '12.34',
        ];
        ksort($callback);
        $pairs = [];
        foreach ($callback as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }
        $callback['sign'] = md5(implode('&', $pairs) . 'retry-test-secret');
        $callback['sign_type'] = 'MD5';
        return [$store, $payment, $callback];
    }
}
