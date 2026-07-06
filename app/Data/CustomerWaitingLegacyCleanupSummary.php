<?php

namespace App\Data;

readonly class CustomerWaitingLegacyCleanupSummary
{
    public function __construct(
        public int $totalFound,
        public int $casesClosed,
        public int $skipped,
    ) {}
}
