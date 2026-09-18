<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\RadiumBoxServiceCommerceLookup;
use App\Services\StatutoryInvoice\RadiumBoxServiceCommerceLineBuilder;
use Tests\TestCase;

class RadiumBoxServiceCommerceLineBuilderTest extends TestCase
{
    private RadiumBoxServiceCommerceLineBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new RadiumBoxServiceCommerceLineBuilder;
    }

    public function test_single_line_when_no_duration_component(): void
    {
        $lookup = $this->lookup(durationPrice: null);

        $lines = $this->builder->build($lookup);

        $this->assertCount(1, $lines);
        $this->assertSame(507.63, $lines[0]['taxable_value']);
        $this->assertSame(91.37, $lines[0]['tax_total']);
        $this->assertSame(599.0, $lines[0]['line_total']);
    }

    public function test_express_duration_adds_second_line_with_authoritative_tax_split(): void
    {
        $lookup = $this->lookup(
            baseTaxable: 507.63,
            taxableValue: 607.63,
            taxTotal: 109.37,
            lineTotal: 717.0,
            durationType: 'express',
            durationPrice: 100.0,
        );

        $lines = $this->builder->build($lookup);

        $this->assertCount(2, $lines);
        $this->assertSame(507.63, $lines[0]['taxable_value']);
        $this->assertSame(91.37, $lines[0]['tax_total']);
        $this->assertSame(599.0, $lines[0]['line_total']);
        $this->assertSame('express', $lines[1]['variant']);
        $this->assertSame(100.0, $lines[1]['taxable_value']);
        $this->assertSame(18.0, $lines[1]['tax_total']);
        $this->assertSame(118.0, $lines[1]['line_total']);
        $this->assertStringContainsString('priority assistance', $lines[1]['description']);
    }

    private function lookup(
        float $baseTaxable = 507.63,
        ?float $taxableValue = null,
        float $taxTotal = 91.37,
        float $lineTotal = 599.0,
        ?string $durationType = null,
        ?float $durationPrice = null,
    ): RadiumBoxServiceCommerceLookup {
        return new RadiumBoxServiceCommerceLookup(
            rdOrderId: 'RB115',
            billingState: 'Bihar',
            placeOfSupplyState: 'Bihar',
            billingAddress: 'Patna, Bihar',
            billingAddressStructured: null,
            buyerGstin: null,
            customerName: 'Buyer',
            customerEmail: 'buyer@example.com',
            customerPhone: '9000000000',
            serialNo: '1234567',
            serviceName: '1 Year Unlimited',
            productName: 'Mantra MIS100',
            serviceDescription: 'Information Technology (IT) Consulting & Support Services (SAC - 998313) - (Sr. No. 1234567) - 1 Year Unlimited',
            catalogHsnSac: '998314',
            taxableValue: $taxableValue ?? $baseTaxable,
            taxTotal: $taxTotal,
            lineTotal: $lineTotal,
            gstPercentage: 18.0,
            orderedAt: '2026-09-10 10:00:00',
            durationType: $durationType,
            durationPrice: $durationPrice,
            baseTaxableValue: $baseTaxable,
        );
    }
}
