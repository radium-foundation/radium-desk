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
     * @param  array<string, mixed>  $radiumBoxHealth
     * @param  list<array<string, mixed>>  $recentNotificationFailures
     * @param  list<array<string, mixed>>  $recentAutomationActivity
     * @param  list<array<string, mixed>>  $recentIraMessages
     * @param  list<array<string, mixed>>  $teamAvailability
     * @param  list<array<string, mixed>>  $teamTelegramStatus
     * @param  array<string, int>  $cashfreeDeviceEnrichmentQuality
     * @param  array<string, int>  $missingSerialAutomationQuality
     * @param  array<string, mixed>  $supportIntelligence
     */
    public function __construct(
        public array $systemHealth,
        public array $notificationMetrics,
        public array $automationMetrics,
        public array $queueMetrics,
        public array $integrationHealth,
        public array $radiumBoxHealth,
        public array $recentNotificationFailures,
        public array $recentAutomationActivity,
        public array $recentIraMessages,
        public array $teamAvailability,
        public array $teamTelegramStatus,
        public array $cashfreeDeviceEnrichmentQuality,
        public array $missingSerialAutomationQuality,
        public array $supportIntelligence,
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
            'radiumbox_health' => $this->radiumBoxHealth,
            'recent_notification_failures' => $this->recentNotificationFailures,
            'recent_automation_activity' => $this->recentAutomationActivity,
            'recent_ira_messages' => $this->recentIraMessages,
            'team_availability' => $this->teamAvailability,
            'team_telegram_status' => $this->teamTelegramStatus,
            'cashfree_device_enrichment_quality' => $this->cashfreeDeviceEnrichmentQuality,
            'missing_serial_automation_quality' => $this->missingSerialAutomationQuality,
            'support_intelligence' => $this->supportIntelligence,
            'generated_at' => $this->generatedAt->toIso8601String(),
        ];
    }
}
