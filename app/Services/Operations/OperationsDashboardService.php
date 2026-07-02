<?php

namespace App\Services\Operations;

use App\Data\Operations\OperationsDashboardData;
use Illuminate\Support\Facades\Cache;

class OperationsDashboardService
{
    private const CACHE_KEY = 'operations:dashboard:latest';

    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly OperationsSystemHealthService $systemHealthService,
        private readonly OperationsNotificationMetricsService $notificationMetricsService,
        private readonly OperationsAutomationMetricsService $automationMetricsService,
        private readonly OperationsQueueMetricsService $queueMetricsService,
        private readonly OperationsIntegrationHealthService $integrationHealthService,
        private readonly OperationsRecentNotificationFailuresService $recentNotificationFailuresService,
        private readonly OperationsRecentAutomationActivityService $recentAutomationActivityService,
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
        return new OperationsDashboardData(
            systemHealth: $this->systemHealthService->components(),
            notificationMetrics: $this->notificationMetricsService->metrics(),
            automationMetrics: $this->automationMetricsService->metrics(),
            queueMetrics: $this->queueMetricsService->metrics(),
            integrationHealth: $this->integrationHealthService->cards(),
            recentNotificationFailures: $this->recentNotificationFailuresService->recent(limit: 15),
            recentAutomationActivity: $this->recentAutomationActivityService->recent(limit: 15),
            generatedAt: now(),
        );
    }
}
