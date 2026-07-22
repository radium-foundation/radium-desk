<?php

namespace App\Support\Repair\Contracts;

use App\Support\Repair\Core\RepairContext;
use App\Support\Repair\Data\RepairCandidate;
use App\Support\Repair\Data\RepairClassification;

abstract class AbstractRepairItemHandler implements RepairItemHandlerInterface
{
    public function isIdempotentNoOp(
        RepairCandidate $candidate,
        RepairClassification $classification,
    ): bool {
        return false;
    }

    public function afterBatch(RepairContext $context): void
    {
        // Optional hook.
    }
}
