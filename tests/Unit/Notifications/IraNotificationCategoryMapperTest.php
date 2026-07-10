<?php

namespace Tests\Unit\Notifications;

use App\Enums\IraNotificationType;
use App\Enums\NotificationCategory;
use App\Services\Notifications\IraNotificationCategoryMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IraNotificationCategoryMapperTest extends TestCase
{
    #[DataProvider('iraNotificationCategoryMappings')]
    public function test_maps_ira_notification_type_to_notification_category(
        IraNotificationType $type,
        NotificationCategory $expectedCategory,
    ): void {
        $this->assertSame(
            $expectedCategory,
            IraNotificationCategoryMapper::toNotificationCategory($type),
        );
    }

    /**
     * @return array<string, array{0: IraNotificationType, 1: NotificationCategory}>
     */
    public static function iraNotificationCategoryMappings(): array
    {
        return [
            'daily briefing' => [IraNotificationType::DailyBriefing, NotificationCategory::DailySummary],
            'team daily briefing' => [IraNotificationType::TeamDailyBriefing, NotificationCategory::DailySummary],
            'smart assignment' => [IraNotificationType::SmartAssignment, NotificationCategory::Assignment],
            'manual assignment' => [IraNotificationType::ManualAssignment, NotificationCategory::Assignment],
            'reassignment' => [IraNotificationType::Reassignment, NotificationCategory::Assignment],
            'support slot reminder' => [IraNotificationType::SupportSlotReminder, NotificationCategory::Assignment],
            'risk alert' => [IraNotificationType::RiskAlert, NotificationCategory::Escalation],
            'waiting customer risk' => [IraNotificationType::WaitingCustomerRisk, NotificationCategory::Escalation],
            'unusual backlog' => [IraNotificationType::UnusualBacklog, NotificationCategory::Escalation],
            'unassigned scheduled work' => [IraNotificationType::UnassignedScheduledWork, NotificationCategory::Escalation],
            'team availability issue' => [IraNotificationType::TeamAvailabilityIssue, NotificationCategory::Escalation],
            'integration failure' => [IraNotificationType::IntegrationFailure, NotificationCategory::SystemHealth],
            'critical system alert' => [IraNotificationType::CriticalSystemAlert, NotificationCategory::SystemHealth],
            'team announcement' => [IraNotificationType::TeamAnnouncement, NotificationCategory::Assignment],
            'owner intelligence report' => [IraNotificationType::OwnerIntelligenceReport, NotificationCategory::DailySummary],
        ];
    }
}
