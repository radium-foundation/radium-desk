<?php

namespace App\Services\Dashboard;

use App\Models\Incident;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Active-incident hydrate plus optional precomputed global queue/SLA aggregates.
 */
final class CachedActiveIncidentSnapshot
{
    /**
     * @param  EloquentCollection<int, Incident>  $incidents
     * @param  array<string, int>|null  $queueCounts  Global (unscoped) queue counts
     * @param  array{
     *     overdue_cases: int,
     *     warning_cases: int,
     *     service_overdue_cases: int,
     *     service_warning_cases: int,
     *     hardware_overdue_cases: int,
     *     hardware_warning_cases: int
     * }|null  $slaCounts
     */
    public function __construct(
        public readonly EloquentCollection $incidents,
        public readonly ?array $queueCounts = null,
        public readonly ?array $slaCounts = null,
    ) {}

    public function hasPrecomputedMetrics(): bool
    {
        return $this->queueCounts !== null && $this->slaCounts !== null;
    }
}
