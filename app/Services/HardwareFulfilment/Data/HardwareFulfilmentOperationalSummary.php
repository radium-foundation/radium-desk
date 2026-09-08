<?php

namespace App\Services\HardwareFulfilment\Data;

final class HardwareFulfilmentOperationalSummary
{
    /**
     * @param  array<string, int>  $stageCounts
     * @param  array<string, int>  $sectionCounts
     */
    public function __construct(
        public readonly string $fromIst,
        public readonly string $toIst,
        public readonly int $qualifying,
        public readonly int $blockedReview,
        public readonly int $excluded,
        public readonly int $excludedUnpaid,
        public readonly int $excludedCompleted,
        public readonly int $excludedRin,
        public readonly array $stageCounts,
        public readonly array $sectionCounts = [],
        public readonly int $rinVisible = 0,
    ) {}
}
