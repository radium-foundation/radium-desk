<?php

namespace App\Services\Dashboard;

/**
 * Precomputed filter-count variants for KPI broadcast fan-out.
 */
final class LiveReverbMetricsBatch
{
    /**
     * @param  array<string, int>  $operationsFilterCounts
     * @param  array<int, array<string, int>>  $supportFilterCountsByUserId
     */
    public function __construct(
        public readonly array $operationsFilterCounts,
        public readonly array $supportFilterCountsByUserId,
    ) {}
}
