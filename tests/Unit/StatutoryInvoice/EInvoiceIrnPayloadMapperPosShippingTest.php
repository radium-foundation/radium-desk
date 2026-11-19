<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use Tests\TestCase;

class EInvoiceIrnPayloadMapperPosShippingTest extends TestCase
{
    public function test_pos_shipping_appends_irn_line_and_assessable_value_when_hsn_configured(): void
    {
        config([
            'statutory_invoices.pos_retail_shipping.hsn_sac' => '996511',
        ]);

        $invoice = $this->invoiceShape();

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertNotContains('line_header_gst_mismatch', $payload->gaps);
        $this->assertNotContains('invoice_total_mismatch', $payload->gaps);
        $this->assertNotContains('missing_pos_shipping_hsn_sac', $payload->gaps);
        $this->assertSame('32644.08', $payload->values['taxable_value']);
        $this->assertCount(2, $payload->items);
        $this->assertSame('996511', $payload->items[1]['hsn_sac']);
        $this->assertSame('1500.00', $payload->items[1]['taxable_value']);
    }

    public function test_pos_shipping_without_hsn_config_fails_closed(): void
    {
        config([
            'statutory_invoices.pos_retail_shipping.hsn_sac' => null,
        ]);

        $invoice = $this->invoiceShape();
        $invoice->items->first()->hsn_sac = '';

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertContains('missing_pos_shipping_hsn_sac', $payload->gaps);
    }

    public function test_pos_shipping_uses_incidental_product_hsn_when_env_override_absent(): void
    {
        config([
            'statutory_invoices.pos_retail_shipping.hsn_sac' => null,
            'statutory_invoices.pos_retail_shipping.description' => 'Shipping & Delivery Charges',
        ]);

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($this->invoiceShape());

        $this->assertNotContains('missing_pos_shipping_hsn_sac', $payload->gaps);
        $this->assertSame('84716050', $payload->items[1]['hsn_sac']);
        $this->assertSame('Shipping & Delivery Charges', $payload->items[1]['description']);
    }

    public function test_repeated_map_does_not_accumulate_shipping_lines(): void
    {
        config([
            'statutory_invoices.pos_retail_shipping.hsn_sac' => '996511',
        ]);

        $mapper = app(EInvoiceIrnPayloadMapper::class);
        $invoice = $this->invoiceShape();

        $first = $mapper->map($invoice);
        $second = $mapper->map($invoice);

        $this->assertCount(2, $first->items);
        $this->assertCount(2, $second->items);
        $this->assertSame($first->items[1]['taxable_value'], $second->items[1]['taxable_value']);
    }

    public function test_mapper_skips_synthetic_line_when_stored_lines_already_include_shipping_tax(): void
    {
        config([
            'statutory_invoices.pos_retail_shipping.hsn_sac' => '996511',
        ]);

        $invoice = $this->invoiceShape();
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
            new StatutoryInvoiceItem([
                'line_no' => 2,
                'gst_percentage' => 18.00,
                'taxable_value' => 1500.00,
                'tax_total' => 270.00,
                'cgst' => 0.00,
                'sgst' => 0.00,
                'igst' => 270.00,
                'qty' => 1,
                'hsn_sac' => '996511',
                'uqc' => 'NOS',
                'unit_price' => 1500.00,
                'description' => 'Shipping / Freight',
            ]),
        ]));

        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertCount(2, $payload->items);
        $this->assertSame('31144.08', $payload->values['taxable_value']);
    }

    private function invoiceShape(): StatutoryInvoice
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
            'invoice_number' => 'INV-SHIP-TEST',
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
