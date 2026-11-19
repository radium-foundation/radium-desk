<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use Tests\TestCase;

class EInvoiceIrnPayloadMapperPosShippingCompositeTest extends TestCase
{
    public function test_composite_enabled_single_product_uses_principal_hsn(): void
    {
        config([
            'statutory_invoices.pos_retail_shipping.hsn_sac' => null,
            'statutory_invoices.pos_retail_shipping.composite_supply.enabled' => true,
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($this->singleProductInvoice());

        $this->assertNotContains('missing_pos_shipping_hsn_sac', $payload->gaps);
        $this->assertCount(2, $payload->items);
        $this->assertSame('84716050', $payload->items[1]['hsn_sac']);
        $this->assertSame('18.00', $payload->items[1]['gst_percentage']);
        $this->assertSame('1500.00', $payload->items[1]['taxable_value']);
        $this->assertSame('270.00', $payload->items[1]['igst']);
    }

    public function test_composite_disabled_without_env_hsn_fails_closed(): void
    {
        config([
            'statutory_invoices.pos_retail_shipping.hsn_sac' => null,
            'statutory_invoices.pos_retail_shipping.composite_supply.enabled' => false,
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($this->singleProductInvoice());

        $this->assertContains('missing_pos_shipping_hsn_sac', $payload->gaps);
        $this->assertCount(1, $payload->items);
    }

    public function test_env_hsn_override_takes_precedence_over_composite(): void
    {
        config([
            'statutory_invoices.pos_retail_shipping.hsn_sac' => '998319',
            'statutory_invoices.pos_retail_shipping.composite_supply.enabled' => true,
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($this->singleProductInvoice());

        $this->assertSame('998319', $payload->items[1]['hsn_sac']);
    }

    private function singleProductInvoice(): StatutoryInvoice
    {
        $invoice = new StatutoryInvoice([
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
            'invoice_number' => 'INV-COMPOSITE',
            'issued_at' => now(),
            'place_of_supply_state' => 'Uttar Pradesh',
            'billing_address_structured' => [
                'line1' => 'Buyer line',
                'city' => 'City',
                'state' => 'Uttar Pradesh',
                'pincode' => '201301',
            ],
        ]);

        $invoice->setRelation('items', collect([
            new StatutoryInvoiceItem([
                'line_no' => 1,
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
                'description' => 'Hardware line',
            ]),
        ]));

        return $invoice;
    }
}
