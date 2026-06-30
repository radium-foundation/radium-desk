<?php

namespace App\Enums;

enum ServiceCaseAutomationStatus: string
{
    case AutomationPending = 'automation_pending';
    case WaitingRadiumbox = 'waiting_radiumbox';
    case ValidationFailed = 'validation_failed';
    case AssignedToAgent = 'assigned_to_agent';
    case AssignedToAdmin = 'assigned_to_admin';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::AutomationPending => 'Automation pending',
            self::WaitingRadiumbox => 'Waiting for RadiumBox',
            self::ValidationFailed => 'Validation failed',
            self::AssignedToAgent => 'Assigned to agent',
            self::AssignedToAdmin => 'Assigned to admin',
            self::Completed => 'Completed',
        };
    }
}
