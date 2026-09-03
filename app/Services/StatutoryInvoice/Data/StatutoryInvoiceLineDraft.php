<?php

namespace App\Services\StatutoryInvoice\Data;

final class StatutoryInvoiceLineDraft
{
    public function __construct(
        public readonly string $description,
        public readonly int $qty,
        public readonly float $unitPrice,
        public readonly float $gstPercentage,
        public readonly float $taxTotal,
        public readonly float $lineTotal,
        public readonly float $taxableValue,
        public readonly float $discount = 0,
        public readonly ?string $sku = null,
        public readonly ?string $hsnSac = null,
        public readonly ?float $cgst = null,
        public readonly ?float $sgst = null,
        public readonly ?float $igst = null,
    ) {}
}
