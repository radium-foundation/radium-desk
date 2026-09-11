<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
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
