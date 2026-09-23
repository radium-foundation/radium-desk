<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\B2cCgstSgstSnapshotRemediation;
use Tests\TestCase;

class B2cCgstSgstSnapshotRemediationTest extends TestCase
{
    public function test_propose_correction_for_single_line_odd_paise_tax(): void
    {
        $invoice = $this->invoiceWithLines([
            ['taxable' => 534.75, 'tax' => 96.25, 'cgst' => 48.13, 'sgst' => 48.12],
        ], headerCgst: 48.13, headerSgst: 48.12);

        $proposal = B2cCgstSgstSnapshotRemediation::proposeCorrection($invoice);

        $this->assertNotNull($proposal);
        $this->assertSame(48.13, $proposal['header_cgst']);
        $this->assertSame(48.13, $proposal['header_sgst']);
        $this->assertSame([4813], $proposal['line_halves']);
    }

    public function test_propose_correction_for_rd3300_shape_without_zero_line(): void
    {
        $invoice = $this->invoiceWithLines([
            ['taxable' => 421.19, 'tax' => 75.81, 'cgst' => 37.91, 'sgst' => 37.90],
            ['taxable' => 84.75, 'tax' => 15.25, 'cgst' => 7.63, 'sgst' => 7.62],
        ], headerCgst: 45.54, headerSgst: 45.52, taxTotal: 91.06);

        $proposal = B2cCgstSgstSnapshotRemediation::proposeCorrection($invoice);

        $this->assertNotNull($proposal);
        $this->assertSame(45.53, $proposal['header_cgst']);
        $this->assertSame(45.53, $proposal['header_sgst']);
        $this->assertSame([3791, 762], $proposal['line_halves']);
    }

    public function test_propose_correction_returns_null_for_zero_line_allocation(): void
    {
        $invoice = $this->invoiceWithLines([
            ['taxable' => 421.19, 'tax' => 75.81, 'cgst' => 37.91, 'sgst' => 37.90],
            ['taxable' => 84.75, 'tax' => 15.25, 'cgst' => 7.63, 'sgst' => 7.62],
            ['taxable' => 0.0, 'tax' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0],
        ], headerCgst: 45.54, headerSgst: 45.52, taxTotal: 91.06);

        $this->assertNull(B2cCgstSgstSnapshotRemediation::proposeCorrection($invoice));
    }

    public function test_manifest_hash_is_stable_without_embedded_hash_field(): void
    {
        $manifest = [
            'prompt_id' => 'RadiumDesk-P-23-09-18',
            'algorithm' => 'IntraStateCgstSgstRules@v4.0.127',
            'generated_at' => '2026-09-23T15:00:00+05:30',
            'invoices' => [],
        ];

        $hash = B2cCgstSgstSnapshotRemediation::hashManifest($manifest);
        $manifest['manifest_sha256'] = $hash;

        $this->assertSame($hash, B2cCgstSgstSnapshotRemediation::hashManifest($manifest));
    }

    /**
     * @param  list<array{taxable: float, tax: float, cgst: float, sgst: float}>  $lines
     */
    private function invoiceWithLines(
        array $lines,
        float $headerCgst,
        float $headerSgst,
        ?float $taxTotal = null,
    ): StatutoryInvoice {
        $taxTotal ??= array_sum(array_column($lines, 'tax'));
        $taxableTotal = array_sum(array_column($lines, 'taxable'));

        $invoice = new StatutoryInvoice([
            'invoice_number' => 'INV-TEST',
            'taxable_value' => $taxableTotal,
            'tax_total' => $taxTotal,
            'invoice_value' => $taxableTotal + $taxTotal,
            'cgst' => $headerCgst,
            'sgst' => $headerSgst,
            'igst' => 0.0,
            'shipping_amount' => 0.0,
        ]);

        $items = [];
        foreach ($lines as $index => $line) {
            $items[] = new StatutoryInvoiceItem([
                'line_no' => $index + 1,
                'gst_percentage' => 18.0,
                'taxable_value' => $line['taxable'],
                'tax_total' => $line['tax'],
                'cgst' => $line['cgst'],
                'sgst' => $line['sgst'],
                'igst' => 0.0,
                'line_total' => $line['taxable'] + $line['tax'],
            ]);
        }

        $invoice->setRelation('items', collect($items));

        return $invoice;
    }
}
