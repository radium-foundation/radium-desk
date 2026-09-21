<?php

namespace App\Reports\CaMonthly;

final class CaMonthlyReportInvoiceChildRow
{
    public function __construct(
        public readonly string $productName,
        public readonly string $quantity,
        public readonly string $hsnSac,
        public readonly string $taxableAmount,
        public readonly string $igst,
        public readonly string $cgst,
        public readonly string $sgst,
    ) {}
}
