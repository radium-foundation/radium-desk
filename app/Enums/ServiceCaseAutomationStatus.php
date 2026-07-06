<?php

namespace App\Enums;

enum ServiceCaseAutomationStatus: string
{
    case AutomationPending = 'automation_pending';
    case WaitingRadiumbox = 'waiting_radiumbox';
    case WaitingForCustomerSerial = 'waiting_for_customer_serial';
    case ValidationFailed = 'validation_failed';
    case ValidationWarning = 'validation_warning';
    case AssignedToAgent = 'assigned_to_agent';
    case AssignedToAdmin = 'assigned_to_admin';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::AutomationPending => 'Automation pending',
            self::WaitingRadiumbox => 'Waiting for RadiumBox',
            self::WaitingForCustomerSerial => 'Waiting for Customer Serial',
            self::ValidationFailed => 'Validation failed',
            self::ValidationWarning => 'Serial needs review',
            self::AssignedToAgent => 'Assigned to team member',
            self::AssignedToAdmin => 'Assigned to admin',
            self::Completed => 'Completed',
        };
    }
}
