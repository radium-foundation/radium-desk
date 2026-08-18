<?php

namespace App\Data\Retention;

use Illuminate\Support\Carbon;

readonly class RetentionCachePruneSummary
{
    public function __construct(
        public Carbon $inspectedAt,
        public bool $dryRun,
        public int $candidateCount,
        public int $activeCount,
        public int $tableTotalCount,
        public int $estimatedCandidatePayloadBytes,
        public int $deletedCount,
        public int $batchesProcessed,
        public int $batchSize,
    ) {}
}
