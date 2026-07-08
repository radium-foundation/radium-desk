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
            IraNotificationType::SmartAssignment,
            IraNotificationType::ManualAssignment,
            IraNotificationType::Reassignment,
            IraNotificationType::SupportSlotReminder => NotificationCategory::Assignment,
            IraNotificationType::RiskAlert,
            IraNotificationType::WaitingCustomerRisk,
            IraNotificationType::UnusualBacklog,
            IraNotificationType::UnassignedScheduledWork,
            IraNotificationType::TeamAvailabilityIssue => NotificationCategory::Escalation,
            IraNotificationType::IntegrationFailure => NotificationCategory::SystemHealth,
        };
    }
}
