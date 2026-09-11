<?php

namespace Tests\Unit\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\Order;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareFulfilmentProductLines;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareFulfilmentProductLinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_order_product_name_is_used_when_commerce_has_no_physical_lines(): void
    {
        $order = $this->supportOrder('RDE980001', 'Mantra L1');

        $catalog = HardwareFulfilmentProductLines::resolve(null, $order);

        $this->assertFalse($catalog['missing']);
        $this->assertSame('Mantra L1', $catalog['compact']);
        $this->assertSame('', $catalog['quantity']);
        $this->assertSame('Mantra L1', $catalog['lines'][0]['primary']);
        $this->assertFalse($catalog['lines'][0]['ambiguous']);
    }

    public function test_missing_product_is_an_exception_not_a_dash(): void
    {
        $order = $this->supportOrder('RDE980002', null);

        $catalog = HardwareFulfilmentProductLines::resolve(null, $order);

        $this->assertTrue($catalog['missing']);
        $this->assertSame('Product data missing', $catalog['compact']);
        $this->assertSame([], $catalog['lines']);
    }

    public function test_multi_product_commerce_lines_use_description_and_qty(): void
    {
        $order = $this->supportOrder('RDE980003', 'Ignored support name');
        $commerce = $this->commerce($order, 'RDE980003');
        $this->physicalItem($commerce, 1, 'Mantra L1', 1);
        $this->physicalItem($commerce, 2, 'Morpho L1', 2);

        $catalog = HardwareFulfilmentProductLines::resolve($commerce->fresh('items'), $order);

        $this->assertFalse($catalog['missing']);
        $this->assertSame('Mantra L1 · 1 Q +1', $catalog['compact']);
        $this->assertSame('3', $catalog['quantity']);
        $this->assertSame('Mantra L1', $catalog['lines'][0]['primary']);
        $this->assertSame(2, $catalog['lines'][1]['qty']);
    }

    public function test_mantra_mfs_commerce_line_uses_canonical_variant_not_marketing_description(): void
    {
        $order = $this->supportOrder('RBP31', null);
        $commerce = $this->commerce($order, 'RBP31');
        $this->physicalItem($commerce, 1, 'Mantra MFS 100 / 110 L1 Fingerprint Scanner', 1, [
            'model_id' => 946,
            'rdserviceid' => 1119,
            'amcid' => 1120,
            'otgid' => 1127,
        ]);

        $catalog = HardwareFulfilmentProductLines::resolve($commerce->fresh('items'), $order);

        $this->assertFalse($catalog['missing']);
        $this->assertSame('Mantra MFS 110 L1 R1 W1 UC Q1', $catalog['compact']);
        $this->assertSame('Mantra MFS 110 L1', $catalog['lines'][0]['primary']);
        $this->assertSame('R1 W1 UC Q1', $catalog['lines'][0]['secondary']);
        $this->assertFalse($catalog['lines'][0]['ambiguous']);
    }

    public function test_rbp29_ugr_product_line_uses_model_id_variant(): void
    {
        $order = $this->supportOrder('RBP29', null);
        $commerce = $this->commerce($order, 'RBP29');
        $this->physicalItem($commerce, 1, 'Radium Box UGR 86 UIDAI Approved USB GPS Receiver for AADHAAR', 1, [
            'model_id' => 1723,
            'amcid' => 1788,
        ]);

        $catalog = HardwareFulfilmentProductLines::resolve($commerce->fresh('items'), $order);

        $this->assertSame('Radium Box UGR 89', $catalog['lines'][0]['primary']);
        $this->assertSame('NaviC Q1', $catalog['lines'][0]['secondary']);
    }

    public function test_mantra_mfs_compact_uses_canonical_variant_not_generic_listing(): void
    {
        $order = $this->supportOrder('RDE980004', 'Ignored support name');
        $commerce = $this->commerce($order, 'RDE980004');
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => '946',
            'catalog_sku' => 'RBMFS110L1',
            'model_id' => 946,
            'rdserviceid' => 1119,
            'amcid' => 1120,
            'otgid' => 1126,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'Mantra MFS 100 / 110 L1 Fingerprint Scanner',
            'qty' => 1,
            'unit_price' => 2499,
            'gst_percentage' => 18,
            'taxable_value' => 2117.80,
            'tax_total' => 381.20,
            'line_total' => 2499,
        ]);

        $catalog = HardwareFulfilmentProductLines::resolve($commerce->fresh('items'), $order);

        $this->assertSame('Mantra MFS 110 L1 R1 W1 U Q1', $catalog['compact']);
        $this->assertSame('Mantra MFS 110 L1', $catalog['lines'][0]['primary']);
        $this->assertSame('R1 W1 U Q1', $catalog['lines'][0]['secondary']);
    }

    private function supportOrder(string $orderId, ?string $productName): Order
    {
        $creator = User::factory()->create(['is_active' => true]);

        return Order::query()->create([
            'order_id' => $orderId,
            'product_name' => $productName,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);
    }

    private function commerce(Order $order, string $sourceId): CommerceOrder
    {
        return CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function physicalItem(CommerceOrder $commerce, int $lineNo, string $description, int $qty, array $overrides = []): void
    {
        CommerceOrderItem::query()->create(array_merge([
            'commerce_order_id' => $commerce->id,
            'line_no' => $lineNo,
            'sku' => 'SKU-'.$lineNo,
            'catalog_sku' => 'CAT-'.$lineNo,
            'model_id' => 900 + $lineNo,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => $description,
            'qty' => $qty,
            'unit_price' => 1000,
            'gst_percentage' => 18,
            'taxable_value' => 847.46,
            'tax_total' => 152.54,
            'line_total' => 1000,
        ], $overrides));
    }
}
