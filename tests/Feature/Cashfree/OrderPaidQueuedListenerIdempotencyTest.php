<?php

namespace Tests\Feature\Cashfree;

use App\Enums\FinanceJournalSourceType;
use App\Enums\OrderStatus;
use App\Events\Finance\OrderPaid;
use App\Infrastructure\Queue\QueueRouting;
use App\Listeners\Finance\PostOrderPaidJournal;
use App\Listeners\RadiumBox\ConfirmRadiumBoxPaymentOnOrderPaid;
use App\Models\CashfreeWebhookLog;
use App\Models\FinanceJournal;
use App\Models\Order;
use App\Models\User;
use App\Services\Finance\OrderPaymentJournalService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OrderPaidQueuedListenerIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('jobs')) {
            RefreshDatabaseState::$migrated = false;
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            RefreshDatabaseState::$migrated = true;
        }

        $connectionName = (string) config('database.default');
        $transactionsManager = new DatabaseTransactionsManager;
        $this->app->instance('db.transactions', $transactionsManager);
        DB::connection($connectionName)->setTransactionManager($transactionsManager);

        config([
            'queue.default' => 'database',
            'cashfree.verify_signature' => false,
            'radiumbox.enabled' => false,
        ]);

        DB::table('jobs')->delete();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    private function paidOrder(): Order
    {
        $admin = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        return Order::query()->create([
            'order_id' => 'RD'.random_int(10000, 99999),
            'serial_number' => 'SN-'.uniqid(),
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => OrderStatus::Active,
            'payment_amount' => 500.00,
            'payment_date' => now(),
            'cashfree_payment_id' => 'cf_'.uniqid(),
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }

    private function drainCriticalQueue(int $maxRuns = 25): void
    {
        for ($i = 0; $i < $maxRuns && $this->criticalJobCount() > 0; $i++) {
            Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => QueueRouting::critical(),
                '--once' => true,
                '--stop-when-empty' => true,
            ]);
        }
    }

    private function criticalJobCount(): int
    {
        return (int) DB::table('jobs')->where('queue', QueueRouting::critical())->count();
    }

    /** @return array<string, mixed> */
    private function successfulPaymentPayload(string $cfPaymentId, string $orderId): array
    {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 500,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 500,
                    'payment_currency' => 'INR',
                    'payment_time' => '2022-12-15T12:20:29+05:30',
                    'payment_group' => 'upi',
                ],
                'customer_details' => [
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'test@gmail.com',
                    'customer_phone' => '9908734801',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => $orderId,
                    'gateway_payment_id' => '1504280099',
                ],
            ],
        ];
    }

    public function test_post_order_paid_journal_listener_repeated_handle_is_idempotent(): void
    {
        $order = $this->paidOrder();
        $listener = app(PostOrderPaidJournal::class);

        $listener->handle(new OrderPaid($order));
        $listener->handle(new OrderPaid($order->fresh()));

        $this->assertSame(
            1,
            FinanceJournal::query()
                ->where('source_type', FinanceJournalSourceType::OrderPayment)
                ->where('idempotency_key', 'order_payment:'.$order->id)
                ->count(),
        );
    }

    public function test_journal_listener_swallows_throwable_without_rethrow_for_fail_open_semantics(): void
    {
        $order = $this->paidOrder();
        $mock = Mockery::mock(OrderPaymentJournalService::class);
        $mock->shouldReceive('postForOrder')
            ->once()
            ->andThrow(new RuntimeException('transient journal failure'));
        $this->app->instance(OrderPaymentJournalService::class, $mock);

        Log::spy();

        $listener = app(PostOrderPaidJournal::class);
        $listener->handle(new OrderPaid($order));

        Log::shouldHaveReceived('error')
            ->with('[Finance] Order paid journal listener failed.', Mockery::on(fn (array $context): bool => ($context['order_id'] ?? null) === $order->id));

        $this->assertSame(0, FinanceJournal::query()->count());
    }

    public function test_confirm_listener_repeated_execution_with_already_paid_is_safe(): void
    {
        config([
            'radiumbox.payment_confirm.enabled' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-token',
        ]);

        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::sequence()
                ->push(['status' => 'paid', 'gateway_order_id' => 'RBP301'], 200)
                ->push(['status' => 'already_paid', 'gateway_order_id' => 'RBP301'], 200),
        ]);

        $admin = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();
        $order = Order::query()->create([
            'order_id' => 'RBP301',
            'cashfree_payment_id' => 'pay_301',
            'gateway_order_id' => 'RBP301',
            'payment_amount' => 100,
            'payment_method' => 'cashfree',
            'payment_date' => now(),
            'status' => OrderStatus::Active,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $listener = app(ConfirmRadiumBoxPaymentOnOrderPaid::class);
        $listener->handle(new OrderPaid($order));
        $listener->handle(new OrderPaid($order->fresh()));

        Http::assertSentCount(2);
    }

    public function test_duplicate_cashfree_webhook_delivery_drains_to_single_order_payment_journal(): void
    {
        $cfPaymentId = '1453099001';
        $orderId = 'order_DUP_'.random_int(1000, 9999);
        $payload = $this->successfulPaymentPayload($cfPaymentId, $orderId);

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $this->assertSame(2, CashfreeWebhookLog::query()->count());
        $this->assertSame(1, Order::query()->where('cashfree_payment_id', $cfPaymentId)->count());

        $this->drainCriticalQueue();

        $order = Order::query()->where('cashfree_payment_id', $cfPaymentId)->firstOrFail();

        $this->assertSame(
            1,
            FinanceJournal::query()
                ->where('source_type', FinanceJournalSourceType::OrderPayment)
                ->where('idempotency_key', 'order_payment:'.$order->id)
                ->count(),
        );
    }
}
