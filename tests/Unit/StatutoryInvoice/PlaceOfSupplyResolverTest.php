<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\Data\PlaceOfSupplyResolution;
use App\Services\StatutoryInvoice\PlaceOfSupplyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaceOfSupplyResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_gstin_differs_from_billing_pos_but_igst_is_consistent_is_review_not_blocked(): void
    {
        $invoice = $this->serviceInvoice(
            buyerGstin: '29AAICA3918J1ZE',
            posState: 'Rajasthan',
            igst: '76.12',
            structured: [
                'line1' => 'Billing lane',
                'city' => 'Alwar',
                'state' => 'Rajasthan',
                'pincode' => '301707',
            ],
        );

        $resolution = app(PlaceOfSupplyResolver::class)->resolveForInvoice($invoice);

        $this->assertSame('Rajasthan', $resolution->state);
        $this->assertSame('08', $resolution->stateCode);
        $this->assertSame(PlaceOfSupplyResolution::CLASSIFICATION_REVIEW, $resolution->gstinPosClassification);
        $this->assertTrue($resolution->isResolvable());
    }

    public function test_delhi_seller_maharashtra_b2b_goods_uses_delivery_destination(): void
    {
        $invoice = $this->goodsInvoice(
            buyerGstin: '27AAICA3918J1Z5',
            posState: 'Maharashtra',
            igst: '180.00',
            structured: [
                'line1' => 'Billing office',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400001',
            ],
        );

        $resolution = app(PlaceOfSupplyResolver::class)->resolveForInvoice($invoice);

        $this->assertSame('Maharashtra', $resolution->state);
        $this->assertSame('27', $resolution->stateCode);
        $this->assertSame('goods_delivery_destination', $resolution->source);
    }

    public function test_goods_prefers_commerce_shipping_state_over_billing_when_present(): void
    {
        $invoice = $this->goodsInvoice(
            buyerGstin: '29AAICA3918J1ZE',
            posState: 'Karnataka',
            igst: '180.00',
            structured: [
                'line1' => 'Billing office',
                'city' => 'Bengaluru',
                'state' => 'Karnataka',
                'pincode' => '560001',
            ],
        );

        CommerceOrder::query()->create([
            'order_no' => 'CO-'.$invoice->source_id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => $invoice->source_id,
            'source_order_id' => $invoice->source_id,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$invoice->source_id,
            'payload_hash' => hash('sha256', $invoice->source_id),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'payment_method' => 'UPI',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'billing_state' => 'Karnataka',
            'billing_address' => 'Billing office',
            'billing_address_structured' => $invoice->billing_address_structured,
            'shipping_address' => 'Delivery lane',
            'shipping_address_structured' => [
                'line1' => 'Delivery lane',
                'city' => 'Mumbai',
                'state' => 'Maharashtra',
                'pincode' => '400001',
            ],
            'branch_code' => 'DELHI',
            'place_of_supply_state' => 'Karnataka',
            'taxable_value' => 1000.00,
            'tax_total' => 180.00,
            'order_value' => 1180.00,
            'ordered_at' => '2026-09-10 10:00:00',
            'received_at' => now(),
            'statutory_invoice_id' => $invoice->id,
        ]);

        $resolution = app(PlaceOfSupplyResolver::class)->resolveForInvoice($invoice);

        $this->assertSame('Maharashtra', $resolution->state);
        $this->assertSame('27', $resolution->stateCode);
        $this->assertSame('goods_delivery_destination', $resolution->source);
    }

    public function test_missing_pos_evidence_is_blocked(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-POS-MISSING',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'POS-MISSING',
            'idempotency_key' => 'statutory:pos:missing',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'buyer_gstin' => '07AAICA3918J1Z5',
            'billing_address' => 'Billing lane',
            'taxable_value' => '422.88',
            'discount' => '0.00',
            'tax_total' => '76.12',
            'cgst' => '38.06',
            'sgst' => '38.06',
            'igst' => '0.00',
            'rounding' => '0.00',
            'invoice_value' => '499.00',
            'issued_at' => '2026-09-10 10:00:00',
        ]);
        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'RD Service',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '499.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '422.88',
            'tax_total' => '76.12',
            'cgst' => '38.06',
            'sgst' => '38.06',
            'igst' => '0.00',
            'line_total' => '499.00',
        ]);

        $resolution = app(PlaceOfSupplyResolver::class)->resolveForInvoice($invoice->fresh(['items']));

        $this->assertFalse($resolution->isResolvable());
        $this->assertSame(PlaceOfSupplyResolution::CLASSIFICATION_BLOCKED, $resolution->gstinPosClassification);
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function goodsInvoice(
        string $buyerGstin,
        string $posState,
        string $igst,
        array $structured,
    ): StatutoryInvoice {
        $invoice = $this->serviceInvoice($buyerGstin, $posState, $igst, $structured);
        $invoice->items()->update(['hsn_sac' => '84716050', 'description' => 'Hardware']);

        return $invoice->fresh(['items']);
    }

    /**
     * @param  array<string, mixed>  $structured
     */
    private function serviceInvoice(
        string $buyerGstin,
        string $posState,
        string $igst,
        array $structured,
    ): StatutoryInvoice {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-POS-TEST',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'POS-TEST',
            'idempotency_key' => 'statutory:pos:test',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'buyer_gstin' => $buyerGstin,
            'billing_address' => 'Billing lane',
            'billing_address_structured' => $structured,
            'place_of_supply_state' => $posState,
            'taxable_value' => '422.88',
            'discount' => '0.00',
            'tax_total' => $igst,
            'cgst' => '0.00',
            'sgst' => '0.00',
            'igst' => $igst,
            'rounding' => '0.00',
            'invoice_value' => '499.00',
            'issued_at' => '2026-09-10 10:00:00',
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'RD Service',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '499.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '422.88',
            'tax_total' => $igst,
            'cgst' => '0.00',
            'sgst' => '0.00',
            'igst' => $igst,
            'line_total' => '499.00',
        ]);

        return $invoice->fresh(['items']);
    }
}
