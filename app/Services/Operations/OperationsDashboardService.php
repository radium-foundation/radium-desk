<?php

namespace App\Services\Operations;

use App\Data\Operations\OperationsDashboardData;
use App\Infrastructure\IntegrationHealth\Probes\CashfreeIntegrationHealthProbe;
use App\Infrastructure\Queue\QueueMetricsService;
use Illuminate\Support\Facades\Cache;

class OperationsDashboardService
{
    private const CACHE_KEY = 'operations:dashboard:latest';

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
        private readonly OperationsRecentNotificationFailuresService $recentNotificationFailuresService,
        private readonly OperationsRecentAutomationActivityService $recentAutomationActivityService,
        private readonly OperationsRecentIraMessagesService $recentIraMessagesService,
        private readonly TeamAvailabilityOverviewService $teamAvailabilityOverviewService,
        private readonly OperationsCashfreeDeviceEnrichmentService $cashfreeDeviceEnrichmentService,
        private readonly OperationsMissingSerialAutomationService $missingSerialAutomationService,
    ) {}

    public function dashboardData(bool $useCache = true): OperationsDashboardData
    {
        if ($useCache) {
            $cached = Cache::get(self::CACHE_KEY);

            if ($cached instanceof OperationsDashboardData) {
                return $cached;
            }
        }

        $data = $this->build();

        Cache::put(self::CACHE_KEY, $data, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $data;
    }

    public function build(): OperationsDashboardData
    {
        $snapshot = $this->snapshot();

        return new OperationsDashboardData(
            systemHealth: $this->systemHealthService->components($snapshot),
            notificationMetrics: $this->notificationMetricsService->metrics($snapshot->auditAggregator()),
            automationMetrics: $this->automationMetricsService->metrics($snapshot),
            queueMetrics: $this->queueMetricsService->metrics($snapshot),
            integrationHealth: $this->integrationHealthService->cards($snapshot),
            radiumBoxHealth: $this->radiumBoxHealthService->widget(),
            recentNotificationFailures: $this->recentNotificationFailuresService->recent(limit: 15),
            recentAutomationActivity: $this->recentAutomationActivityService->recent(limit: 15),
            recentIraMessages: $this->recentIraMessagesService->recent(limit: 15),
            teamAvailability: $this->teamAvailabilityOverviewService->members(),
            cashfreeDeviceEnrichmentQuality: $this->cashfreeDeviceEnrichmentService->qualitySummary()->toArray(),
            missingSerialAutomationQuality: $this->missingSerialAutomationService->qualitySummary()->toArray(),
            generatedAt: now(),
        );
    }

    public function snapshot(): OperationsDashboardSnapshot
    {
        return $this->snapshot ??= OperationsDashboardSnapshot::load(
            $this->infrastructureQueueMetrics,
            $this->cashfreeProbe,
        );
    }
}
