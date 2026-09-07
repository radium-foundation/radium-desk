<?php

namespace App\Services\ChannelIngest\Data;

final class ChannelOrderLineDraft
{
    public function __construct(
        public readonly string $description,
        public readonly int $qty,
        public readonly float $unitPrice,
        public readonly ?string $sku = null,
        public readonly ?string $variant = null,
        public readonly ?string $hsnSac = null,
        public readonly ?float $discount = null,
        public readonly ?float $gstPercentage = null,
        public readonly ?float $taxableValue = null,
        public readonly ?float $taxTotal = null,
        public readonly ?float $cgst = null,
        public readonly ?float $sgst = null,
        public readonly ?float $igst = null,
        public readonly ?float $lineTotal = null,
        public readonly ?string $shippingLineKind = null,
        public readonly ?bool $requiresShipping = null,
        public readonly ?int $productId = null,
        public readonly ?int $modelId = null,
        public readonly ?string $catalogSku = null,
        public readonly ?int $rdserviceid = null,
        public readonly ?int $amcid = null,
        public readonly ?int $otgid = null,
    ) {}
}
