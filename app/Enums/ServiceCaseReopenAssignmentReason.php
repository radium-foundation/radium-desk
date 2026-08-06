<?php

namespace App\Enums;

enum ServiceCaseReopenAssignmentReason: string
{
    case RefundWorkflow = 'refund_workflow';
    case LastOwner = 'last_owner';
    case Manual = 'manual';
    case DefaultRouting = 'default_routing';

    public function label(): string
    {
        return match ($this) {
            self::RefundWorkflow => 'Refund Workflow',
            self::LastOwner => 'Last Owner',
            self::Manual => 'Manual',
            self::DefaultRouting => 'Default Routing',
        };
    }
}
