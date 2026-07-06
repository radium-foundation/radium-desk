<?php

namespace App\Data;

readonly class CustomerWaitingLegacyCleanupSummary
{
    /**
     * @param  array<string, int>  $skipReasons
     */
    public function __construct(
        public int $totalFound,
        public int $casesClosed,
        public int $skipped,
        public int $wouldClose = 0,
        public array $skipReasons = [],
    ) {}
}
