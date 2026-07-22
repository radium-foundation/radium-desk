<?php

namespace App\Support\Repair\Data;

readonly class RepairProgress
{
    public function __construct(
        public int $processed,
        public int $total,
        public int $repaired,
        public int $cleanedUp,
        public int $skipped,
        public int $failed,
        public string $currentSubjectKey = '',
        public string $currentAction = '',
        public string $currentOutcome = '',
    ) {}
}
