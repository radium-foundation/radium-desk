<?php

namespace App\Data\Retention;

use Illuminate\Support\Carbon;

readonly class RetentionHistoricalGmailNoiseSummary
{
    /**
     * @param  array<string, int>  $candidatesByIgnoreReason
     * @param  array<string, int>  $candidatesByReceivedMonth
     * @param  list<int>  $sampleCandidateIds
     */
    public function __construct(
        public Carbon $inspectedAt,
        public string $receivedAtCutoff,
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
    ) {}
}
