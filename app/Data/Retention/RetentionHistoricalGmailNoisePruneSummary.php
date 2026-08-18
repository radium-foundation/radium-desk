<?php

namespace App\Data\Retention;

use Illuminate\Support\Carbon;

readonly class RetentionHistoricalGmailNoisePruneSummary
{
    /**
     * @param  array<string, int>  $candidatesByIgnoreReason
     * @param  array<string, int>  $candidatesByReceivedMonth
     * @param  list<int>  $sampleCandidateIds
     */
    public function __construct(
        public Carbon $inspectedAt,
        public bool $dryRun,
        public string $receivedAtCutoff,
        public int $tableTotalCount,
        public int $candidateCount,
        public array $candidatesByIgnoreReason,
        public array $candidatesByReceivedMonth,
        public int $estimatedPayloadBytes,
        public array $sampleCandidateIds,
        public int $candidatesWithIncidentId,
        public int $candidatesWithOrderId,
        public int $candidatesWithLinkFk,
        public int $candidatesWithOutgoingReplyFk,
        public int $excludedUnknownCustomerCount,
        public int $excludedExplicitMessageIdCount,
        public int $deletedCount,
        public int $batchesProcessed,
        public int $batchSize,
    ) {}
}
