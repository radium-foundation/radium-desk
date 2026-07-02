<?php

namespace App\Services\Operations;

use App\Enums\OutboxEventStatus;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationsQueueMetricsService
{
    public function __construct(
        private readonly QueueMetricsService $queueMetricsService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function metrics(?OperationsDashboardSnapshot $snapshot = null): array
    {
        if ($snapshot !== null) {
            $queueSnapshot = $snapshot->queueSnapshot();

            return [
                'pending' => $snapshot->pendingJobsCount(),
                'running' => $snapshot->runningJobsCount(),
                'failed' => $queueSnapshot->failedJobs,
                'retries' => $snapshot->retriesCount(),
                'queues' => $queueSnapshot->queues,
                'oldest_pending_at' => $queueSnapshot->oldestPendingJobAt?->toIso8601String(),
                'last_successful_job_at' => $queueSnapshot->lastSuccessfulJobAt?->toIso8601String(),
                'average_processing_ms' => $queueSnapshot->averageProcessingTimeMs,
            ];
        }

        $queueSnapshot = $this->queueMetricsService->latest() ?? $this->queueMetricsService->capture();

        return [
            'pending' => $this->countPendingJobs(),
            'running' => $this->countRunningJobs(),
            'failed' => $queueSnapshot->failedJobs,
            'retries' => $this->countRetries(),
            'queues' => $queueSnapshot->queues,
            'oldest_pending_at' => $queueSnapshot->oldestPendingJobAt?->toIso8601String(),
            'last_successful_job_at' => $queueSnapshot->lastSuccessfulJobAt?->toIso8601String(),
            'average_processing_ms' => $queueSnapshot->averageProcessingTimeMs,
        ];
    }

    private function countPendingJobs(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')->whereNull('reserved_at')->count();
    }

    private function countRunningJobs(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')->whereNotNull('reserved_at')->count();
    }

    private function countRetries(): int
    {
        $jobRetries = 0;

        if (Schema::hasTable('jobs')) {
            $jobRetries = (int) DB::table('jobs')->where('attempts', '>', 1)->count();
        }

        $outboxRetries = 0;

        if (Schema::hasTable('outbox_events')) {
            $outboxRetries = (int) OutboxEvent::query()
                ->whereIn('status', [OutboxEventStatus::Pending, OutboxEventStatus::Processing, OutboxEventStatus::Failed])
                ->where('attempts', '>', 0)
                ->count();
        }

        return $jobRetries + $outboxRetries;
    }
}
