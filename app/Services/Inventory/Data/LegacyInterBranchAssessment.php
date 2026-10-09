<?php

namespace App\Services\Inventory\Data;

use App\Enums\LegacyInterBranchCandidateStatus;
use App\Models\InventoryBranch;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;

final class LegacyInterBranchAssessment
{
    /**
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly LegacyInterBranchCandidateStatus $status,
        public readonly InventorySale $sale,
        public readonly ?StatutoryInvoice $invoice,
        public readonly ?InventoryBranch $fromBranch,
        public readonly ?InventoryBranch $toBranch,
        public readonly int $serialCount,
        public readonly array $blockers,
        public readonly ?string $existingIrn = null,
        public readonly ?string $ewayStatus = null,
        public readonly ?string $financeTreatment = null,
        public readonly ?int $financeJournalId = null,
    ) {}

    public function isReconcilable(): bool
    {
        return $this->status === LegacyInterBranchCandidateStatus::Reconcilable;
    }
}
