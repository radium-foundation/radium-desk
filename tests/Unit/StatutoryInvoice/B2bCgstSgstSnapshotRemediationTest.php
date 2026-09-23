<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\B2bCgstSgstSnapshotRemediation;
use App\Services\StatutoryInvoice\B2cCgstSgstSnapshotRemediation;
use App\Services\StatutoryInvoice\IntraStateCgstSgstRules;
use Tests\TestCase;

class B2bCgstSgstSnapshotRemediationTest extends TestCase
{
    public function test_target_allowlist_matches_b2b_exclusions(): void
    {
        $this->assertSame(
            B2bCgstSgstSnapshotRemediation::TARGET_INVOICE_NUMBERS,
            B2cCgstSgstSnapshotRemediation::B2B_EXCLUDED_INVOICE_NUMBERS,
        );
    }

    public function test_propose_correction_matches_expected_rd3300_shape(): void
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

    public function test_propose_correction_matches_expected_multi_line_b2b_shape(): void
    {
        $invoice = $this->invoiceWithLines([
            ['taxable' => 422.88, 'tax' => 76.12, 'cgst' => 38.06, 'sgst' => 38.06],
            ['taxable' => 169.49, 'tax' => 30.51, 'cgst' => 15.26, 'sgst' => 15.25],
        ], headerCgst: 53.32, headerSgst: 53.31, taxTotal: 106.63);

        $proposal = B2cCgstSgstSnapshotRemediation::proposeCorrection($invoice);

        $this->assertNotNull($proposal);
        $this->assertSame(53.32, $proposal['header_cgst']);
        $this->assertSame(53.32, $proposal['header_sgst']);
        $this->assertSame([3807, 1525], $proposal['line_halves']);
        $this->assertSame(38.07, IntraStateCgstSgstRules::fromPaise(3807));
        $this->assertSame(15.25, IntraStateCgstSgstRules::fromPaise(1525));
    }

    public function test_manifest_hash_is_stable_without_embedded_hash_field(): void
    {
        $manifest = [
            'prompt_id' => B2bCgstSgstSnapshotRemediation::PROMPT_ID,
            'algorithm' => 'IntraStateCgstSgstRules@v4.0.127',
            'generated_at' => '2026-09-23T15:30:00+05:30',
            'invoices' => [],
        ];

        $hash = B2bCgstSgstSnapshotRemediation::hashManifest($manifest);
        $manifest['manifest_sha256'] = $hash;

        $this->assertSame($hash, B2bCgstSgstSnapshotRemediation::hashManifest($manifest));
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
