<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\EInvoiceStoredGstGuard;
use App\Services\StatutoryInvoice\IntraStateCgstSgstRules;
use Tests\TestCase;

class EInvoiceStoredGstGuardIntraStateTest extends TestCase
{
    public function test_equal_intra_state_components_pass_guard_with_one_paisa_invoice_drift(): void
    {
        $invoice = $this->invoice(
            taxTotal: 96.25,
            cgst: 48.13,
            sgst: 48.13,
            igst: 0.0,
            items: [[
                'taxable_value' => 534.75,
                'tax_total' => 96.25,
                'cgst' => 48.13,
                'sgst' => 48.13,
                'igst' => 0.0,
            ]],
        );

        $this->assertSame([], EInvoiceStoredGstGuard::missingReasons($invoice));
    }

    public function test_unequal_intra_state_components_fail_guard(): void
    {
        $invoice = $this->invoice(
            taxTotal: 96.25,
            cgst: 48.13,
            sgst: 48.12,
            igst: 0.0,
            items: [[
                'taxable_value' => 534.75,
                'tax_total' => 96.25,
                'cgst' => 48.13,
                'sgst' => 48.12,
                'igst' => 0.0,
            ]],
        );

        $this->assertContains(
            IntraStateCgstSgstRules::INTRA_STATE_CGST_SGST_UNEQUAL,
            EInvoiceStoredGstGuard::missingReasons($invoice),
        );
    }

    public function test_multi_line_inv_0767278_shape_passes_with_equal_halves(): void
    {
        $invoice = $this->invoice(
            taxTotal: 91.06,
            cgst: 45.54,
            sgst: 45.54,
            igst: 0.0,
            taxable: 505.94,
            invoiceValue: 597.0,
            items: [
                [
                    'taxable_value' => 421.19,
                    'tax_total' => 75.81,
                    'cgst' => 37.91,
                    'sgst' => 37.91,
                    'igst' => 0.0,
                    'line_total' => 497.0,
                ],
                [
                    'taxable_value' => 84.75,
                    'tax_total' => 15.25,
                    'cgst' => 7.63,
                    'sgst' => 7.63,
                    'igst' => 0.0,
                    'line_total' => 100.0,
                ],
            ],
        );

        $this->assertSame([], EInvoiceStoredGstGuard::missingReasons($invoice));
    }

    /**
     * @param  list<array<string, float>>  $items
     */
    private function invoice(
        float $taxTotal,
        float $cgst,
        float $sgst,
        float $igst,
        array $items,
        float $taxable = 100.0,
        float $invoiceValue = 118.0,
    ): StatutoryInvoice {
        $invoice = new StatutoryInvoice([
            'taxable_value' => $taxable,
            'tax_total' => $taxTotal,
            'invoice_value' => $invoiceValue,
            'rounding' => round($invoiceValue - $taxable - $taxTotal, 2),
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
        ]);

        $invoice->setRelation('items', collect($items)->values()->map(
            fn (array $item, int $index): StatutoryInvoiceItem => new StatutoryInvoiceItem([
                'line_no' => $index + 1,
                'gst_percentage' => 18.0,
                'taxable_value' => $item['taxable_value'],
                'tax_total' => $item['tax_total'],
                'cgst' => $item['cgst'],
                'sgst' => $item['sgst'],
                'igst' => $item['igst'],
                'line_total' => $item['line_total'] ?? round(
                    $item['taxable_value'] + $item['tax_total'],
                    2,
                ),
            ]),
        ));

        return $invoice;
    }
}
