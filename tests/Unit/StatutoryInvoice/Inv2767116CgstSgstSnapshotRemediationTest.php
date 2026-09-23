<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\B2cCgstSgstSnapshotRemediation;
use App\Services\StatutoryInvoice\IntraStateCgstSgstRules;
use App\Services\StatutoryInvoice\Inv2767116CgstSgstSnapshotRemediation;
use Tests\TestCase;

class Inv2767116CgstSgstSnapshotRemediationTest extends TestCase
{
    public function test_propose_correction_matches_expected_inv_2767116_values(): void
    {
        $invoice = $this->invoiceWithLines([
            ['taxable' => 421.19, 'tax' => 75.81, 'cgst' => 37.91, 'sgst' => 37.90],
            ['taxable' => 84.75, 'tax' => 15.25, 'cgst' => 7.63, 'sgst' => 7.62],
            ['taxable' => 0.0, 'tax' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0],
        ], headerCgst: 45.54, headerSgst: 45.52, taxTotal: 91.06);

        $proposal = B2cCgstSgstSnapshotRemediation::proposeCorrection($invoice);

        $this->assertNotNull($proposal);
        $this->assertSame(45.53, $proposal['header_cgst']);
        $this->assertSame(45.53, $proposal['header_sgst']);
        $this->assertSame([3791, 762, 0], $proposal['line_halves']);
        $this->assertSame(37.91, IntraStateCgstSgstRules::fromPaise(3791));
        $this->assertSame(7.62, IntraStateCgstSgstRules::fromPaise(762));
    }

    public function test_manifest_hash_is_stable_without_embedded_hash_field(): void
    {
        $manifest = [
            'prompt_id' => Inv2767116CgstSgstSnapshotRemediation::PROMPT_ID,
            'algorithm' => 'IntraStateCgstSgstRules@v4.0.127+zero-line-hardening',
            'generated_at' => '2026-09-23T15:45:00+05:30',
            'invoices' => [],
        ];

        $hash = Inv2767116CgstSgstSnapshotRemediation::hashManifest($manifest);
        $manifest['manifest_sha256'] = $hash;

        $this->assertSame($hash, Inv2767116CgstSgstSnapshotRemediation::hashManifest($manifest));
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
            'invoice_number' => Inv2767116CgstSgstSnapshotRemediation::TARGET_INVOICE_NUMBER,
            'source_id' => Inv2767116CgstSgstSnapshotRemediation::TARGET_SOURCE_ID,
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
