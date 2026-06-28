<?php

namespace App\Infrastructure\Queue;

use Carbon\CarbonInterface;

/**
 * Point-in-time queue metrics for monitoring and future dashboard integration.
 */
readonly class QueueMetricsSnapshot
{
    /**
     * @param  list<string>  $queues
     */
    public function __construct(
        public int $pendingJobs,
        public int $failedJobs,
        public ?CarbonInterface $lastSuccessfulJobAt,
        public ?float $averageProcessingTimeMs,
        public array $queues,
        public CarbonInterface $capturedAt,
        public ?CarbonInterface $oldestPendingJobAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pending_jobs' => $this->pendingJobs,
            'failed_jobs' => $this->failedJobs,
            'last_successful_job_at' => $this->lastSuccessfulJobAt?->toIso8601String(),
            'average_processing_time_ms' => $this->averageProcessingTimeMs,
            'queues' => $this->queues,
            'captured_at' => $this->capturedAt->toIso8601String(),
            'oldest_pending_job_at' => $this->oldestPendingJobAt?->toIso8601String(),
        ];
    }
}
