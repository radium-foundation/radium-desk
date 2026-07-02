<?php

namespace App\Enums\Operations;

enum OperationsInsightCategory: string
{
    case SlaRisk = 'sla_risk';
    case CustomerRisk = 'customer_risk';
    case AutomationHealth = 'automation_health';
    case NotificationHealth = 'notification_health';
    case EngineerWorkload = 'engineer_workload';
    case RevenueOpportunity = 'revenue_opportunity';

    public function label(): string
    {
        return match ($this) {
            self::SlaRisk => 'SLA Risk',
            self::CustomerRisk => 'Customer Risk',
            self::AutomationHealth => 'Automation Health',
            self::NotificationHealth => 'Notification Health',
            self::EngineerWorkload => 'Engineer Workload',
            self::RevenueOpportunity => 'Revenue Opportunity',
        };
    }
}
