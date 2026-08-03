<?php

namespace Tests\Unit\Platform;

use App\Data\Platform\PlatformHealthComponent;
use App\Enums\PlatformHealthStatus;
use App\Services\Operations\AutomationHealthService;
use App\Services\Platform\Health\QueueHealthProvider;
use App\Services\Platform\Health\SchedulerHealthProvider;
use App\Services\Platform\PlatformAutomationOverviewService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class PlatformAutomationOverviewSchedulerWorkersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-08-03 10:30:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();

        parent::tearDown();
    }

    public function test_scheduler_workers_stays_healthy_when_automation_health_is_failed(): void
    {
        $overview = $this->makeService(
            automationAggregation: [
                'health_status' => 'failed',
                'health_label' => 'Failed',
                'health_detail' => 'Scheduler appears stalled — no recent automation activity.',
                'failures_today' => 1,
                'pending_executions' => 2,
                'executions_today' => 10,
                'last_success_at' => '2026-08-03T08:59:22+05:30',
            ],
            schedulerStatus: PlatformHealthStatus::Healthy,
            schedulerDetail: 'Laravel scheduler heartbeat is fresh.',
            schedulerMetrics: [
                'last_run_at' => now()->toIso8601String(),
                'minutes_ago' => 0,
            ],
            queueStatus: PlatformHealthStatus::Healthy,
            queueDetail: 'Queue worker (dedicated_cron) is healthy.',
        )->overview(useCache: false);

        $automation = collect($overview['items'])->firstWhere('key', 'automation_health');
        $scheduler = collect($overview['items'])->firstWhere('key', 'scheduler');

        $this->assertSame(PlatformHealthStatus::Critical->value, $automation['status'] ?? null);
        $this->assertSame('Failed', $automation['status_label'] ?? null);
        $this->assertSame(PlatformHealthStatus::Healthy->value, $scheduler['status'] ?? null);
        $this->assertSame(PlatformHealthStatus::Healthy->label(), $scheduler['status_label'] ?? null);
        $this->assertStringContainsString('Last scheduler run', (string) ($scheduler['summary'] ?? ''));
        $this->assertStringNotContainsString('executions today', (string) ($scheduler['summary'] ?? ''));
        $this->assertStringNotContainsString('last success', strtolower((string) ($scheduler['summary'] ?? '')));
        $this->assertSame(PlatformHealthStatus::Critical->value, $overview['overall_status']);
    }

    public function test_scheduler_workers_is_critical_without_heartbeat_even_if_automation_is_healthy(): void
    {
        $overview = $this->makeService(
            automationAggregation: [
                'health_status' => 'healthy',
                'health_label' => 'Healthy',
                'health_detail' => 'No failures today and recent successful execution.',
                'failures_today' => 0,
                'pending_executions' => 0,
                'executions_today' => 3,
                'last_success_at' => now()->toIso8601String(),
            ],
            schedulerStatus: PlatformHealthStatus::Critical,
            schedulerDetail: 'No scheduler heartbeat recorded. Confirm cron is running schedule:run every minute.',
            schedulerMetrics: [
                'last_run_at' => null,
            ],
            queueStatus: PlatformHealthStatus::Healthy,
            queueDetail: 'Queue worker (dedicated_cron) is healthy.',
        )->overview(useCache: false);

        $scheduler = collect($overview['items'])->firstWhere('key', 'scheduler');

        $this->assertSame(PlatformHealthStatus::Healthy->value, collect($overview['items'])->firstWhere('key', 'automation_health')['status'] ?? null);
        $this->assertSame(PlatformHealthStatus::Critical->value, $scheduler['status'] ?? null);
        $this->assertSame(PlatformHealthStatus::Critical->value, $overview['overall_status']);
        $this->assertStringContainsString('No scheduler heartbeat recorded', (string) ($scheduler['summary'] ?? ''));
    }

    public function test_scheduler_diagnostics_use_runtime_probes_not_automation_ledger(): void
    {
        $diagnostics = $this->makeService(
            automationAggregation: [
                'health_status' => 'failed',
                'health_label' => 'Failed',
                'health_detail' => 'Scheduler appears stalled — no recent automation activity.',
                'failures_today' => 9,
                'pending_executions' => 4,
                'executions_today' => 12,
            ],
            schedulerStatus: PlatformHealthStatus::Warning,
            schedulerDetail: 'Last scheduler heartbeat was 5 minutes ago.',
            schedulerMetrics: [
                'last_run_at' => now()->subMinutes(5)->toIso8601String(),
                'minutes_ago' => 5,
            ],
            queueStatus: PlatformHealthStatus::Healthy,
            queueDetail: 'Queue worker (dedicated_cron) is healthy.',
        )->diagnostics('scheduler');

        $this->assertSame('scheduler', $diagnostics['key']);
        $this->assertStringContainsString('Last scheduler run 5 min ago', (string) $diagnostics['message']);
        $this->assertStringContainsString('Queue worker', (string) $diagnostics['message']);
        $this->assertStringNotContainsString('failures', strtolower((string) $diagnostics['message']));
        $this->assertStringNotContainsString('pending', strtolower((string) $diagnostics['message']));
    }

    public function test_automation_aggregation_failure_still_returns_scheduler_workers_item(): void
    {
        /** @var AutomationHealthService&MockInterface $automation */
        $automation = Mockery::mock(AutomationHealthService::class);
        $automation->shouldReceive('overviewAggregation')
            ->once()
            ->andThrow(new \RuntimeException('ledger unavailable'));

        $service = new PlatformAutomationOverviewService(
            $automation,
            $this->mockSchedulerProvider(
                PlatformHealthStatus::Healthy,
                'Laravel scheduler heartbeat is fresh.',
                ['last_run_at' => now()->toIso8601String(), 'minutes_ago' => 0],
            ),
            $this->mockQueueProvider(
                PlatformHealthStatus::Healthy,
                'Queue worker (dedicated_cron) is healthy.',
            ),
        );

        $overview = $service->overview(useCache: false);
        $keys = array_column($overview['items'], 'key');

        $this->assertSame(['automation_health', 'scheduler'], $keys);
        $this->assertFalse($overview['available']);
        $this->assertSame(
            PlatformHealthStatus::Healthy->value,
            collect($overview['items'])->firstWhere('key', 'scheduler')['status'] ?? null,
        );
        $this->assertSame(PlatformHealthStatus::Critical->value, $overview['overall_status']);
    }

    /**
     * @param  array<string, mixed>  $automationAggregation
     * @param  array<string, mixed>  $schedulerMetrics
     */
    private function makeService(
        array $automationAggregation,
        PlatformHealthStatus $schedulerStatus,
        string $schedulerDetail,
        array $schedulerMetrics,
        PlatformHealthStatus $queueStatus,
        string $queueDetail,
    ): PlatformAutomationOverviewService {
        /** @var AutomationHealthService&MockInterface $automation */
        $automation = Mockery::mock(AutomationHealthService::class);
        $automation->shouldReceive('overviewAggregation')->andReturn($automationAggregation);

        return new PlatformAutomationOverviewService(
            $automation,
            $this->mockSchedulerProvider($schedulerStatus, $schedulerDetail, $schedulerMetrics),
            $this->mockQueueProvider($queueStatus, $queueDetail),
        );
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    private function mockSchedulerProvider(
        PlatformHealthStatus $status,
        string $detail,
        array $metrics,
    ): SchedulerHealthProvider {
        /** @var SchedulerHealthProvider&MockInterface $provider */
        $provider = Mockery::mock(SchedulerHealthProvider::class);
        $provider->shouldReceive('probe')->andReturn(new PlatformHealthComponent(
            key: 'scheduler',
            label: 'Scheduler',
            status: $status,
            detail: $detail,
            checkedAt: now(),
            metrics: $metrics,
        ));

        return $provider;
    }

    private function mockQueueProvider(PlatformHealthStatus $status, string $detail): QueueHealthProvider
    {
        /** @var QueueHealthProvider&MockInterface $provider */
        $provider = Mockery::mock(QueueHealthProvider::class);
        $provider->shouldReceive('probe')->andReturn(new PlatformHealthComponent(
            key: 'queue',
            label: 'Queue',
            status: $status,
            detail: $detail,
            checkedAt: now(),
            metrics: [],
        ));

        return $provider;
    }
}
