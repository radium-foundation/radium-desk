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
    ) {}

    public function toArray(): array
    {
        return [
            'complete' => $this->complete,
            'supported' => $this->supported,
            'count' => $this->count,
            'failure_reason' => $this->failureReason,
            'next_cursor' => $this->nextCursor,
        ];
    }
}
