<?php

namespace App\Services\Operations;

use App\Data\Operations\IraBriefingFormatted;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\OperationsDashboardData;

class OperationsDashboardLiveRenderer
{
    /** @var list<string> */
    public const ALL_SECTIONS = [
        'critical_alerts',
        'overview_cards',
        'health_status',
        'support_intelligence',
        'ira_briefing',
        'ira_briefing_details',
        'immediate_risks',
        'advisor_insights',
        'team_availability',
        'team_telegram_status',
        'system_health',
        'notification_metrics',
        'automation_metrics',
        'queue_metrics',
        'integration_health',
        'radiumbox_health',
        'cashfree_health',
        'cashfree_device_enrichment_quality',
        'missing_serial_automation_quality',
        'recent_notification_failures',
        'recent_automation_activity',
        'recent_ira_messages',
    ];

    /** @var array<string, list<string>> */
    public const GROUP_SECTIONS = [
        'critical' => ['critical_alerts'],
        'summary' => ['overview_cards'],
        'health' => ['health_status'],
        'today' => [
            'support_intelligence',
            'ira_briefing',
            'ira_briefing_details',
            'immediate_risks',
            'advisor_insights',
        ],
        'team' => ['team_availability', 'team_telegram_status'],
        'performance' => [
            'notification_metrics',
            'automation_metrics',
            'queue_metrics',
            'radiumbox_health',
            'cashfree_health',
            'cashfree_device_enrichment_quality',
            'missing_serial_automation_quality',
        ],
        'system' => [
            'system_health',
            'integration_health',
            'recent_notification_failures',
            'recent_automation_activity',
            'recent_ira_messages',
        ],
    ];

    /**
     * @param  list<string>|null  $groups
     * @return list<string>
     */
    public static function resolveSections(?array $groups): array
    {
        if ($groups === null || $groups === []) {
            return self::ALL_SECTIONS;
        }

        $sections = collect($groups)
            ->flatMap(fn (string $group): array => self::GROUP_SECTIONS[$group] ?? [])
            ->unique()
            ->values()
            ->all();

        return $sections !== [] ? $sections : self::ALL_SECTIONS;
    }

    /**
     * @param  list<string>  $sections
     * @param  list<\App\Data\Operations\OperationsInsightDTO>  $advisorInsights
     * @return array<string, string>
     */
    public function renderSections(
        array $sections,
        OperationsDashboardData $dashboard,
        ?IraMorningBriefing $iraBriefing,
        ?IraBriefingFormatted $iraBriefingFormatted,
        string $iraReasoningProvider,
        array $advisorInsights,
    ): array {
        $html = [];

        foreach ($sections as $section) {
            $html[$section] = $this->renderSection(
                $section,
                $dashboard,
                $iraBriefing,
                $iraBriefingFormatted,
                $iraReasoningProvider,
                $advisorInsights,
            );
        }

        return $html;
    }

    /**
     * @param  list<\App\Data\Operations\OperationsInsightDTO>  $advisorInsights
     */
    private function renderSection(
        string $section,
        OperationsDashboardData $dashboard,
        ?IraMorningBriefing $iraBriefing,
        ?IraBriefingFormatted $iraBriefingFormatted,
        string $iraReasoningProvider,
        array $advisorInsights,
    ): string {
        return match ($section) {
            'critical_alerts' => view('admin.operations.partials.critical-alerts', [
                'dashboard' => $dashboard,
                'briefing' => $iraBriefing,
            ])->render(),
            'overview_cards' => view('admin.operations.partials.overview-cards', [
                'briefing' => $iraBriefing,
                'formatted' => $iraBriefingFormatted,
                'members' => $dashboard->teamAvailability,
                'insights' => $advisorInsights,
                'intelligence' => $dashboard->supportIntelligence,
            ])->render(),
            'health_status' => view('admin.operations.partials.health-status-compact', [
                'cashfreeHealth' => $dashboard->cashfreeHealth,
                'radiumBoxHealth' => $dashboard->radiumBoxHealth,
                'teamTelegramStatus' => $dashboard->teamTelegramStatus,
            ])->render(),
            'ira_briefing' => view('admin.operations.partials.ira-briefing', [
                'briefing' => $iraBriefing,
                'formatted' => $iraBriefingFormatted,
                'reasoningProvider' => $iraReasoningProvider,
            ])->render(),
            'ira_briefing_details' => view('admin.operations.partials.ira-briefing-details', [
                'briefing' => $iraBriefing,
                'formatted' => $iraBriefingFormatted,
            ])->render(),
            'immediate_risks' => view('admin.operations.partials.immediate-risks', [
                'briefing' => $iraBriefing,
            ])->render(),
            'advisor_insights' => view('admin.operations.partials.advisor-insights', [
                'insights' => $advisorInsights,
            ])->render(),
            'system_health' => view('admin.operations.partials.system-health', [
                'components' => $dashboard->systemHealth,
            ])->render(),
            'notification_metrics' => view('admin.operations.partials.notification-metrics', [
                'metrics' => $dashboard->notificationMetrics,
            ])->render(),
            'automation_metrics' => view('admin.operations.partials.automation-metrics', [
                'metrics' => $dashboard->automationMetrics,
            ])->render(),
            'queue_metrics' => view('admin.operations.partials.queue-metrics', [
                'metrics' => $dashboard->queueMetrics,
            ])->render(),
            'integration_health' => view('admin.operations.partials.integration-health', [
                'cards' => $dashboard->integrationHealth,
            ])->render(),
            'radiumbox_health' => view('admin.operations.partials.radiumbox-health', [
                'health' => $dashboard->radiumBoxHealth,
            ])->render(),
            'cashfree_health' => view('admin.operations.partials.cashfree-health', [
                'health' => $dashboard->cashfreeHealth,
            ])->render(),
            'cashfree_device_enrichment_quality' => view('admin.operations.partials.cashfree-device-enrichment-quality', [
                'quality' => $dashboard->cashfreeDeviceEnrichmentQuality,
            ])->render(),
            'missing_serial_automation_quality' => view('admin.operations.partials.missing-serial-automation-quality', [
                'quality' => $dashboard->missingSerialAutomationQuality,
            ])->render(),
            'support_intelligence' => view('admin.operations.partials.support-intelligence', [
                'intelligence' => $dashboard->supportIntelligence,
            ])->render(),
            'recent_notification_failures' => view('admin.operations.partials.recent-notification-failures', [
                'failures' => $dashboard->recentNotificationFailures,
            ])->render(),
            'recent_automation_activity' => view('admin.operations.partials.recent-automation-activity', [
                'activities' => $dashboard->recentAutomationActivity,
            ])->render(),
            'recent_ira_messages' => view('admin.operations.partials.recent-ira-messages', [
                'messages' => $dashboard->recentIraMessages,
            ])->render(),
            'team_availability' => view('admin.operations.partials.team-availability', [
                'members' => $dashboard->teamAvailability,
            ])->render(),
            'team_telegram_status' => view('admin.operations.partials.team-telegram-status', [
                'members' => $dashboard->teamTelegramStatus,
            ])->render(),
            default => '',
        };
    }
}
