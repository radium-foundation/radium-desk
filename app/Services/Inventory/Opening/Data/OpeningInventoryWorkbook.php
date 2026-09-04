<?php

namespace App\Services\Inventory\Opening\Data;

final class OpeningInventoryWorkbook
{
    /**
     * @param  list<OpeningInventoryCountRow>  $openingRows
     * @param  list<OpeningInventorySkuRow>  $skuRows
     * @param  list<OpeningInventoryBranchRow>  $branchRows
     * @param  list<OpeningInventoryIssue>  $parseIssues
     */
    public function __construct(
        public readonly string $checksum,
        public readonly string $filename,
        public readonly array $openingRows,
        public readonly array $skuRows,
        public readonly array $branchRows,
        public readonly array $parseIssues = [],
    ) {}
}
