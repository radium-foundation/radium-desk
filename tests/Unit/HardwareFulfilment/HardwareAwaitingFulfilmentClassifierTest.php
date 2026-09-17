<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareAwaitingFulfilmentReason;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareAwaitingFulfilmentClassifier;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareAwaitingFulfilmentClassifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_classifies_review_candidate(): void
    {
        $order = $this->order('RDE960001', [
            'cashfree_payment_id' => 'cf_paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);

        $this->assertSame(
            HardwareAwaitingFulfilmentReason::AwaitingHandoff,
            HardwareAwaitingFulfilmentClassifier::reason($order),
        );
    }

    public function test_classifies_rin_frozen_hold_blocked_unpaid_historical_and_completed(): void
    {
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Rin,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RIN960002')),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Frozen,
            HardwareAwaitingFulfilmentClassifier::reason($this->order(HardwareFulfilmentEligibility::FROZEN_SOURCE_IDS[0], [
                'cashfree_payment_id' => 'paid',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Hold,
            HardwareAwaitingFulfilmentClassifier::reason($this->order(HardwareFulfilmentEligibility::HOLD_SOURCE_IDS[0], [
                'cashfree_payment_id' => 'paid',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Hold,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE255714', [
                'cashfree_payment_id' => 'paid',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Hold,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE313554', [
                'cashfree_payment_id' => 'paid',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Blocked,
            HardwareAwaitingFulfilmentClassifier::reason($this->order(
                HardwareFulfilmentEligibility::BLOCKED_UNTIL_AUTHORIZED_SOURCE_IDS[0],
                ['cashfree_payment_id' => 'paid'],
            )),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::Unpaid,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE960003')),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::PreCutoff,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE960004', [
                'cashfree_payment_id' => 'paid',
                'created_at' => '2026-09-01 10:00:00',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::DeskAlreadyCompleted,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE960005', [
                'cashfree_payment_id' => 'paid',
                'serial_number' => '10500001',
                'created_at' => '2026-09-07 10:00:00',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::DeskAlreadyCompleted,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE960006', [
                'cashfree_payment_id' => 'paid',
                'transaction_id' => 'TX-1',
                'created_at' => '2026-09-07 10:00:00',
            ])),
        );
        $this->assertSame(
            HardwareAwaitingFulfilmentReason::AwaitingHandoff,
            HardwareAwaitingFulfilmentClassifier::reason($this->order('RDE960007', [
                'cashfree_payment_id' => 'paid',
                'created_at' => '2026-09-07 10:00:00',
                'product_name' => '',
            ])),
        );
    }

    public function test_unmapped_physical_model_is_product_mapping_required_even_when_similar_inventory_sku_exists(): void
    {
        $listing = 'Precision Biometrics PB 510 / PB1000 L1 F';
        $order = $this->order('RBP960062', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
            'product_name' => $listing,
        ]);
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-RBP960062',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP960062',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP960062',
            'payload_hash' => hash('sha256', 'RBP960062'),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'ordered_at' => '2026-09-07 10:00:00',
            'paid_at' => '2026-09-07 10:05:00',
            'support_order_id' => $order->id,
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => '1003',
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'model_id' => 1003,
            'description' => $listing,
            'qty' => 1,
            'unit_price' => 3727.97,
            'gst_percentage' => 18,
            'taxable_value' => 3159.30,
            'tax_total' => 568.67,
            'line_total' => 3727.97,
        ]);
        InventoryProduct::query()->create([
            'sku' => 'RBPB1000L1',
            'name' => 'PB 1000 L1',
            'hsn_code' => '84716090',
            'gst_percentage' => 18,
            'unit_price' => 3727.97,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $this->assertSame(
            HardwareAwaitingFulfilmentReason::ProductMappingRequired,
            HardwareAwaitingFulfilmentClassifier::reason($order, $commerce->fresh('items')),
        );
    }

    public function test_mapped_physical_model_is_review_candidate(): void
    {
        $order = $this->order('RDE960008', [
            'cashfree_payment_id' => 'paid',
            'created_at' => '2026-09-07 10:00:00',
        ]);
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-RDE960008',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE960008',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RDE960008',
            'payload_hash' => hash('sha256', 'RDE960008'),
            'status' => CommerceOrderStatus::Validated,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'ordered_at' => '2026-09-07 10:00:00',
            'paid_at' => '2026-09-07 10:05:00',
            'support_order_id' => $order->id,
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => '946',
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'model_id' => 946,
            'description' => 'Mantra MFS110 L1',
            'qty' => 1,
            'unit_price' => 2549,
            'gst_percentage' => 18,
            'taxable_value' => 2160.17,
            'tax_total' => 388.83,
            'line_total' => 2549,
        ]);
        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110 L1',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
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

        $this->assertSame(
            HardwareAwaitingFulfilmentReason::ReviewCandidate,
            HardwareAwaitingFulfilmentClassifier::reason($order, $commerce->fresh('items')),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function order(string $orderId, array $overrides = []): Order
    {
        $creator = User::factory()->create(['is_active' => true]);
        if (filled($overrides['cashfree_payment_id'] ?? null)) {
            $overrides['cashfree_payment_id'] = 'cf_'.$orderId;
        }

        $order = Order::query()->create(array_merge([
            'order_id' => $orderId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $order->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $order->fresh();
    }
}
