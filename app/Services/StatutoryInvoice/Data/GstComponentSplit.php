<?php

namespace App\Services\StatutoryInvoice\Data;

final class GstComponentSplit
{
    public function __construct(
        public readonly float $cgst,
        public readonly float $sgst,
        public readonly float $igst,
        public readonly float $cgstRate,
        public readonly float $sgstRate,
        public readonly float $igstRate,
        public readonly bool $intraState,
    ) {}
}
