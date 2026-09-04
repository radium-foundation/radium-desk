<?php

namespace App\Services\Inventory\Opening\Data;

final class OpeningInventoryBranchRow
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $code,
        public readonly string $name,
        public readonly string $locationType,
        public readonly string $gstin,
        public readonly string $state,
        public readonly string $city,
        public readonly string $address,
        public readonly ?bool $active,
        public readonly string $notes,
    ) {}
}
