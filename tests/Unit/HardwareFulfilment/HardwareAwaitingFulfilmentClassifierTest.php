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

    public function test_support_serial_with_paid_hardware_commerce_is_fulfilment_not_opened(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
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

        $order = $this->order('RBP2138', [
            'cashfree_payment_id' => 'paid',
            'serial_number' => '11119634',
            'created_at' => '2026-11-14 12:06:13',
        ]);
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-022155',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RBP2138',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RBP2138',
            'payload_hash' => hash('sha256', 'RBP2138'),
            'status' => CommerceOrderStatus::Validated,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'received_at' => '2026-11-14 12:03:29',
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'model_id' => 946,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'MFS 110',
            'qty' => 1,
            'unit_price' => 2499,
            'gst_percentage' => 18,
            'taxable_value' => 2117.8,
            'tax_total' => 381.2,
            'line_total' => 2499,
        ]);

        $this->assertSame(
            HardwareAwaitingFulfilmentReason::FulfilmentNotOpened,
            HardwareAwaitingFulfilmentClassifier::reason($order, $commerce),
        );
    }

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
            HardwareAwaitingFulfilmentReason::AwaitingHandoff,
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
