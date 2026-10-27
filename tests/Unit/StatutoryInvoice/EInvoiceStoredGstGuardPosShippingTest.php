<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\EInvoiceStoredGstGuard;
use Tests\TestCase;

class EInvoiceStoredGstGuardPosShippingTest extends TestCase
{
    public function test_inter_state_pos_shipping_header_snapshot_passes_guard(): void
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
                'line_total' => 36750.01,
            ]),
        ]));

        $this->assertSame([], EInvoiceStoredGstGuard::missingReasons($invoice));
    }

    public function test_inter_state_pos_shipping_with_inconsistent_header_still_fails(): void
    {
        $invoice = new StatutoryInvoice([
            'taxable_value' => 31144.08,
            'shipping_amount' => 1500.00,
            'tax_total' => 5875.93,
            'invoice_value' => 38520.00,
            'rounding' => -0.01,
            'cgst' => 0.00,
            'sgst' => 0.00,
            'igst' => 5800.00,
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
            ]),
        ]));

        $this->assertContains(
            'line_header_gst_mismatch',
            EInvoiceStoredGstGuard::missingReasons($invoice),
        );
    }

    public function test_intra_state_pos_shipping_header_snapshot_passes_guard(): void
    {
        $invoice = new StatutoryInvoice([
            'taxable_value' => 100.00,
            'shipping_amount' => 50.00,
            'tax_total' => 27.00,
            'invoice_value' => 177.00,
            'rounding' => 0.00,
            'cgst' => 13.50,
            'sgst' => 13.50,
            'igst' => 0.00,
        ]);

        $invoice->setRelation('items', collect([
            new StatutoryInvoiceItem([
                'line_no' => 1,
                'gst_percentage' => 18.00,
                'taxable_value' => 100.00,
                'tax_total' => 18.00,
                'cgst' => 9.00,
                'sgst' => 9.00,
                'igst' => 0.00,
                'line_total' => 118.00,
            ]),
        ]));

        $this->assertSame([], EInvoiceStoredGstGuard::missingReasons($invoice));
    }
}
