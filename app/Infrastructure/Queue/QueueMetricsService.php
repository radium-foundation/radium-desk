<?php

namespace App\Infrastructure\Queue;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueMetricsService
{
    private const CACHE_KEY = 'infrastructure:queue:metrics:latest';

    private const PROCESSING_TIMES_KEY = 'infrastructure:queue:processing_times';

    private const LAST_SUCCESS_KEY = 'infrastructure:queue:last_success_at';

    private const PROCESSING_SAMPLE_LIMIT = 100;

    private ?QueueMetricsSnapshot $capturedThisRequest = null;

    public function capture(): QueueMetricsSnapshot
    {
        if ($this->capturedThisRequest !== null) {
            return $this->capturedThisRequest;
        }

        $snapshot = new QueueMetricsSnapshot(
            pendingJobs: $this->countPendingJobs(),
            failedJobs: $this->countFailedJobs(),
            lastSuccessfulJobAt: $this->lastSuccessfulJobAt(),
            averageProcessingTimeMs: $this->averageProcessingTimeMs(),
            queues: $this->distinctQueues(),
            capturedAt: now(),
            oldestPendingJobAt: $this->oldestPendingJobAt(),
        );

        Cache::put(self::CACHE_KEY, $snapshot->toArray(), now()->addDay());
        $this->capturedThisRequest = $snapshot;

        return $snapshot;
    }

    public function latest(): ?QueueMetricsSnapshot
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (! is_array($cached)) {
            return null;
        }

        return new QueueMetricsSnapshot(
            pendingJobs: (int) ($cached['pending_jobs'] ?? 0),
            failedJobs: (int) ($cached['failed_jobs'] ?? 0),
            lastSuccessfulJobAt: isset($cached['last_successful_job_at'])
                ? Carbon::parse($cached['last_successful_job_at'])
                : null,
            averageProcessingTimeMs: isset($cached['average_processing_time_ms'])
                ? (float) $cached['average_processing_time_ms']
                : null,
            queues: is_array($cached['queues'] ?? null) ? $cached['queues'] : [],
            capturedAt: Carbon::parse($cached['captured_at'] ?? now()->toIso8601String()),
            oldestPendingJobAt: isset($cached['oldest_pending_job_at'])
                ? Carbon::parse($cached['oldest_pending_job_at'])
                : null,
        );
    }

    public function recordJobSuccess(float $durationMs): void
    {
        Cache::put(self::LAST_SUCCESS_KEY, now()->toIso8601String(), now()->addDays(30));

        $samples = Cache::get(self::PROCESSING_TIMES_KEY, []);

        if (! is_array($samples)) {
            $samples = [];
        }

        $samples[] = round($durationMs, 2);

        if (count($samples) > self::PROCESSING_SAMPLE_LIMIT) {
            $samples = array_slice($samples, -self::PROCESSING_SAMPLE_LIMIT);
        }

        Cache::put(self::PROCESSING_TIMES_KEY, $samples, now()->addDays(7));
    }

    private function countPendingJobs(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) $this->runnableJobsQuery()->count();
    }

    private function countFailedJobs(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')->count();
    }

    /**
     * @return list<string>
     */
    private function distinctQueues(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')
            ->distinct()
            ->orderBy('queue')
            ->pluck('queue')
            ->map(fn ($queue) => (string) $queue)
            ->all();
    }

    private function lastSuccessfulJobAt(): ?Carbon
    {
        $cached = Cache::get(self::LAST_SUCCESS_KEY);

        return is_string($cached) && $cached !== ''
            ? Carbon::parse($cached)
            : null;
    }

    private function averageProcessingTimeMs(): ?float
    {
        $samples = Cache::get(self::PROCESSING_TIMES_KEY, []);

        if (! is_array($samples) || $samples === []) {
            return null;
        }

        return round(array_sum($samples) / count($samples), 2);
    }

    private function oldestPendingJobAt(): ?Carbon
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        // Age by when the job became runnable, not original created_at.
        // created_at stays fixed across backoff releases and falsely trips health
        // during intentional delays (e.g. RadiumBox enrichment 1800s backoff).
        $availableAt = $this->runnableJobsQuery()
            ->orderBy('available_at')
            ->value('available_at');

        if ($availableAt === null) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $availableAt);
    }

    /**
     * Jobs a database worker can claim right now (matches Laravel DatabaseQueue).
     */
    private function runnableJobsQuery(): Builder
    {
        $now = now()->getTimestamp();
        $retryAfter = (int) config('queue.connections.database.retry_after', 90);

        return DB::table('jobs')->where(function (Builder $query) use ($now, $retryAfter): void {
            $query->where(function (Builder $query) use ($now): void {
                $query->whereNull('reserved_at')
                    ->where('available_at', '<=', $now);
            })->orWhere(function (Builder $query) use ($now, $retryAfter): void {
                $query->whereNotNull('reserved_at')
                    ->where('reserved_at', '<=', $now - $retryAfter);
            });
        });
    }
}
