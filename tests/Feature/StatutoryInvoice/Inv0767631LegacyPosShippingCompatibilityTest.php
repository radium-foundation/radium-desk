<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceStoredGstGuard;
use Tests\TestCase;

/**
 * Safe fixture mirroring production INV-0767631 (statutory id 14910): header-only shipping,
 * single hardware line, inter-state IGST — no production data mutation.
 */
class Inv0767631LegacyPosShippingCompatibilityTest extends TestCase
{
    public function test_legacy_header_only_shipping_reconciles_for_einvoice_without_env_hsn_override(): void
    {
        config([
            'statutory_invoices.pos_retail_shipping.hsn_sac' => null,
        ]);

        $invoice = $this->legacyInv0767631Shape();

        $this->assertSame([], EInvoiceStoredGstGuard::missingReasons($invoice));

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertNotContains('line_header_gst_mismatch', $payload->gaps);
        $this->assertNotContains('invoice_total_mismatch', $payload->gaps);
        $this->assertNotContains('missing_pos_shipping_hsn_sac', $payload->gaps);
        $this->assertCount(2, $payload->items);
        $this->assertSame('84716050', $payload->items[1]['hsn_sac']);
        $this->assertSame('1500.00', $payload->items[1]['taxable_value']);
        $this->assertSame('270.00', $payload->items[1]['igst']);
        $this->assertSame('32644.08', $payload->values['taxable_value']);
    }

    private function legacyInv0767631Shape(): StatutoryInvoice
    {
        $invoice = new StatutoryInvoice([
            'invoice_number' => 'INV-0767631',
            'taxable_value' => 31144.08,
            'shipping_amount' => 1500.00,
            'tax_total' => 5875.93,
            'invoice_value' => 38520.00,
            'rounding' => -0.01,
            'cgst' => 0.00,
            'sgst' => 0.00,
            'igst' => 5875.93,
            'seller_gstin' => '07AAICP1128M1Z9',
            'buyer_gstin' => '09AAAAA0000A1Z5',
            'issued_at' => now(),
            'place_of_supply_state' => 'Uttar Pradesh',
            'billing_address_structured' => [
                'line1' => 'Buyer',
                'city' => 'Noida',
                'state' => 'Uttar Pradesh',
                'pincode' => '201301',
            ],
        ]);

        $invoice->setRelation('items', collect([
            new StatutoryInvoiceItem([
                'line_no' => 1,
                'sku' => null,
                'gst_percentage' => 18.00,
                'taxable_value' => 31144.08,
                'tax_total' => 5605.93,
                'cgst' => 0.00,
                'sgst' => 0.00,
                'igst' => 5605.93,
                'qty' => 3,
                'hsn_sac' => '84716050',
                'uqc' => 'PCS',
                'unit_price' => 10381.36,
                'description' => 'POS hardware line',
            ]),
        ]));

        return $invoice;
    }
}
