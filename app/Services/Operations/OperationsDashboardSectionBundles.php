<?php

namespace App\Services\Operations;

class OperationsDashboardSectionBundles
{
    public const SYSTEM_HEALTH = 'system_health';

    public const NOTIFICATION_METRICS = 'notification_metrics';

    public const AUTOMATION_METRICS = 'automation_metrics';

    public const QUEUE_METRICS = 'queue_metrics';

    public const INTEGRATION_HEALTH = 'integration_health';

    public const RADIUMBOX_HEALTH = 'radiumbox_health';

    public const CASHFREE_HEALTH = 'cashfree_health';

    public const RECENT_NOTIFICATION_FAILURES = 'recent_notification_failures';

    public const RECENT_AUTOMATION_ACTIVITY = 'recent_automation_activity';

    public const RECENT_IRA_MESSAGES = 'recent_ira_messages';

    public const TEAM_AVAILABILITY = 'team_availability';

    public const TEAM_TELEGRAM_STATUS = 'team_telegram_status';

    public const CASHFREE_DEVICE_ENRICHMENT = 'cashfree_device_enrichment';

    public const MISSING_SERIAL_AUTOMATION = 'missing_serial_automation';

    public const SUPPORT_INTELLIGENCE = 'support_intelligence';

    public const IVR_ANALYTICS = 'ivr_analytics';

    /** @var array<string, list<string>> */
    public const SECTION_BUNDLES = [
        'critical_alerts' => [
            self::CASHFREE_HEALTH,
            self::RADIUMBOX_HEALTH,
            self::SUPPORT_INTELLIGENCE,
        ],
        'overview_cards' => [
            self::SYSTEM_HEALTH,
            self::INTEGRATION_HEALTH,
            self::CASHFREE_HEALTH,
            self::RADIUMBOX_HEALTH,
            self::TEAM_TELEGRAM_STATUS,
            self::SUPPORT_INTELLIGENCE,
            self::TEAM_AVAILABILITY,
        ],
        'ira_compact' => [],
        'ira_full_analysis' => [],
        'health_status' => [
            self::CASHFREE_HEALTH,
            self::RADIUMBOX_HEALTH,
            self::TEAM_TELEGRAM_STATUS,
        ],
        'today_tab' => [
            self::SUPPORT_INTELLIGENCE,
        ],
        'team_tab' => [
            self::TEAM_AVAILABILITY,
            self::TEAM_TELEGRAM_STATUS,
        ],
        'performance_tab' => [
            self::IVR_ANALYTICS,
            self::NOTIFICATION_METRICS,
            self::AUTOMATION_METRICS,
            self::QUEUE_METRICS,
            self::RADIUMBOX_HEALTH,
            self::CASHFREE_HEALTH,
            self::CASHFREE_DEVICE_ENRICHMENT,
            self::MISSING_SERIAL_AUTOMATION,
        ],
        'system_tab' => [
            self::SYSTEM_HEALTH,
            self::INTEGRATION_HEALTH,
            self::RECENT_NOTIFICATION_FAILURES,
            self::RECENT_AUTOMATION_ACTIVITY,
            self::RECENT_IRA_MESSAGES,
        ],
        'health_cashfree' => [
            self::CASHFREE_HEALTH,
        ],
        'health_radiumbox' => [
            self::RADIUMBOX_HEALTH,
        ],
        'health_telegram' => [
            self::TEAM_TELEGRAM_STATUS,
        ],
        'ira_briefing' => [],
        'ira_briefing_details' => [],
        'immediate_risks' => [],
        'advisor_insights' => [],
        'system_health' => [
            self::SYSTEM_HEALTH,
        ],
        'notification_metrics' => [
            self::NOTIFICATION_METRICS,
        ],
        'automation_metrics' => [
            self::AUTOMATION_METRICS,
        ],
        'queue_metrics' => [
            self::QUEUE_METRICS,
        ],
        'integration_health' => [
            self::INTEGRATION_HEALTH,
        ],
        'cashfree_health' => [
            self::CASHFREE_HEALTH,
        ],
        'cashfree_device_enrichment_quality' => [
            self::CASHFREE_DEVICE_ENRICHMENT,
        ],
        'missing_serial_automation_quality' => [
            self::MISSING_SERIAL_AUTOMATION,
        ],
        'support_intelligence' => [
            self::SUPPORT_INTELLIGENCE,
        ],
        'recent_notification_failures' => [
            self::RECENT_NOTIFICATION_FAILURES,
        ],
        'recent_automation_activity' => [
            self::RECENT_AUTOMATION_ACTIVITY,
        ],
        'recent_ira_messages' => [
            self::RECENT_IRA_MESSAGES,
        ],
        'team_availability' => [
            self::TEAM_AVAILABILITY,
        ],
    ];

    /**
     * @param  list<string>  $sections
     * @return list<string>
     */
    public static function bundlesForSections(array $sections): array
    {
        if ($sections === OperationsDashboardLiveRenderer::ALL_SECTIONS) {
            return self::allBundles();
        }

        return collect($sections)
            ->flatMap(fn (string $section): array => self::SECTION_BUNDLES[$section] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function allBundles(): array
    {
        return [
            self::SYSTEM_HEALTH,
            self::NOTIFICATION_METRICS,
            self::AUTOMATION_METRICS,
            self::QUEUE_METRICS,
            self::INTEGRATION_HEALTH,
            self::RADIUMBOX_HEALTH,
            self::CASHFREE_HEALTH,
            self::RECENT_NOTIFICATION_FAILURES,
            self::RECENT_AUTOMATION_ACTIVITY,
            self::RECENT_IRA_MESSAGES,
            self::TEAM_AVAILABILITY,
            self::TEAM_TELEGRAM_STATUS,
            self::CASHFREE_DEVICE_ENRICHMENT,
            self::MISSING_SERIAL_AUTOMATION,
            self::SUPPORT_INTELLIGENCE,
            self::IVR_ANALYTICS,
        ];
    }

    /**
     * @param  list<string>  $sections
     */
    public static function isFullRefresh(array $sections): bool
    {
        return $sections === OperationsDashboardLiveRenderer::ALL_SECTIONS;
    }
}
