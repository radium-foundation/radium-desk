<?php

namespace App\Support\Repair\Services;

use App\Support\Repair\Contracts\RepairItemHandlerInterface;
use App\Support\Repair\Data\RepairCandidate;

class RepairSnapshotService
{
    /**
     * @return array<string, mixed>
     */
    public function capture(RepairItemHandlerInterface $handler, RepairCandidate $candidate): array
    {
        return $handler->captureSnapshot($candidate);
    }
}
