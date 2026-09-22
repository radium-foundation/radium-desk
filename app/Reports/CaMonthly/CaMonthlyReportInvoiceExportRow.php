<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportInvoiceExportRow
{
    /**
     * @param  list<string>  $parentCells
     * @param  list<list<string>>  $detailRows
     */
    public function __construct(
        public readonly array $parentCells,
        public readonly array $detailRows,
        public readonly bool $expandable,
        public readonly float $taxableAmount,
        public readonly float $shippingAmount,
        public readonly float $igst,
        public readonly float $cgst,
        public readonly float $sgst,
        public readonly float $shortExcess,
        public readonly float $invoiceTotal,
    ) {}
}
