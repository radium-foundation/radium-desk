<?php

namespace App\Data\Retention;

use Illuminate\Support\Carbon;

readonly class RetentionOutboxPruneSummary
{
    /**
     * @param  array<string, int>  $candidatesByEventType
     */
    public function __construct(
        public Carbon $inspectedAt,
        public bool $dryRun,
        public int $retentionDays,
        public string $cutoffAt,
        public int $candidateCount,
        public int $tableTotalCount,
        public array $candidatesByEventType,
        public int $excludedPending,
        public int $excludedProcessing,
        public int $excludedFailed,
        public int $excludedRecentCompleted,
        public int $excludedNullProcessedAt,
        public int $deletedCount,
        public int $batchesProcessed,
        public int $batchSize,
    ) {}
}
