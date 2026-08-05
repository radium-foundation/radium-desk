<?php

namespace App\Services\PerformanceIntelligence;

use App\Data\PerformanceIntelligence\PerformanceScoreResult;
use App\Models\PerformanceIntelligenceSnapshot;
use Illuminate\Support\Carbon;

/**
 * Builds and persists daily shadow snapshots.
 * No-ops when PERFORMANCE_INTELLIGENCE_ENABLED is false.
 */
class PerformanceSnapshotService
{
    public function __construct(
        private readonly PerformanceEventCollector $collector,
        private readonly PerformanceScoreCalculator $calculator,
        private readonly PerformanceSnapshotRepository $repository,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('performance_intelligence.enabled', false);
    }

    /**
     * @return array{
     *     processed: int,
     *     skipped: bool,
     *     date: string,
     *     duration_ms: int
     * }
     */
    public function captureDay(?Carbon $workDate = null, ?array $userIds = null): array
    {
        if (! $this->enabled()) {
            return [
                'processed' => 0,
                'skipped' => true,
                'date' => ($workDate ?? now()->subDay())->toDateString(),
                'duration_ms' => 0,
            ];
        }

        $started = hrtime(true);
        $workDate = ($workDate ?? now()->subDay())->copy()->startOfDay();
        $userIds ??= $this->collector->trackedUserIds();

        $inputs = $this->collector->collectForUsers($userIds, $workDate);
        $processed = 0;

        foreach ($inputs as $userId => $dayInputs) {
            $userStarted = hrtime(true);
            $result = $this->calculator->calculate(
                $dayInputs,
                durationMs: (int) ((hrtime(true) - $userStarted) / 1_000_000),
            );
            $this->repository->upsert($result);
            $processed++;
        }

        return [
            'processed' => $processed,
            'skipped' => false,
            'date' => $workDate->toDateString(),
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ];
    }

    public function scoreOne(int $userId, Carbon $workDate): ?PerformanceScoreResult
    {
        if (! $this->enabled()) {
            return null;
        }

        $started = hrtime(true);
        $inputs = $this->collector->collectForUsers([$userId], $workDate->copy()->startOfDay());
        $dayInputs = $inputs[$userId] ?? null;

        if ($dayInputs === null) {
            return null;
        }

        return $this->calculator->calculate(
            $dayInputs,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
        );
    }

    public function latestSnapshot(int $userId, Carbon $workDate): ?PerformanceIntelligenceSnapshot
    {
        return $this->repository->findForUserOnDate($userId, $workDate);
    }
}
