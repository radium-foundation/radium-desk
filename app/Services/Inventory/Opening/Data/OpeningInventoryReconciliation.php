<?php

namespace App\Services\Inventory\Opening\Data;

use App\Enums\InventorySerialStatus;

final class OpeningInventoryReconciliation
{
    /**
     * @param  list<array{branch: string, available_serials: int, damaged_serials: int, quantity_units: int}>  $byBranch
     * @param  list<array{sku: string, available_serials: int, damaged_serials: int, quantity_units: int}>  $bySku
     */
    public function __construct(
        public readonly int $availableSerials,
        public readonly int $damagedSerials,
        public readonly int $quantityUnits,
        public readonly array $byBranch,
        public readonly array $bySku,
    ) {}

    /**
     * Totals from rows that have no blocking opening-sheet issues.
     * Does not invent quantities.
     *
     * @param  list<OpeningInventoryCountRow>  $rows
     * @param  list<OpeningInventoryIssue>  $issues
     */
    public static function fromRows(array $rows, array $issues): self
    {
        $blocked = [];
        foreach ($issues as $issue) {
            if ($issue->blocking && $issue->sheet === 'Inventory Opening' && $issue->rowNumber > 0) {
                $blocked[$issue->rowNumber] = true;
            }
        }

        $byBranch = [];
        $bySku = [];
        $availableSerials = 0;
        $damagedSerials = 0;
        $quantityUnits = 0;

        foreach ($rows as $row) {
            if (isset($blocked[$row->rowNumber])) {
                continue;
            }

            $branch = $row->branchCode !== '' ? $row->branchCode : '(missing)';
            $sku = $row->sku !== '' ? $row->sku : '(missing)';
            $byBranch[$branch] ??= ['branch' => $branch, 'available_serials' => 0, 'damaged_serials' => 0, 'quantity_units' => 0];
            $bySku[$sku] ??= ['sku' => $sku, 'available_serials' => 0, 'damaged_serials' => 0, 'quantity_units' => 0];

            if ($row->serialNumber !== '') {
                if ($row->stockStatus === InventorySerialStatus::Damaged) {
                    $damagedSerials++;
                    $byBranch[$branch]['damaged_serials']++;
                    $bySku[$sku]['damaged_serials']++;
                } else {
                    $availableSerials++;
                    $byBranch[$branch]['available_serials']++;
                    $bySku[$sku]['available_serials']++;
                }
            } else {
                $qty = $row->quantity ?? 0;
                $quantityUnits += $qty;
                $byBranch[$branch]['quantity_units'] += $qty;
                $bySku[$sku]['quantity_units'] += $qty;
            }
        }

        ksort($byBranch);
        ksort($bySku);

        return new self(
            availableSerials: $availableSerials,
            damagedSerials: $damagedSerials,
            quantityUnits: $quantityUnits,
            byBranch: array_values($byBranch),
            bySku: array_values($bySku),
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function fromArray(?array $payload): self
    {
        if ($payload === null) {
            return new self(0, 0, 0, [], []);
        }

        return new self(
            availableSerials: (int) ($payload['available_serials'] ?? 0),
            damagedSerials: (int) ($payload['damaged_serials'] ?? 0),
            quantityUnits: (int) ($payload['quantity_units'] ?? 0),
            byBranch: is_array($payload['by_branch'] ?? null) ? $payload['by_branch'] : [],
            bySku: is_array($payload['by_sku'] ?? null) ? $payload['by_sku'] : [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'available_serials' => $this->availableSerials,
            'damaged_serials' => $this->damagedSerials,
            'quantity_units' => $this->quantityUnits,
            'by_branch' => $this->byBranch,
            'by_sku' => $this->bySku,
        ];
    }
}
