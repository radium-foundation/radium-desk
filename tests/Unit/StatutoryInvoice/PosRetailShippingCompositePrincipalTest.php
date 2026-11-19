<?php

namespace Tests\Unit\StatutoryInvoice;

use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\PosRetailShippingCompositePrincipal;
use Tests\TestCase;

class PosRetailShippingCompositePrincipalTest extends TestCase
{
    public function test_resolves_single_product_principal(): void
    {
        $invoice = $this->invoiceWithLines([
            $this->line(hsn: '84716050', rate: 18.0, taxable: 1000.0),
        ]);

        $this->assertSame([], PosRetailShippingCompositePrincipal::unresolvedReasons($invoice));
        $this->assertSame([
            'hsn_sac' => '84716050',
            'gst_percentage' => 18.0,
        ], PosRetailShippingCompositePrincipal::resolve($invoice));
    }

    public function test_multiple_lines_same_hsn_and_rate(): void
    {
        $invoice = $this->invoiceWithLines([
            $this->line(hsn: '84716050', rate: 18.0, taxable: 600.0),
            $this->line(hsn: '84716050', rate: 18.0, taxable: 400.0),
        ]);

        $this->assertSame([], PosRetailShippingCompositePrincipal::unresolvedReasons($invoice));
        $this->assertSame('84716050', PosRetailShippingCompositePrincipal::resolve($invoice)['hsn_sac']);
    }

    public function test_same_rate_different_hsn_uses_predominant_taxable_value(): void
    {
        $invoice = $this->invoiceWithLines([
            $this->line(hsn: '84716050', rate: 18.0, taxable: 2000.0),
            $this->line(hsn: '85176290', rate: 18.0, taxable: 50.0),
        ]);

        $this->assertSame([], PosRetailShippingCompositePrincipal::unresolvedReasons($invoice));
        $this->assertSame('84716050', PosRetailShippingCompositePrincipal::resolve($invoice)['hsn_sac']);
    }

    public function test_mixed_gst_rates_fail_closed(): void
    {
        $invoice = $this->invoiceWithLines([
            $this->line(hsn: '84716050', rate: 18.0, taxable: 1000.0),
            $this->line(hsn: '998313', rate: 12.0, taxable: 200.0),
        ]);

        $this->assertSame(['pos_shipping_composite_mixed_gst_rates'], PosRetailShippingCompositePrincipal::unresolvedReasons($invoice));
        $this->assertNull(PosRetailShippingCompositePrincipal::resolve($invoice));
    }

    public function test_tied_taxable_different_hsn_fails_closed(): void
    {
        $invoice = $this->invoiceWithLines([
            $this->line(hsn: '84716050', rate: 18.0, taxable: 500.0),
            $this->line(hsn: '85176290', rate: 18.0, taxable: 500.0),
        ]);

        $this->assertSame(['pos_shipping_composite_principal_unresolved'], PosRetailShippingCompositePrincipal::unresolvedReasons($invoice));
    }

    /**
     * @param  list<StatutoryInvoiceItem>  $items
     */
    private function invoiceWithLines(array $items): StatutoryInvoice
    {
        $invoice = new StatutoryInvoice;
        $invoice->setRelation('items', collect($items));

        return $invoice;
    }

    private function line(string $hsn, float $rate, float $taxable): StatutoryInvoiceItem
    {
        return new StatutoryInvoiceItem([
            'hsn_sac' => $hsn,
            'gst_percentage' => $rate,
            'taxable_value' => $taxable,
            'sku' => 'HW-SKU',
        ]);
    }
}
