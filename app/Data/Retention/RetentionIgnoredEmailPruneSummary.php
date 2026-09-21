<?php

namespace App\Data\Retention;

use Illuminate\Support\Carbon;

readonly class RetentionIgnoredEmailPruneSummary
{
    /**
     * @param  array<string, int>  $candidatesByIgnoreReason
     * @param  array<string, int>  $candidatesByAgeBucket
     * @param  array<string, int>  $predicateMatchByIgnoreReason
     * @param  list<int>  $sampleCandidateIds
     */
    public function __construct(
        public Carbon $inspectedAt,
        public bool $dryRun,
        public int $ignoredEmailDays,
        public string $receivedAtCutoff,
        public int $tableTotalCount,
        public int $predicateMatchCount,
        public int $candidateCount,
        public array $candidatesByIgnoreReason,
        public array $candidatesByAgeBucket,
        public array $predicateMatchByIgnoreReason,
        public int $estimatedPayloadBytes,
        public ?string $oldestCandidateReceivedAt,
        public ?string $newestCandidateReceivedAt,
        public array $sampleCandidateIds,
        public ?string $manifestPath,
        public int $manifestIdCount,
        public int $candidatesWithIncidentId,
        public int $candidatesWithOrderId,
        public int $candidatesWithLinkFk,
        public int $candidatesWithOutgoingReplyFk,
        public int $candidatesWithPendingOutbox,
        public int $candidatesWithoutProcessedAt,
        public int $excludedUnknownCustomerCount,
        public int $excludedUnapprovedIgnoreReasonCount,
        public int $deletedCount,
        public int $batchesProcessed,
        public int $batchSize,
        public bool $baselineAnomaly,
        public ?int $baselineCandidateCount,
        public ?int $baselineVariancePercent,
    ) {}

    public function integrityFailureCount(): int
    {
        return $this->candidatesWithIncidentId
            + $this->candidatesWithOrderId
            + $this->candidatesWithLinkFk
            + $this->candidatesWithOutgoingReplyFk
            + $this->candidatesWithPendingOutbox;
    }
}
