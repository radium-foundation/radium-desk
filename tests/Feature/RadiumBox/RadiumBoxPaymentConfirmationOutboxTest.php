<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\OrderStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Events\Finance\OrderPaid;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\RadiumBox\RadiumBoxPaymentConfirmationOutboxWriter;
use App\Services\RadiumBox\RadiumBoxPaymentSyncDiagnostics;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadiumBoxPaymentConfirmationOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'radiumbox.payment_confirm.enabled' => true,
            'radiumbox.payment_confirm.outbox_enabled' => true,
            'radiumbox.payment_confirm.drain_on_enqueue' => true,
            'order_lookup.spokes.radiumbox_com.enabled' => true,
            'order_lookup.spokes.radiumbox_com.base_url' => 'https://radiumbox.test',
            'order_lookup.spokes.radiumbox_com.token' => 'desk-token',
        ]);
    }

    public function test_order_paid_enqueues_durable_payment_confirm_outbox_event(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::response([
                'status' => 'paid',
                'first_paid' => true,
                'gateway_order_id' => 'RBP2138',
                'business_order_id' => 'RBP2138',
                'payment_status' => 'Paid',
                'handoff' => [
                    'id' => 9,
                    'status' => 'pending',
                    'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP2138',
                ],
            ], 200),
        ]);

        $order = $this->createPaidHardwareOrder('RBP2138');
        OrderPaid::dispatch($order);

        $outbox = OutboxEvent::query()
            ->where('idempotency_key', 'radiumbox.payment_confirm:'.$order->id)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertSame(RadiumBoxPaymentConfirmationOutboxWriter::EVENT_TYPE, $outbox->event_type);
        $this->assertSame(OutboxEventStatus::Completed, $outbox->status);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::HandoffPending,
            $order->fresh()->radiumbox_sync_status,
        );
    }

    public function test_timeout_marks_reconciliation_required_and_outbox_retries(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('Connection timed out');
            }

            return Http::response([
                'status' => 'paid',
                'first_paid' => true,
                'gateway_order_id' => 'RBP2200',
                'business_order_id' => 'RBP2200',
                'payment_status' => 'Paid',
                'handoff' => [
                    'id' => 10,
                    'status' => 'pending',
                    'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP2200',
                ],
            ], 200);
        });

        $order = $this->createPaidHardwareOrder('RBP2200');
        app(RadiumBoxPaymentConfirmationOutboxWriter::class)->enqueueIfRequired($order);

        $processor = app(OutboxProcessorService::class);
        $processor->processAggregate(RadiumBoxPaymentConfirmationOutboxWriter::AGGREGATE_TYPE, $order->id);

        $outbox = OutboxEvent::query()
            ->where('idempotency_key', 'radiumbox.payment_confirm:'.$order->id)
            ->first();

        $this->assertNotNull($outbox);
        $this->assertSame(OutboxEventStatus::Pending, $outbox->status);
        $this->assertSame(
            RadiumBoxEnrichmentSyncStatus::ReconciliationRequired,
            $order->fresh()->radiumbox_sync_status,
        );
        $this->assertSame(1, RadiumBoxPaymentSyncDiagnostics::incompleteHandoffCount());

        $outbox->forceFill(['available_at' => now()->subMinute(), 'status' => OutboxEventStatus::Pending])->save();
        $processor->processAggregate(RadiumBoxPaymentConfirmationOutboxWriter::AGGREGATE_TYPE, $order->id);

        $this->assertSame(OutboxEventStatus::Completed, $outbox->fresh()->status);
        $this->assertSame(2, $attempts);
    }

    public function test_duplicate_order_paid_does_not_create_duplicate_outbox_rows(): void
    {
        Http::fake([
            'https://radiumbox.test/api/integrations/v1/cashfree/confirm-payment' => Http::response([
                'status' => 'already_paid',
                'first_paid' => false,
                'gateway_order_id' => 'RBP2201',
                'business_order_id' => 'RBP2201',
                'payment_status' => 'Paid',
            ], 200),
        ]);

        $order = $this->createPaidHardwareOrder('RBP2201');
        OrderPaid::dispatch($order);
        OrderPaid::dispatch($order->fresh());

        $this->assertSame(
            1,
            OutboxEvent::query()->where('event_type', RadiumBoxPaymentConfirmationOutboxWriter::EVENT_TYPE)->count(),
        );
    }

    private function createPaidHardwareOrder(string $businessOrderId): Order
    {
        $user = User::factory()->create(['is_active' => true]);

        return Order::query()->create([
            'order_id' => $businessOrderId,
            'product_name' => 'MFS 110',
            'status' => OrderStatus::Active,
            'customer_name' => 'Buyer',
            'cashfree_payment_id' => 'pay_'.$businessOrderId,
            'created_by' => $user->id,
        ]);
    }
}