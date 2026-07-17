<?php

namespace App\Enums;

enum WorkforceAuditEvent: string
{
    case LeaveSubmitted = 'workforce.leave.submitted';
    case LeaveApproved = 'workforce.leave.approved';
    case LeaveRejected = 'workforce.leave.rejected';
    case LeaveNotificationDispatched = 'workforce.leave.notification.dispatched';

    public function legacyEvent(): string
    {
        return match ($this) {
            self::LeaveSubmitted => 'leave.submitted',
            self::LeaveApproved => 'leave.approved',
            self::LeaveRejected => 'leave.rejected',
            self::LeaveNotificationDispatched => 'leave.notification.dispatched',
        };
    }
}
