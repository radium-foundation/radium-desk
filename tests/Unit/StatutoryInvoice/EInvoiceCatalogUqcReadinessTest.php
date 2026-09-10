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

    public function test_priority_b2b_skus_are_not_hardcoded_to_a_convenience_uqc(): void
    {
        $path = dirname(__DIR__, 3).'/docs/desk-irn-catalog-uqc-readiness-p-07-09-186.md';
        $report = file_get_contents($path);
        $this->assertIsString($report);
        $this->assertStringContainsString('0 of 9 priority SKUs assigned', $report);
        foreach ([
            'RBMFS110L1', 'RBIMSOE3L1', 'RBUGR89GPS', 'RBFM220UFP', 'RBUGR86GPS',
            'RBFUTFS80H', 'RBFUTFS88H', 'RBMFS100L0', 'RBMIS100IR',
        ] as $sku) {
            $this->assertStringContainsString($sku, $report);
        }
        $this->assertStringNotContainsString('Assigned UQC: PCS', $report);
        $this->assertStringNotContainsString('Assigned UQC: NOS', $report);
    }
}
