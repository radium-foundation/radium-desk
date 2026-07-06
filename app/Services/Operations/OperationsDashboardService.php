<?php

namespace App\Services\Operations;

use App\Data\Operations\OperationsDashboardData;
use App\Infrastructure\IntegrationHealth\Probes\CashfreeIntegrationHealthProbe;
use App\Infrastructure\Queue\QueueMetricsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

class OperationsDashboardService
{
    private const CACHE_KEY = 'operations:dashboard:latest:v2';

    private const CACHE_TTL_SECONDS = 30;

    private ?OperationsDashboardSnapshot $snapshot = null;

    public function __construct(
        private readonly QueueMetricsService $infrastructureQueueMetrics,
        private readonly CashfreeIntegrationHealthProbe $cashfreeProbe,
        private readonly OperationsSystemHealthService $systemHealthService,
        private readonly OperationsNotificationMetricsService $notificationMetricsService,
        private readonly OperationsAutomationMetricsService $automationMetricsService,
        private readonly OperationsQueueMetricsService $queueMetricsService,
        private readonly OperationsIntegrationHealthService $integrationHealthService,
        private readonly OperationsRadiumBoxHealthService $radiumBoxHealthService,
        private readonly OperationsCashfreeHealthService $cashfreeHealthService,
        private readonly OperationsRecentNotificationFailuresService $recentNotificationFailuresService,
        private readonly OperationsRecentAutomationActivityService $recentAutomationActivityService,
        private readonly OperationsRecentIraMessagesService $recentIraMessagesService,
        private readonly TeamAvailabilityOverviewService $teamAvailabilityOverviewService,
        private readonly OperationsCashfreeDeviceEnrichmentService $cashfreeDeviceEnrichmentService,
        private readonly OperationsMissingSerialAutomationService $missingSerialAutomationService,
        private readonly OperationsSupportIntelligenceService $supportIntelligenceService,
        private readonly OperationsTeamTelegramStatusService $teamTelegramStatusService,
    ) {}

    public function dashboardData(bool $useCache = true): OperationsDashboardData
    {
        if ($useCache) {
            $cached = Cache::get(self::CACHE_KEY);

            if ($cached instanceof OperationsDashboardData && $this->isCachedDashboardValid($cached)) {
                return $cached;
            }

            if ($cached !== null) {
                Cache::forget(self::CACHE_KEY);
            }
        }

        $data = $this->build();

        Cache::put(self::CACHE_KEY, $data, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $data;
    }

    public function build(?OperationsDashboardBuildProfiler $profiler = null): OperationsDashboardData
    {
        $snapshot = $this->snapshot();
        $missingSerialQuality = $profiler !== null
            ? $profiler->measure('missing_serial_automation', fn () => $this->missingSerialAutomationService->qualitySummary())
            : $this->missingSerialAutomationService->qualitySummary();

        $measure = fn (string $label, callable $callback) => $profiler !== null
            ? $profiler->measure($label, $callback)
            : $callback();

        return new OperationsDashboardData(
            systemHealth: $measure('system_health', fn () => $this->systemHealthService->components($snapshot)),
            notificationMetrics: $measure('notification_metrics', fn () => $this->notificationMetricsService->metrics($snapshot->auditAggregator())),
            automationMetrics: $measure('automation_metrics', fn () => $this->automationMetricsService->metrics($snapshot)),
            queueMetrics: $measure('queue_metrics', fn () => $this->queueMetricsService->metrics($snapshot)),
            integrationHealth: $measure('integration_health', fn () => $this->integrationHealthService->cards($snapshot)),
            radiumBoxHealth: $measure('radiumbox_health', fn () => $this->radiumBoxHealthService->widget()),
            cashfreeHealth: $measure('cashfree_health', fn () => $this->cashfreeHealthService->widget()),
            recentNotificationFailures: $measure('recent_notification_failures', fn () => $this->recentNotificationFailuresService->recent(limit: 15)),
            recentAutomationActivity: $measure('recent_automation_activity', fn () => $this->recentAutomationActivityService->recent(limit: 15)),
            recentIraMessages: $measure('recent_ira_messages', fn () => $this->recentIraMessagesService->recent(limit: 15)),
            teamAvailability: $measure('team_availability', fn () => $this->teamAvailabilityOverviewService->members()),
            teamTelegramStatus: $measure('team_telegram_status', fn () => $this->teamTelegramStatusService->members()),
            cashfreeDeviceEnrichmentQuality: $measure('cashfree_device_enrichment', fn () => $this->cashfreeDeviceEnrichmentService->qualitySummary()->toArray()),
            missingSerialAutomationQuality: $missingSerialQuality->toArray(),
            supportIntelligence: $measure('support_intelligence', fn () => $this->supportIntelligenceService->summary(serialQuality: $missingSerialQuality)->toArray()),
            generatedAt: now(),
        );
    }

    public function buildProfiled(): array
    {
        $profiler = new OperationsDashboardBuildProfiler;

        return [
            'data' => $this->build($profiler),
            'profile' => $profiler->timings(),
            'total_ms' => $profiler->totalMs(),
        ];
    }

    public function snapshot(): OperationsDashboardSnapshot
    {
        return $this->snapshot ??= OperationsDashboardSnapshot::load(
            $this->infrastructureQueueMetrics,
            $this->cashfreeProbe,
        );
    }

    private function isCachedDashboardValid(OperationsDashboardData $cached): bool
    {
        if (! $cached->generatedAt instanceof CarbonInterface) {
            return false;
        }

        $lastSuccessfulSyncAt = $cached->radiumBoxHealth['last_successful_sync_at'] ?? null;

        return $this->isValidRuntimeDate($lastSuccessfulSyncAt);
    }

    private function isValidRuntimeDate(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return $value instanceof CarbonInterface;
    }
}
