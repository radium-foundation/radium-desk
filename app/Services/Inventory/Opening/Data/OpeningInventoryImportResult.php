<?php

namespace App\Services\Inventory\Opening\Data;

use App\Models\InventoryOpeningImportBatch;

final class OpeningInventoryImportResult
{
    /**
     * @param  list<OpeningInventoryIssue>  $issues
     */
    public function __construct(
        public readonly InventoryOpeningImportBatch $batch,
        public readonly bool $canApply,
        public readonly bool $alreadyApplied,
        public readonly int $openingRows,
        public readonly int $validRows,
        public readonly int $invalidRows,
        public readonly int $skuRows,
        public readonly int $skusCreated,
        public readonly int $variantsCreated,
        public readonly int $rowsApplied,
        public readonly array $issues,
        public readonly OpeningInventoryReconciliation $reconciliation,
    ) {}

    public function blockingIssues(): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (OpeningInventoryIssue $issue): bool => $issue->blocking,
        ));
    }
}
