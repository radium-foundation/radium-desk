<?php

namespace Tests\Feature\Infrastructure;

use App\Enums\PlatformHealthStatus;
use App\Enums\QueueWorkerMode;
use App\Infrastructure\Queue\QueueMetricsService;
use App\Services\Platform\Health\QueueHealthProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueMetricsHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-07-29 12:00:00', 'Asia/Kolkata'));
        config(['infrastructure.queue_worker_mode' => QueueWorkerMode::DedicatedCron->value]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_delayed_backoff_job_is_not_counted_as_pending_backlog(): void
    {
        $this->insertJob(
            queue: 'critical',
            attempts: 3,
            availableAt: now()->addMinutes(25)->getTimestamp(),
            createdAt: now()->subHour()->getTimestamp(),
        );

        $snapshot = app(QueueMetricsService::class)->capture();

        $this->assertSame(0, $snapshot->pendingJobs);
        $this->assertNull($snapshot->oldestPendingJobAt);

        $health = app(QueueHealthProvider::class)->probe();

        $this->assertSame(PlatformHealthStatus::Healthy, $health->status);
        $this->assertStringContainsString('is healthy', $health->detail);
    }

    public function test_due_job_older_than_thirty_minutes_warns(): void
    {
        $availableAt = now()->subMinutes(45)->getTimestamp();

        $this->insertJob(
            queue: 'critical',
            attempts: 0,
            availableAt: $availableAt,
            createdAt: $availableAt,
        );

        $snapshot = app(QueueMetricsService::class)->capture();

        $this->assertSame(1, $snapshot->pendingJobs);
        $this->assertNotNull($snapshot->oldestPendingJobAt);
        $this->assertTrue($snapshot->oldestPendingJobAt->equalTo(Carbon::createFromTimestamp($availableAt)));

        $health = app(QueueHealthProvider::class)->probe();

        $this->assertSame(PlatformHealthStatus::Warning, $health->status);
        $this->assertStringContainsString('oldest pending job is over 30 minutes old', $health->detail);
    }

    public function test_expired_reserved_job_counts_as_runnable_pending(): void
    {
        config(['queue.connections.database.retry_after' => 90]);

        $availableAt = now()->subMinutes(10)->getTimestamp();
        $reservedAt = now()->subMinutes(5)->getTimestamp();

        $this->insertJob(
            queue: 'default',
            attempts: 1,
            availableAt: $availableAt,
            createdAt: $availableAt,
            reservedAt: $reservedAt,
        );

        $snapshot = app(QueueMetricsService::class)->capture();

        $this->assertSame(1, $snapshot->pendingJobs);
        $this->assertNotNull($snapshot->oldestPendingJobAt);
    }

    public function test_in_flight_reserved_job_is_not_counted_as_pending(): void
    {
        config(['queue.connections.database.retry_after' => 90]);

        $availableAt = now()->subMinutes(2)->getTimestamp();
        $reservedAt = now()->subSeconds(30)->getTimestamp();

        $this->insertJob(
            queue: 'default',
            attempts: 1,
            availableAt: $availableAt,
            createdAt: $availableAt,
            reservedAt: $reservedAt,
        );

        $snapshot = app(QueueMetricsService::class)->capture();

        $this->assertSame(0, $snapshot->pendingJobs);
        $this->assertNull($snapshot->oldestPendingJobAt);
    }

    public function test_health_probe_ignores_stale_cached_backlog_snapshot(): void
    {
        Cache::put('infrastructure:queue:metrics:latest', [
            'pending_jobs' => 1,
            'failed_jobs' => 0,
            'last_successful_job_at' => null,
            'average_processing_time_ms' => null,
            'queues' => ['critical'],
            'captured_at' => now()->subHours(2)->toIso8601String(),
            'oldest_pending_job_at' => now()->subHours(2)->toIso8601String(),
        ], now()->addDay());

        $health = app(QueueHealthProvider::class)->probe();

        $this->assertSame(PlatformHealthStatus::Healthy, $health->status);
        $this->assertSame(0, $health->metrics['pending_jobs'] ?? null);
    }

    private function insertJob(
        string $queue,
        int $attempts,
        int $availableAt,
        int $createdAt,
        ?int $reservedAt = null,
    ): void {
        DB::table('jobs')->insert([
            'queue' => $queue,
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RadiumBoxOrderEnrichmentJob',
                'data' => ['commandName' => 'App\\Jobs\\RadiumBoxOrderEnrichmentJob'],
            ], JSON_THROW_ON_ERROR),
            'attempts' => $attempts,
            'reserved_at' => $reservedAt,
            'available_at' => $availableAt,
            'created_at' => $createdAt,
        ]);
    }
}
