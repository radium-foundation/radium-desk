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

    public function withTaxComponents(float $cgst, float $sgst, float $igst): self
    {
        return new self(
            description: $this->description,
            qty: $this->qty,
            unitPrice: $this->unitPrice,
            gstPercentage: $this->gstPercentage,
            taxTotal: $this->taxTotal,
            lineTotal: $this->lineTotal,
            taxableValue: $this->taxableValue,
            discount: $this->discount,
            sku: $this->sku,
            hsnSac: $this->hsnSac,
            cgst: $cgst,
            sgst: $sgst,
            igst: $igst,
        );
    }
}
