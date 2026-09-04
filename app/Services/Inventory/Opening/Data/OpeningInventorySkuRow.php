<?php

namespace App\Services\Inventory\Opening\Data;

final class OpeningInventorySkuRow
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $sku,
        public readonly string $name,
        public readonly string $variantSku,
        public readonly ?bool $serialized,
        public readonly string $hsn,
        public readonly ?string $gstPercentage,
        public readonly ?string $unitPrice,
        public readonly ?string $unitCost,
        public readonly ?bool $active,
        public readonly string $remarks,
    ) {}
}
