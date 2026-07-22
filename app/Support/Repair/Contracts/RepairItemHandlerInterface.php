<?php

namespace App\Support\Repair\Contracts;

use App\Support\Repair\Core\RepairContext;
use App\Support\Repair\Data\RepairActionOutcome;
use App\Support\Repair\Data\RepairCandidate;
use App\Support\Repair\Data\RepairClassification;

interface RepairItemHandlerInterface
{
    public function preview(
        RepairCandidate $candidate,
        RepairClassification $classification,
        RepairContext $context,
    ): RepairActionOutcome;

    public function execute(
        RepairCandidate $candidate,
        RepairClassification $classification,
        RepairContext $context,
    ): RepairActionOutcome;

    /**
     * @return array<string, mixed>
     */
    public function captureSnapshot(RepairCandidate $candidate): array;

    /**
     * @param  array<string, mixed>  $before
     */
    public function restoreSnapshot(
        RepairCandidate $candidate,
        array $before,
        RepairContext $context,
    ): void;

    public function isIdempotentNoOp(
        RepairCandidate $candidate,
        RepairClassification $classification,
    ): bool;

    /**
     * Optional hook after a successful batch execute.
     */
    public function afterBatch(RepairContext $context): void;
}
