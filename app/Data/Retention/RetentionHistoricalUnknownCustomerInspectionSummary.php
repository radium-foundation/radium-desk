<?php

namespace App\Data\Retention;

use Illuminate\Support\Carbon;

readonly class RetentionHistoricalUnknownCustomerInspectionSummary
{
    /**
     * @param  array<string, int>  $candidatesByIgnoreReason
     * @param  array<string, int>  $candidatesByReceivedMonth
     * @param  array<int|string, int>  $candidatesByYear
     * @param  list<int>  $sampleCandidateIds
     * @param  list<array<string, mixed>>  $sampleCandidateMetadata
     * @param  list<array<string, mixed>>  $gmailSyncStates
     */
    public function __construct(
        public Carbon $inspectedAt,
        public int $tableTotalCount,
        public string $receivedAtCutoff,
        public int $candidateCount,
        public int $estimatedPayloadBytes,
        public ?string $oldestCandidateReceivedAt,
        public ?string $newestCandidateReceivedAt,
        public array $candidatesByIgnoreReason,
        public array $candidatesByReceivedMonth,
        public array $candidatesByYear,
        public array $sampleCandidateIds,
        public array $sampleCandidateMetadata,
        public int $unknownCustomerIgnoredTotal,
        public int $postCutoffUnknownCustomerIgnoredCount,
        public int $excludedNeedsReviewUnknownCustomerCount,
        public int $excludedUnknownCustomerWithIncidentId,
        public int $excludedUnknownCustomerWithOrderId,
        public int $excludedUnknownCustomerWithLinkFk,
        public int $excludedUnknownCustomerWithOutgoingReplyFk,
        public int $candidatesWithIncidentId,
        public int $candidatesWithOrderId,
        public int $candidatesWithLinkFk,
        public int $candidatesWithOutgoingReplyFk,
        public array $gmailSyncStates,
    ) {}
}
