<?php

namespace App\Services\Inventory\Data;

use App\Enums\LegacyInterBranchCandidateStatus;

final class LegacyInterBranchCandidate
{
    /**
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly int $saleId,
        public readonly ?string $saleNo,
        public readonly ?int $invoiceId,
        public readonly ?string $invoiceNumber,
        public readonly LegacyInterBranchCandidateStatus $status,
        public readonly int $serialCount,
        public readonly array $blockers,
    ) {}
}
