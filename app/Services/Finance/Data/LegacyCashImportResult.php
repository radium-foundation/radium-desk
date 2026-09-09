<?php

namespace App\Services\Finance\Data;

use App\Support\Finance\LegacyCashContract;

readonly class LegacyCashImportResult
{
    /**
     * @param  list<int>  $presentExcludedIds
     * @param  list<string>  $conflicts
     * @param  array<string, int>  $unmappedAdminCounts
     */
    public function __construct(
        public int $sourceRows,
        public int $imported,
        public int $skippedExisting,
        public int $credits,
        public int $debits,
        public string $creditTotal,
        public string $debitTotal,
        public string $net,
        public int $mappedRows,
        public int $unmappedRows,
        public int $reviewRows,
        public array $presentExcludedIds,
        public array $conflicts,
        public array $unmappedAdminCounts,
        public bool $dryRun,
        public ?string $maxCreatedAt,
    ) {}

    public function matchesApprovedTotals(): bool
    {
        return $this->sourceRows === LegacyCashContract::EXPECTED_ROWS
            && $this->credits === LegacyCashContract::EXPECTED_CREDITS
            && $this->debits === LegacyCashContract::EXPECTED_DEBITS
            && $this->creditTotal === LegacyCashContract::EXPECTED_CREDIT_TOTAL
            && $this->debitTotal === LegacyCashContract::EXPECTED_DEBIT_TOTAL
            && $this->net === LegacyCashContract::EXPECTED_NET
            && $this->presentExcludedIds === []
            && $this->conflicts === [];
    }
}
