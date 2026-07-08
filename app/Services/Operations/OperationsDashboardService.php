<?php

namespace App\Services\Operations;

use App\Data\Operations\OperationsDashboardData;
use App\Services\Bonvoice\BonvoiceAnalyticsService;
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
        private readonly BonvoiceAnalyticsService $bonvoiceAnalyticsService,
    ) {}

    public function dashboardData(bool $useCache = true): OperationsDashboardData
    {
        return $this->dashboardDataForSections(OperationsDashboardLiveRenderer::ALL_SECTIONS, $useCache);
    }

    /**
     * @param  list<string>  $sections
     */
    public function dashboardDataForSections(array $sections, bool $useCache = true): OperationsDashboardData
    {
        if (OperationsDashboardSectionBundles::isFullRefresh($sections)) {
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

        return $this->buildForSections($sections);
    }

    public function build(?OperationsDashboardBuildProfiler $profiler = null): OperationsDashboardData
    {
        return $this->buildBundles(OperationsDashboardSectionBundles::allBundles(), $profiler);
    }

    /**
     * @param  list<string>  $sections
     */
    public function buildForSections(array $sections, ?OperationsDashboardBuildProfiler $profiler = null): OperationsDashboardData
    {
        return $this->buildBundles(OperationsDashboardSectionBundles::bundlesForSections($sections), $profiler);
    }

    /**
     * @param  list<string>  $bundles
     */
    private function buildBundles(array $bundles, ?OperationsDashboardBuildProfiler $profiler = null): OperationsDashboardData
    {
        $bundleSet = array_fill_keys($bundles, true);
        $snapshot = $this->needsOperationsSnapshot($bundleSet) ? $this->snapshot() : null;
        $missingSerialQuality = null;

        if (isset($bundleSet[OperationsDashboardSectionBundles::MISSING_SERIAL_AUTOMATION])
            || isset($bundleSet[OperationsDashboardSectionBundles::SUPPORT_INTELLIGENCE])) {
            $missingSerialQuality = $profiler !== null
                ? $profiler->measure('missing_serial_automation', fn () => $this->missingSerialAutomationService->qualitySummary())
                : $this->missingSerialAutomationService->qualitySummary();
        }

        $measure = fn (string $label, callable $callback) => $profiler !== null
            ? $profiler->measure($label, $callback)
            : $callback();

        $empty = OperationsDashboardData::empty();

        return new OperationsDashboardData(
            systemHealth: isset($bundleSet[OperationsDashboardSectionBundles::SYSTEM_HEALTH])
                ? $measure('system_health', fn () => $this->systemHealthService->components($snapshot))
                : $empty->systemHealth,
            notificationMetrics: isset($bundleSet[OperationsDashboardSectionBundles::NOTIFICATION_METRICS])
                ? $measure('notification_metrics', fn () => $this->notificationMetricsService->metrics($snapshot?->auditAggregator()))
                : $empty->notificationMetrics,
            automationMetrics: isset($bundleSet[OperationsDashboardSectionBundles::AUTOMATION_METRICS])
                ? $measure('automation_metrics', fn () => $this->automationMetricsService->metrics($snapshot))
                : $empty->automationMetrics,
            queueMetrics: isset($bundleSet[OperationsDashboardSectionBundles::QUEUE_METRICS])
                ? $measure('queue_metrics', fn () => $this->queueMetricsService->metrics($snapshot))
                : $empty->queueMetrics,
            integrationHealth: isset($bundleSet[OperationsDashboardSectionBundles::INTEGRATION_HEALTH])
                ? $measure('integration_health', fn () => $this->integrationHealthService->cards($snapshot))
                : $empty->integrationHealth,
            radiumBoxHealth: isset($bundleSet[OperationsDashboardSectionBundles::RADIUMBOX_HEALTH])
                ? $measure('radiumbox_health', fn () => $this->radiumBoxHealthService->widget())
                : $empty->radiumBoxHealth,
            cashfreeHealth: isset($bundleSet[OperationsDashboardSectionBundles::CASHFREE_HEALTH])
                ? $measure('cashfree_health', fn () => $this->cashfreeHealthService->widget())
                : $empty->cashfreeHealth,
            recentNotificationFailures: isset($bundleSet[OperationsDashboardSectionBundles::RECENT_NOTIFICATION_FAILURES])
                ? $measure('recent_notification_failures', fn () => $this->recentNotificationFailuresService->recent(limit: 15))
                : $empty->recentNotificationFailures,
            recentAutomationActivity: isset($bundleSet[OperationsDashboardSectionBundles::RECENT_AUTOMATION_ACTIVITY])
                ? $measure('recent_automation_activity', fn () => $this->recentAutomationActivityService->recent(limit: 15))
                : $empty->recentAutomationActivity,
            recentIraMessages: isset($bundleSet[OperationsDashboardSectionBundles::RECENT_IRA_MESSAGES])
                ? $measure('recent_ira_messages', fn () => $this->recentIraMessagesService->recent(limit: 15))
                : $empty->recentIraMessages,
            teamAvailability: isset($bundleSet[OperationsDashboardSectionBundles::TEAM_AVAILABILITY])
                ? $measure('team_availability', fn () => $this->teamAvailabilityOverviewService->members())
                : $empty->teamAvailability,
            teamTelegramStatus: isset($bundleSet[OperationsDashboardSectionBundles::TEAM_TELEGRAM_STATUS])
                ? $measure('team_telegram_status', fn () => $this->teamTelegramStatusService->members())
                : $empty->teamTelegramStatus,
            cashfreeDeviceEnrichmentQuality: isset($bundleSet[OperationsDashboardSectionBundles::CASHFREE_DEVICE_ENRICHMENT])
                ? $measure('cashfree_device_enrichment', fn () => $this->cashfreeDeviceEnrichmentService->qualitySummary()->toArray())
                : $empty->cashfreeDeviceEnrichmentQuality,
            missingSerialAutomationQuality: isset($bundleSet[OperationsDashboardSectionBundles::MISSING_SERIAL_AUTOMATION])
                ? ($missingSerialQuality?->toArray() ?? $empty->missingSerialAutomationQuality)
                : $empty->missingSerialAutomationQuality,
            supportIntelligence: isset($bundleSet[OperationsDashboardSectionBundles::SUPPORT_INTELLIGENCE])
                ? $measure('support_intelligence', fn () => $this->supportIntelligenceService->summary(serialQuality: $missingSerialQuality)->toArray())
                : $empty->supportIntelligence,
            ivrAnalytics: isset($bundleSet[OperationsDashboardSectionBundles::IVR_ANALYTICS])
                ? $measure('ivr_analytics', fn () => $this->bonvoiceAnalyticsService->widgets())
                : $empty->ivrAnalytics,
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

    /**
     * @param  array<string, true>  $bundleSet
     */
    private function needsOperationsSnapshot(array $bundleSet): bool
    {
        return isset($bundleSet[OperationsDashboardSectionBundles::SYSTEM_HEALTH])
            || isset($bundleSet[OperationsDashboardSectionBundles::NOTIFICATION_METRICS])
            || isset($bundleSet[OperationsDashboardSectionBundles::AUTOMATION_METRICS])
            || isset($bundleSet[OperationsDashboardSectionBundles::QUEUE_METRICS])
            || isset($bundleSet[OperationsDashboardSectionBundles::INTEGRATION_HEALTH]);
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
