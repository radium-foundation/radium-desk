<?php

namespace App\Support\Repair\Contracts;

use App\Support\Repair\Data\RepairVerificationReport;
use App\Support\Repair\Models\SystemRepairBatch;
use App\Support\Repair\Models\SystemRepairItem;

interface RepairVerifierInterface
{
    public function verifyBatch(SystemRepairBatch $batch): RepairVerificationReport;

    /**
     * @return array{ok: bool, message: string}
     */
    public function verifyItem(SystemRepairItem $item): array;
}
