<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportLineRow
{
    /**
     * @param  list<string>  $cells
     */
    public function __construct(
        public readonly array $cells,
        public readonly float $taxableAmount,
        public readonly float $shippingAmount,
        public readonly float $igst,
        public readonly float $cgst,
        public readonly float $sgst,
        public readonly float $shortExcess,
        public readonly float $totalAmount,
        public readonly float $lineDiscount,
        public readonly bool $reconciles,
    ) {}
}
