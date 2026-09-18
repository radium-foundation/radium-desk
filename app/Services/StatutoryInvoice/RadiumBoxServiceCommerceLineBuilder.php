<?php

namespace App\Services\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\RadiumBoxServiceCommerceLookup;

/**
 * Builds authoritative RB* service commerce lines from a RadiumBox lookup snapshot.
 */
final class RadiumBoxServiceCommerceLineBuilder
{
    /**
     * @return list<array{
     *     line_no:int,
     *     description:string,
     *     hsn_sac:string,
     *     variant:?string,
     *     qty:int,
     *     unit_price:float,
     *     gst_percentage:float,
     *     taxable_value:float,
     *     tax_total:float,
     *     line_total:float
     * }>
     */
    public function build(RadiumBoxServiceCommerceLookup $lookup): array
    {
        $gstRate = (float) ($lookup->gstPercentage ?? 18.0);
        $catalogSac = (string) ($lookup->catalogHsnSac ?? '998313');
        $serviceDescription = trim((string) ($lookup->serviceDescription ?? ''));
        if ($serviceDescription === '') {
            throw new \InvalidArgumentException('Service description is required for RB* commerce lines.');
        }

        $durationPrice = max(0.0, round((float) ($lookup->durationPrice ?? 0), 2));
        $baseTaxable = round((float) ($lookup->baseTaxableValue ?? $lookup->taxableValue ?? 0), 2);
        $taxTotal = round((float) ($lookup->taxTotal ?? 0), 2);
        $lineTotal = round((float) ($lookup->lineTotal ?? 0), 2);

        if ($durationPrice <= 0) {
            return [[
                'line_no' => 1,
                'description' => $serviceDescription,
                'hsn_sac' => $catalogSac,
                'variant' => null,
                'qty' => 1,
                'unit_price' => $baseTaxable,
                'gst_percentage' => $gstRate,
                'taxable_value' => $baseTaxable,
                'tax_total' => $taxTotal,
                'line_total' => $lineTotal,
            ]];
        }

        $durationTax = round($durationPrice * ($gstRate / 100), 2);
        $baseTax = round($taxTotal - $durationTax, 2);
        $baseLineTotal = round($baseTaxable + $baseTax, 2);
        $durationLineTotal = round($durationPrice + $durationTax, 2);

        return [
            [
                'line_no' => 1,
                'description' => $serviceDescription,
                'hsn_sac' => $catalogSac,
                'variant' => null,
                'qty' => 1,
                'unit_price' => $baseTaxable,
                'gst_percentage' => $gstRate,
                'taxable_value' => $baseTaxable,
                'tax_total' => $baseTax,
                'line_total' => $baseLineTotal,
            ],
            [
                'line_no' => 2,
                'description' => RadiumBoxServiceDurationLineDescriptor::description($lookup->durationType),
                'hsn_sac' => $catalogSac,
                'variant' => RadiumBoxServiceDurationLineDescriptor::variant($lookup->durationType),
                'qty' => 1,
                'unit_price' => $durationPrice,
                'gst_percentage' => $gstRate,
                'taxable_value' => $durationPrice,
                'tax_total' => $durationTax,
                'line_total' => $durationLineTotal,
            ],
        ];
    }
}
