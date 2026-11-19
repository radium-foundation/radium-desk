<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceStoredGstGuard;
use Tests\TestCase;

/**
 * Sanitized production shape for INV-0767941 (statutory id 23163, POS sale 177) — read-only fixture.
 */
class Inv0767941LegacyPosShippingCompatibilityTest extends TestCase
{
    public function test_production_inv0767941_shape_passes_guard_and_mapper(): void
    {
        config(['statutory_invoices.pos_retail_shipping.hsn_sac' => null]);

        $invoice = $this->inv0767941Shape();

        $this->assertSame([], EInvoiceStoredGstGuard::missingReasons($invoice));

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertNotContains('line_header_gst_mismatch', $payload->gaps);
        $this->assertNotContains('invoice_total_mismatch', $payload->gaps);
        $this->assertNotContains('missing_pos_shipping_hsn_sac', $payload->gaps);
        $this->assertCount(2, $payload->items);
        $this->assertSame('84716050', $payload->items[1]['hsn_sac']);
        $this->assertSame('2237.28', $payload->items[1]['taxable_value']);
        $this->assertSame('402.71', $payload->items[1]['igst']);
        $this->assertSame('200237.28', $payload->values['taxable_value']);
    }

    private function inv0767941Shape(): StatutoryInvoice
    {
        $invoice = new StatutoryInvoice([
            'invoice_number' => 'INV-0767941',
            'taxable_value' => 198000.00,
            'shipping_amount' => 2237.28,
            'tax_total' => 36042.71,
            'invoice_value' => 236280.00,
            'rounding' => 0.01,
            'cgst' => 0.00,
            'sgst' => 0.00,
            'igst' => 36042.71,
            'seller_gstin' => '07AAICP1128M1Z9',
            'buyer_gstin' => '27AAACI2085G1ZP',
            'issued_at' => now(),
            'place_of_supply_state' => 'Maharashtra',
            'billing_address_structured' => [
                'line1' => 'Mumbai billing',
                'city' => 'MUMBAI',
                'state' => 'Maharashtra',
                'pincode' => '400063',
            ],
        ]);

        $invoice->setRelation('items', collect([
            new StatutoryInvoiceItem([
                'line_no' => 1,
                'gst_percentage' => 18.00,
                'taxable_value' => 198000.00,
                'tax_total' => 35640.00,
                'cgst' => 0.00,
                'sgst' => 0.00,
                'igst' => 35640.00,
                'qty' => 90,
                'hsn_sac' => '84716050',
                'uqc' => 'PCS',
                'unit_price' => 2200.00,
                'description' => 'RBMFS500FP scanner line',
            ]),
        ]));

        return $invoice;
    }
}
