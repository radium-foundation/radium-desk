<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Models\CommerceOrderItem;
use App\Services\StatutoryInvoice\StatutoryInvoiceCommerceLinePresentation;
use Tests\TestCase;

class StatutoryInvoiceCommerceLinePresentationTest extends TestCase
{
    private StatutoryInvoiceCommerceLinePresentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presentation = new StatutoryInvoiceCommerceLinePresentation;
    }

    public function test_zero_value_duration_support_line_is_excluded(): void
    {
        $item = $this->item(
            description: 'RD Technical Support — included',
            variant: 'regular',
        );

        $this->assertFalse($this->presentation->includesOnStatutoryInvoice($item));
        $this->assertSame('RD Technical Support — included', $this->presentation->invoiceDescription($item));
    }

    public function test_variant_regular_does_not_replace_service_description(): void
    {
        $item = $this->item(
            description: 'Information technology (IT) consulting & support services (SAC - 998313) - 1 Year Unlimited',
            variant: 'regular',
            taxable: 507.63,
            tax: 91.37,
            total: 599.0,
        );

        $this->assertTrue($this->presentation->includesOnStatutoryInvoice($item));
        $this->assertStringContainsString('1 Year Unlimited', $this->presentation->invoiceDescription($item));
        $this->assertStringNotContainsString('regular', strtolower($this->presentation->invoiceDescription($item)));
    }

    public function test_purchased_express_duration_support_remains_on_invoice(): void
    {
        $item = $this->item(
            description: 'RD Technical Support — priority assistance',
            variant: 'express',
            taxable: 50.0,
            tax: 9.0,
            total: 59.0,
        );

        $this->assertTrue($this->presentation->includesOnStatutoryInvoice($item));
        $this->assertSame('RD Technical Support — priority assistance', $this->presentation->invoiceDescription($item));
    }

    public function test_purchased_amc_line_remains_on_invoice(): void
    {
        $item = $this->item(
            description: 'AMC : 1 Year Standard',
            taxable: 84.75,
            tax: 15.25,
            total: 100.0,
        );

        $this->assertTrue($this->presentation->includesOnStatutoryInvoice($item));
    }

    public function test_not_required_zero_line_is_excluded(): void
    {
        $item = $this->item(description: 'Not Required', variant: 'regular');

        $this->assertFalse($this->presentation->includesOnStatutoryInvoice($item));
    }

    public function test_physical_merchandise_zero_line_is_not_suppressed(): void
    {
        $item = $this->item(
            description: 'Hardware item',
            shippingLineKind: 'physical_merchandise',
        );

        $this->assertTrue($this->presentation->includesOnStatutoryInvoice($item));
    }

    private function item(
        string $description,
        ?string $variant = null,
        ?string $shippingLineKind = null,
        float $taxable = 0.0,
        float $tax = 0.0,
        float $total = 0.0,
    ): CommerceOrderItem {
        return new CommerceOrderItem([
            'description' => $description,
            'variant' => $variant,
            'shipping_line_kind' => $shippingLineKind,
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => $taxable,
            'gst_percentage' => 18,
            'taxable_value' => $taxable,
            'tax_total' => $tax,
            'line_total' => $total,
        ]);
    }
}
