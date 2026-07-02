<?php

namespace App\Data\Operations;

use Illuminate\Support\Carbon;

readonly class OperationsDashboardData
{
    /**
     * @param  list<array<string, mixed>>  $systemHealth
     * @param  array<string, mixed>  $notificationMetrics
     * @param  array<string, mixed>  $automationMetrics
     * @param  array<string, mixed>  $queueMetrics
     * @param  list<array<string, mixed>>  $integrationHealth
     * @param  list<array<string, mixed>>  $recentNotificationFailures
     * @param  list<array<string, mixed>>  $recentAutomationActivity
     */
    public function __construct(
        public array $systemHealth,
        public array $notificationMetrics,
        public array $automationMetrics,
        public array $queueMetrics,
        public array $integrationHealth,
        public array $recentNotificationFailures,
        public array $recentAutomationActivity,
        public Carbon $generatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'system_health' => $this->systemHealth,
            'notification_metrics' => $this->notificationMetrics,
            'automation_metrics' => $this->automationMetrics,
            'queue_metrics' => $this->queueMetrics,
            'integration_health' => $this->integrationHealth,
            'recent_notification_failures' => $this->recentNotificationFailures,
            'recent_automation_activity' => $this->recentAutomationActivity,
            'generated_at' => $this->generatedAt->toIso8601String(),
        ];
    }
}
