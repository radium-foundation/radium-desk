<?php

namespace App\Data;

readonly class TeamActivitySalesLeadBacklogCleanupSummary
{
    /**
     * @param  list<int>  $candidateIds
     * @param  array<string, int>  $skipReasons
     * @param  array<string, int>  $breakdown
     */
    public function __construct(
        public int $candidatesFound,
        public int $wouldClose,
        public int $casesClosed,
        public int $skipped,
        public int $excludedFromTeamActivityPending,
        public array $candidateIds = [],
        public array $skipReasons = [],
        public array $breakdown = [],
    ) {}
}
