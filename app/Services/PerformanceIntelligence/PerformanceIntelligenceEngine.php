<?php

namespace App\Services\PerformanceIntelligence;

use App\Models\PerformanceIntelligenceSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Public façade for Phase 0 Performance Intelligence (shadow mode).
 *
 * When disabled: all methods short-circuit with zero side effects.
 */
class PerformanceIntelligenceEngine
{
    public function __construct(
        private readonly PerformanceSnapshotService $snapshotService,
        private readonly PerformanceSnapshotRepository $repository,
    ) {}

    public function enabled(): bool
    {
        return $this->snapshotService->enabled();
    }

    /**
     * @return array{processed: int, skipped: bool, date: string, duration_ms: int}
     */
    public function captureDay(?Carbon $workDate = null, ?array $userIds = null): array
    {
        return $this->snapshotService->captureDay($workDate, $userIds);
    }

    /**
     * @return Collection<int, PerformanceIntelligenceSnapshot>
     */
    public function snapshotsForDate(Carbon $date): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        return $this->repository->forDate($date);
    }

    public function snapshotForUser(int $userId, Carbon $date): ?PerformanceIntelligenceSnapshot
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->repository->findForUserOnDate($userId, $date);
    }

    /**
     * @return list<string>
     */
    public function availableDates(int $limit = 60): array
    {
        if (! $this->enabled()) {
            return [];
        }

        return $this->repository->availableDates($limit);
    }
}
