<?php

namespace Tests\Feature\PreShipmentCancellation;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CashfreeWebhookLog;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookPayloadParser;
use App\Services\HardwareFulfilment\RbpHardwareFulfilmentGapMonitor;
use App\Services\HardwareFulfilment\RbpHardwareFulfilmentGapRecoveryService;
use App\Services\PreShipmentCancellation\PreShipmentCancellationSubmitService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreShipmentCancellationGapPathsTest extends TestCase
{
    use RefreshDatabase;

    private const CANCELLED_SOURCE = 'RBP941001';

    private const ELIGIBLE_SOURCE = 'RBP941002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config([
            'pre_shipment_cancellation.enabled' => true,
            'hardware_fulfilment.rbp_gap_recovery.allowlist' => [self::CANCELLED_SOURCE, self::ELIGIBLE_SOURCE],
            'hardware_fulfilment.rbp_gap_monitor.grace_minutes' => 0,
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2499,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RBMFS110L1',
            'channel_sku' => '946',
        ]);
    }

    public function test_gap_recovery_skips_cancelled_state_a_order(): void
    {
        [$actor, $commerce] = $this->seedGap(self::CANCELLED_SOURCE);
        $order = Order::query()->where('order_id', self::CANCELLED_SOURCE)->firstOrFail();

        app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Cancel before recovery.',
            'idempotency_key' => 'psc-gap-'.self::CANCELLED_SOURCE,
        ]);

        $result = app(RbpHardwareFulfilmentGapRecoveryService::class)->recoverOne(self::CANCELLED_SOURCE, true);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(0, HardwareFulfilment::query()->count());
        $this->assertNotNull($commerce->fresh()->hardware_fulfilment_blocked_at);

        $again = app(RbpHardwareFulfilmentGapRecoveryService::class)->recoverOne(self::CANCELLED_SOURCE, true);
        $this->assertSame('skipped', $again['status']);
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_gap_monitor_omits_cancelled_state_a_order(): void
    {
        [$actor] = $this->seedGap(self::CANCELLED_SOURCE);
        $order = Order::query()->where('order_id', self::CANCELLED_SOURCE)->firstOrFail();

        app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Monitor must not list cancelled.',
            'idempotency_key' => 'psc-monitor-'.self::CANCELLED_SOURCE,
        ]);

        $gaps = app(RbpHardwareFulfilmentGapMonitor::class)->findGaps();
        $ids = array_column($gaps, 'source_id');
        $this->assertNotContains(self::CANCELLED_SOURCE, $ids);
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_monitor_command_json_excludes_cancelled_gap(): void
    {
        [$actor] = $this->seedGap(self::CANCELLED_SOURCE);
        $order = Order::query()->where('order_id', self::CANCELLED_SOURCE)->firstOrFail();

        app(PreShipmentCancellationSubmitService::class)->submit($actor, $order, [
            'cancellation_reason' => 'Command skips cancelled.',
            'idempotency_key' => 'psc-cmd-'.self::CANCELLED_SOURCE,
        ]);

        $this->artisan('desk:monitor-rbp-hardware-fulfilment-gaps', ['--json' => true])
            ->assertSuccessful();

        $gaps = app(RbpHardwareFulfilmentGapMonitor::class)->findGaps();
        $this->assertSame([], array_filter($gaps, fn (array $row): bool => $row['source_id'] === self::CANCELLED_SOURCE));
    }

    public function test_non_cancelled_gap_still_recovers(): void
    {
        $this->seedGap(self::ELIGIBLE_SOURCE);

        $result = app(RbpHardwareFulfilmentGapRecoveryService::class)->recoverOne(self::ELIGIBLE_SOURCE, true);
        $this->assertSame('recovered', $result['status']);
        $this->assertSame(1, HardwareFulfilment::query()->count());
        $this->assertContains(
            HardwareFulfilment::query()->value('state'),
            [HardwareFulfilmentState::Ingested, HardwareFulfilmentState::ReadyForFulfilment],
        );

        $gaps = app(RbpHardwareFulfilmentGapMonitor::class)->findGaps();
        $this->assertNotContains(self::ELIGIBLE_SOURCE, array_column($gaps, 'source_id'));
    }

    /**
     * @return array{0: User, 1: CommerceOrder}
     */
    private function seedGap(string $source): array
    {
        $actor = User::factory()->create();
        $actor->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $order = Order::query()->create([
            'order_id' => $source,
            'cashfree_payment_id' => 'cf-pay-'.$source,
            'payment_amount' => 2499,
            'product_name' => 'Hardware',
            'status' => 'active',
            'created_at' => '2026-11-14 10:00:00',
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$source,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $source,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$source,
            'payload_hash' => hash('sha256', $source),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'payment_reference' => 'cf-pay-'.$source,
            'currency' => 'INR',
            'order_value' => 2499,
            'received_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'support_order_id' => $order->id,
        ]);

        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => '946',
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
            'model_id' => 946,
            'description' => 'MFS110',
            'qty' => 1,
            'unit_price' => 2499,
            'line_total' => 2499,
        ]);

        $payload = [
            'type' => CashfreeWebhookPayloadParser::EVENT_PAYMENT_SUCCESS,
            'data' => [
                'payment' => [
                    'cf_payment_id' => 'cf-pay-'.$source,
                    'payment_status' => CashfreeWebhookPayloadParser::PAYMENT_STATUS_SUCCESS,
                    'payment_amount' => 2499,
                ],
            ],
        ];

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => 'cf-pay-'.$source,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload, JSON_THROW_ON_ERROR),
            'processing_status' => 'processed',
            'received_at' => now(),
        ]);

        return [$actor, $commerce];
    }
}
