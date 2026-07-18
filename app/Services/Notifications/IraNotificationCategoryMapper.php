<?php

namespace App\Services\Notifications;

use App\Enums\IraNotificationType;
use App\Enums\NotificationCategory;

class IraNotificationCategoryMapper
{
    public static function toNotificationCategory(IraNotificationType $type): NotificationCategory
    {
        return match ($type) {
            IraNotificationType::DailyBriefing,
            IraNotificationType::TeamDailyBriefing => NotificationCategory::DailySummary,
            IraNotificationType::OpsDigest,
            IraNotificationType::OwnerIntelligenceReport => NotificationCategory::DailySummary,
            IraNotificationType::SmartAssignment,
            IraNotificationType::IraAssignmentBatch,
            IraNotificationType::ManualAssignment,
            IraNotificationType::Reassignment,
            IraNotificationType::SupportSlotReminder,
            IraNotificationType::SupportAppointmentReminder,
            IraNotificationType::TeamAnnouncement => NotificationCategory::Assignment,
            IraNotificationType::RiskAlert,
            IraNotificationType::WaitingCustomerRisk,
            IraNotificationType::UnusualBacklog,
            IraNotificationType::UnassignedScheduledWork,
            IraNotificationType::TeamAvailabilityIssue => NotificationCategory::Escalation,
            IraNotificationType::IntegrationFailure => NotificationCategory::SystemHealth,
            IraNotificationType::CriticalSystemAlert => NotificationCategory::SystemHealth,
        };
    }
}
