<?php

namespace App\Services\Inventory\Opening\Data;

use App\Enums\InventorySerialCondition;
use App\Enums\InventorySerialStatus;
use Carbon\CarbonInterface;

final class OpeningInventoryCountRow
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly ?CarbonInterface $openingDate,
        public readonly string $branchCode,
        public readonly string $locationType,
        public readonly string $sku,
        public readonly string $variantSku,
        public readonly string $productName,
        public readonly ?bool $serializedHint,
        public readonly string $rawCondition,
        public readonly ?InventorySerialCondition $condition,
        public readonly string $rawStockStatus,
        public readonly ?InventorySerialStatus $stockStatus,
        public readonly string $serialNumber,
        public readonly ?int $quantity,
        public readonly ?string $unitCost,
        public readonly ?string $sellingPrice,
        public readonly ?string $gstPercentage,
        public readonly string $hsn,
        public readonly string $countedBy,
        public readonly string $remarks,
        public readonly string $rowIssues,
    ) {}

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            'opening',
            $this->rowNumber,
            $this->sku,
            $this->variantSku,
            $this->branchCode,
            $this->serialNumber,
            (string) ($this->quantity ?? ''),
        ]));
    }

    public function appliedIdentity(): string
    {
        if ($this->serialNumber !== '') {
            return 'serial:'.$this->serialNumber;
        }

        return 'qty:'.hash('sha256', implode('|', [
            $this->sku,
            $this->variantSku,
            $this->branchCode,
            $this->openingDate?->toDateString() ?? '',
            $this->stockStatus?->value ?? '',
            $this->condition?->value ?? '',
            (string) ($this->quantity ?? ''),
            $this->unitCost ?? '',
            $this->remarks,
        ]));
    }
}
