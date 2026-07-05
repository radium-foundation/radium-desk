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

    public function label(): string
    {
        return match ($this) {
            self::DailyBriefing => 'Daily Briefing',
            self::SmartAssignment => 'Smart Assignment',
            self::RiskAlert => 'Risk Alert',
            self::UnassignedScheduledWork => 'Unassigned Scheduled Work',
            self::WaitingCustomerRisk => 'Waiting Customer Risk',
            self::TeamAvailabilityIssue => 'Team Availability Issue',
            self::IntegrationFailure => 'Integration Failure',
            self::UnusualBacklog => 'Unusual Backlog',
        };
    }
}
