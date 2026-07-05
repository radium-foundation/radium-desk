<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxSyncAuditService;
use App\Services\RadiumBox\RadiumBoxSyncRecoveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CashfreePaidRadiumBoxEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-05 12:00:00');

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.base_url' => 'https://admin.radiumbox.com',
            'radiumbox.timeout_seconds' => 5,
            'radiumbox.connect_timeout_seconds' => 3,
            'radiumbox.recovery.enabled' => true,
            'radiumbox.recovery.stale_pending_minutes' => 30,
            'radiumbox.recovery.max_recovery_attempts' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cashfree_paid_with_radiumbox_pending_payment_still_imports_serial(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'order_id' => 'RD3435001',
                        'payment_status' => 'pending',
                        'order_status' => 'pending',
                        'serial_no' => 'M250546898',
                        'product_name' => 'Access FM220U L1',
                    ],
                ],
            ]),
        ]);

        $order = $this->createCashfreePaidOrder('RD3435001');

        $this->runEnrichmentJob($order);

        $order->refresh();

        $this->assertSame('M250546898', $order->serial_number);
        $this->assertTrue($order->isCashfreeVerified());
    }

    public function test_cashfree_paid_with_radiumbox_pending_payment_still_imports_model(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'order_id' => 'RD3435002',
                        'payment_status' => 'pending',
                        'serial_no' => '9655721',
                        'product_name' => 'MFS 110',
                    ],
                ],
            ]),
        ]);

        $order = $this->createCashfreePaidOrder('RD3435002');

        $this->runEnrichmentJob($order);

        $order->refresh();

        $this->assertSame('MFS 110', $order->device_model);
    }

    public function test_radiumbox_cannot_overwrite_cashfree_paid_status(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'order_id' => 'RD3435003',
                        'payment_status' => 'pending',
                        'order_status' => 'pending',
                        'serial_no' => '7881953',
                        'product_name' => 'MFS110',
                    ],
                ],
            ]),
        ]);

        $order = $this->createCashfreePaidOrder('RD3435003', cashfreePaymentId: 'cf_original_payment');

        $this->runEnrichmentJob($order);

        $order->refresh();

        $this->assertSame('cf_original_payment', $order->cashfree_payment_id);
        $this->assertSame('7881953', $order->serial_number);
    }

    public function test_missing_device_recovery_command_fixes_synced_cashfree_orders(): void
    {
        Queue::fake();

        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'order_id' => 'RD3435004',
                        'payment_status' => 'pending',
                        'serial_no' => 'SERIAL-RECOVER-1',
                        'product_name' => 'Morpho 1300',
                    ],
                ],
            ]),
        ]);

        $order = $this->createCashfreePaidOrder('RD3435004');
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $syncStore->markSynced($order->id, ['lookup_result' => 'no_data']);
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced,
            'radiumbox_last_sync_at' => now()->subHours(2),
            'radiumbox_sync_attempts' => 1,
        ]);

        $this->travel(2)->hours();

        $this->assertTrue(app(RadiumBoxSyncRecoveryService::class)->isSafeToRecover($order->fresh()));

        Artisan::call('radiumbox:recover-sync');

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, fn (RadiumBoxOrderEnrichmentJob $job): bool => $job->orderId === $order->id);

        (new RadiumBoxOrderEnrichmentJob($order->id))
            ->handle(
                app(RadiumBoxOrderEnrichmentService::class),
                app(QueueMetricsService::class),
            );

        $order->refresh();

        $this->assertSame('SERIAL-RECOVER-1', $order->serial_number);
        $this->assertSame('Morpho 1300', $order->device_model);

        $this->assertDatabaseHas('audit_logs', [
            'event' => RadiumBoxSyncAuditService::EVENT_ENRICHMENT_COMPLETED,
            'auditable_type' => (new Order)->getMorphClass(),
            'auditable_id' => $order->id,
        ]);
    }

    public function test_non_matching_radiumbox_record_does_not_corrupt_order(): void
    {
        Http::fake([
            'admin.radiumbox.com/api/search/order*' => Http::response([
                'status' => 200,
                'data' => [
                    'rd_order' => [
                        'order_id' => 'RD-OTHER-ORDER',
                        'payment_status' => 'paid',
                        'serial_no' => 'WRONG-SERIAL',
                        'product_name' => 'Wrong Model',
                    ],
                ],
            ]),
        ]);

        $order = $this->createCashfreePaidOrder('RD3435005');

        $this->runEnrichmentJob($order);

        $order->refresh();

        $this->assertNull($order->serial_number);
        $this->assertNull($order->device_model);
        $this->assertSame('cf_pay_RD3435005', $order->cashfree_payment_id);

        $this->assertDatabaseHas('audit_logs', [
            'event' => RadiumBoxSyncAuditService::EVENT_ENRICHMENT_STARTED,
            'auditable_id' => $order->id,
        ]);

        $this->assertFalse(
            AuditLog::query()
                ->where('auditable_id', $order->id)
                ->where('event', RadiumBoxSyncAuditService::EVENT_ENRICHMENT_COMPLETED)
                ->exists(),
        );

        $this->assertDatabaseHas('audit_logs', [
            'event' => RadiumBoxSyncAuditService::EVENT_ENRICHMENT_FAILED,
            'auditable_id' => $order->id,
        ]);
    }

    private function createCashfreePaidOrder(string $orderId, ?string $cashfreePaymentId = null): Order
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return Order::query()->create([
            'order_id' => $orderId,
            'cashfree_payment_id' => $cashfreePaymentId ?? 'cf_pay_'.$orderId,
            'payment_date' => now(),
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
            'created_at' => now()->subHours(2),
        ]);
    }

    private function runEnrichmentJob(Order $order): void
    {
        app(RadiumBoxOrderEnrichmentSyncStore::class)->markPending($order->id);

        (new RadiumBoxOrderEnrichmentJob($order->id))
            ->handle(
                app(RadiumBoxOrderEnrichmentService::class),
                app(QueueMetricsService::class),
            );
    }
}
