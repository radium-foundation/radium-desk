<?php

namespace App\Data\StatutoryCoverage;

final readonly class StatutoryCoverageSourcePopulation
{
    public function __construct(
        public bool $complete,
        public bool $supported,
        public int $count,
        public ?string $failureReason,
        public ?string $nextCursor,
        public ?string $snapshotConsistency = null,
        public ?int $spokeTotalCount = null,
        public int $pagesFetched = 0,
        public bool $authoritativeComplete = false,
    ) {}

    public function toArray(): array
    {
        return [
            'complete' => $this->complete,
            'supported' => $this->supported,
            'count' => $this->count,
            'failure_reason' => $this->failureReason,
            'next_cursor' => $this->nextCursor,
            'snapshot_consistency' => $this->snapshotConsistency,
            'spoke_total_count' => $this->spokeTotalCount,
            'pages_fetched' => $this->pagesFetched,
            'authoritative_complete' => $this->authoritativeComplete,
        ];
    }
}
