<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\EInvoiceUqcMapper;
use Tests\TestCase;

class EInvoiceCatalogUqcReadinessTest extends TestCase
{
    public function test_packaging_kg_cm_are_not_treated_as_assigned_sales_uqc(): void
    {
        $mapper = new EInvoiceUqcMapper;

        $this->assertSame(['code' => null, 'gap' => 'unsupported_uqc'], $mapper->resolve('kg'));
        $this->assertSame(['code' => null, 'gap' => 'unsupported_uqc'], $mapper->resolve('cm'));
        $this->assertNull($mapper->snapshot(null, null));
    }

    public function test_serialized_hardware_does_not_default_to_pcs_or_nos(): void
    {
        $mapper = new EInvoiceUqcMapper;

        $this->assertSame(['code' => null, 'gap' => 'missing_uqc'], $mapper->resolve(null));
        $this->assertNotSame('PCS', $mapper->snapshot(null, null));
        $this->assertNotSame('NOS', $mapper->snapshot(null, null));
    }

    public function test_p186_did_not_infer_pcs_or_nos_without_owner_decision(): void
    {
        $path = dirname(__DIR__, 3).'/docs/desk-irn-catalog-uqc-readiness-p-07-09-186.md';
        $report = file_get_contents($path);
        $this->assertIsString($report);
        $this->assertStringContainsString('0 of 9 priority SKUs assigned', $report);
        foreach ($this->prioritySkus() as $sku) {
            $this->assertStringContainsString($sku, $report);
        }
        $this->assertStringNotContainsString('Assigned UQC: PCS', $report);
        $this->assertStringNotContainsString('Assigned UQC: NOS', $report);
    }

    public function test_p187_owner_decision_assigns_pcs_only_to_the_nine_hardware_skus(): void
    {
        $path = dirname(__DIR__, 3).'/docs/desk-irn-catalog-uqc-assignment-pcs-p-07-09-187.md';
        $report = file_get_contents($path);
        $this->assertIsString($report);
        $this->assertStringContainsString('9 of 9 priority B2B hardware SKUs assigned `PCS`', $report);
        $this->assertStringContainsString('nine remaining NULL: **0**', $report);
        $this->assertStringContainsString('`NOS` was not assigned', $report);
        foreach ($this->prioritySkus() as $sku) {
            $this->assertStringContainsString($sku, $report);
            $this->assertMatchesRegularExpression('/\| '.$sku.' \| PCS \|/', $report);
        }
        $this->assertStringNotContainsString('| NOS |', $report);
    }

    public function test_stored_catalog_pcs_is_accepted_and_nos_is_not_substituted(): void
    {
        $mapper = new EInvoiceUqcMapper;

        $this->assertSame(['code' => 'PCS', 'gap' => null], $mapper->resolve('PCS'));
        $this->assertSame('PCS', $mapper->snapshot(null, 'PCS'));
        $this->assertNotSame('NOS', $mapper->snapshot(null, 'PCS'));
        $this->assertNull($mapper->snapshot(null, null));
    }

    /**
     * @return list<string>
     */
    private function prioritySkus(): array
    {
        return [
            'RBMFS110L1', 'RBIMSOE3L1', 'RBUGR89GPS', 'RBFM220UFP', 'RBUGR86GPS',
            'RBFUTFS80H', 'RBFUTFS88H', 'RBMFS100L0', 'RBMIS100IR',
        ];
    }
}
