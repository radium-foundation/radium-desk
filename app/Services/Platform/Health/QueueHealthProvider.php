<?php

namespace App\Services\Platform\Health;

use App\Contracts\Platform\PlatformHealthProvider;
use App\Data\Platform\PlatformHealthComponent;
use App\Enums\PlatformHealthStatus;
use App\Infrastructure\Queue\QueueMetricsService;

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
        $cronWorkerEnabled = (bool) config('infrastructure.queue_cron_worker_enabled');

        if (! $cronWorkerEnabled) {
            return new PlatformHealthComponent(
                key: $this->key(),
                label: $this->label(),
                status: PlatformHealthStatus::Disabled,
                detail: 'Cron queue worker is disabled.',
                checkedAt: $checkedAt,
                metrics: [
                    'cron_worker_enabled' => false,
                ],
            );
        }

        $snapshot = $this->queueMetrics->latest() ?? $this->queueMetrics->capture();

        if ($snapshot->failedJobs > 0) {
            $status = PlatformHealthStatus::Critical;
            $detail = "{$snapshot->failedJobs} failed job(s) in the dead-letter queue.";
        } elseif ($snapshot->pendingJobs > 50) {
            $status = PlatformHealthStatus::Warning;
            $detail = "{$snapshot->pendingJobs} pending job(s) waiting.";
        } elseif (
            $snapshot->oldestPendingJobAt !== null
            && $snapshot->oldestPendingJobAt->lt($checkedAt->copy()->subMinutes(30))
        ) {
            $status = PlatformHealthStatus::Warning;
            $detail = 'Oldest pending job is over 30 minutes old.';
        } else {
            $status = PlatformHealthStatus::Healthy;
            $detail = 'Queue worker is processing normally.';
        }

        return new PlatformHealthComponent(
            key: $this->key(),
            label: $this->label(),
            status: $status,
            detail: $detail,
            checkedAt: $checkedAt,
            metrics: [
                'cron_worker_enabled' => true,
                'pending_jobs' => $snapshot->pendingJobs,
                'failed_jobs' => $snapshot->failedJobs,
                'oldest_pending_job_at' => $snapshot->oldestPendingJobAt?->toIso8601String(),
            ],
        );
    }
}
