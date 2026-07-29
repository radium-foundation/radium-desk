<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\PlatformHealthStatus;
use App\Enums\QueueWorkerMode;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Infrastructure\Queue\QueueMetricsSnapshot;
use Illuminate\Support\Carbon;

class QueueHealthProvider implements PlatformHealthProvider
{
    public function __construct(
        private readonly QueueMetricsService $queueMetrics,
    ) {}

    public function key(): string
    {
        return 'queue';
    }

    public function label(): string
    {
        return 'Queue';
    }

    public function sortOrder(): int
    {
        return 30;
    }

    public function probe(): PlatformHealthComponent
    {
        $checkedAt = now();
        $workerMode = QueueWorkerMode::fromConfig();

        if (! $workerMode->isActive()) {
            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: PlatformHealthStatus::Disabled,
                detail: 'Queue worker is disabled.',
                checkedAt: $checkedAt,
                metrics: [
                    'queue_worker_mode' => $workerMode->value,
                ],
            );
        }

        // Always capture live metrics for health — cached snapshots can lag up to 24h
        // when INFRASTRUCTURE_METRICS_ENABLED is off and falsely keep a backlog warning.
        $snapshot = $this->queueMetrics->capture();
        [$status, $detail] = $this->assessSnapshot($workerMode, $snapshot, $checkedAt);

        return new PlatformHealthComponent(
            key: $this->key(),
            label: $this->label(),
            status: $status,
            detail: $detail,
            checkedAt: $checkedAt,
            metrics: [
                'queue_worker_mode' => $workerMode->value,
                'pending_jobs' => $snapshot->pendingJobs,
                'failed_jobs' => $snapshot->failedJobs,
                'oldest_pending_job_at' => $snapshot->oldestPendingJobAt?->toIso8601String(),
            ],
        );
    }

    /**
     * @return array{0: PlatformHealthStatus, 1: string}
     */
    private function assessSnapshot(
        QueueWorkerMode $workerMode,
        QueueMetricsSnapshot $snapshot,
        Carbon $checkedAt,
    ): array {
        $prefix = "Queue worker ({$workerMode->value})";

        if ($snapshot->failedJobs > 0) {
            return [
                PlatformHealthStatus::Critical,
                "{$prefix}: {$snapshot->failedJobs} failed job(s) in the dead-letter queue.",
            ];
        }

        if ($snapshot->pendingJobs > 50) {
            return [
                PlatformHealthStatus::Warning,
                "{$prefix}: {$snapshot->pendingJobs} pending job(s) waiting.",
            ];
        }

        if (
            $snapshot->oldestPendingJobAt !== null
            && $snapshot->oldestPendingJobAt->lt($checkedAt->copy()->subMinutes(30))
        ) {
            return [
                PlatformHealthStatus::Warning,
                "{$prefix}: oldest pending job is over 30 minutes old.",
            ];
        }

        return [
            PlatformHealthStatus::Healthy,
            "{$prefix} is healthy.",
        ];
    }
}
