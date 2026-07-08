<?php

namespace App\Enums;

enum NotificationCategory: string
{
    case Ivr = 'ivr';
    case LeaveApprovals = 'leave_approvals';
    case Finance = 'finance';
    case Assignment = 'assignment';
    case Escalation = 'escalation';
    case DailySummary = 'daily_summary';
    case SystemHealth = 'system_health';

    public function label(): string
    {
        return match ($this) {
            self::Ivr => 'IVR',
            self::LeaveApprovals => 'Leave approvals',
            self::Finance => 'Finance',
            self::Assignment => 'Assignment',
            self::Escalation => 'Escalation',
            self::DailySummary => 'Daily summary',
            self::SystemHealth => 'System health',
        };
    }
}
