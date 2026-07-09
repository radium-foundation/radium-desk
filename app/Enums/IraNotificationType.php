<?php

namespace App\Enums;

enum IraNotificationType: string
{
    case DailyBriefing = 'daily_briefing';
    case SmartAssignment = 'smart_assignment';
    case RiskAlert = 'risk_alert';
    case UnassignedScheduledWork = 'unassigned_scheduled_work';
    case WaitingCustomerRisk = 'waiting_customer_risk';
    case TeamAvailabilityIssue = 'team_availability_issue';
    case IntegrationFailure = 'integration_failure';
    case UnusualBacklog = 'unusual_backlog';
    case TeamDailyBriefing = 'team_daily_briefing';
    case SupportSlotReminder = 'support_slot_reminder';
    case ManualAssignment = 'manual_assignment';
    case Reassignment = 'reassignment';
    case OpsDigest = 'ops_digest';

    public function label(): string
    {
        return match ($this) {
            self::DailyBriefing => 'Daily Briefing',
            self::SmartAssignment => 'Smart Assignment',
            self::TeamDailyBriefing => 'Team Daily Briefing',
            self::SupportSlotReminder => 'Support Slot Reminder',
            self::ManualAssignment => 'Manual Assignment',
            self::Reassignment => 'Reassignment',
            self::RiskAlert => 'Risk Alert',
            self::UnassignedScheduledWork => 'Unassigned Scheduled Work',
            self::WaitingCustomerRisk => 'Waiting Customer Risk',
            self::TeamAvailabilityIssue => 'Team Availability Issue',
            self::IntegrationFailure => 'Integration Failure',
            self::UnusualBacklog => 'Unusual Backlog',
            self::OpsDigest => 'Operations Digest',
        };
    }
}
