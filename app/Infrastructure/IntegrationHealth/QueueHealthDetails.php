<?php

namespace App\Infrastructure\IntegrationHealth;

use Carbon\CarbonInterface;

readonly class QueueHealthDetails
{
    public function __construct(
        public int $pendingJobs,
        public int $failedJobs,
        public ?CarbonInterface $oldestPendingJobAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pending_jobs' => $this->pendingJobs,
            'failed_jobs' => $this->failedJobs,
            'oldest_pending_job_at' => $this->oldestPendingJobAt?->toIso8601String(),
        ];
    }
}
