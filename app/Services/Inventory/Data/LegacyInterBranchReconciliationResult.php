<?php

namespace App\Services\Inventory\Data;

use App\Models\InterBranchReconciliationAudit;
use App\Models\InterBranchTransaction;

final class LegacyInterBranchReconciliationResult
{
    public function __construct(
        public readonly LegacyInterBranchAssessment $assessment,
        public readonly bool $dryRun,
        public readonly ?InterBranchTransaction $transaction = null,
        public readonly ?InterBranchReconciliationAudit $audit = null,
        public readonly bool $idempotentReplay = false,
    ) {}
}
