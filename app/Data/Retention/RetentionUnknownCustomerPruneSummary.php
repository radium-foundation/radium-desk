<?php

namespace App\Data\Retention;

use Illuminate\Support\Carbon;

readonly class RetentionUnknownCustomerPruneSummary
{
    /**
     * @param  array<string, int>  $candidatesByAgeBucket
     * @param  list<int>  $sampleCandidateIds
     */
    public function __construct(
        public Carbon $inspectedAt,
        public bool $dryRun,
        public int $unknownCustomerDays,
        public string $receivedAtCutoff,
        public int $tableTotalCount,
        public int $unknownCustomerIgnoredTotal,
        public int $candidateCount,
        public array $candidatesByAgeBucket,
        public int $estimatedPayloadBytes,
        public ?string $oldestCandidateReceivedAt,
        public ?string $newestCandidateReceivedAt,
        public ?int $oldestCandidateId,
        public ?int $newestCandidateId,
        public array $sampleCandidateIds,
        public ?string $manifestPath,
        public int $manifestIdCount,
        public ?string $manifestSha256,
        public int $candidatesWithIncidentId,
        public int $candidatesWithOrderId,
        public int $candidatesWithLinkFk,
        public int $candidatesWithOutgoingReplyFk,
        public int $candidatesWithPendingOutbox,
        public int $candidatesWithoutProcessedAt,
        public int $candidatesWithWrongStatus,
        public int $candidatesWithWrongIgnoreReason,
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
