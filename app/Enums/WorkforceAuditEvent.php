<?php

namespace App\Enums;

enum WorkforceAuditEvent: string
{
    case LeaveSubmitted = 'workforce.leave.submitted';
    case LeaveApproved = 'workforce.leave.approved';
    case LeaveRejected = 'workforce.leave.rejected';
    case LeaveNotificationDispatched = 'workforce.leave.notification.dispatched';
    case PayrollLocked = 'workforce.payroll.locked';
    case PayrollUnlocked = 'workforce.payroll.unlocked';
    case PayrollFinalized = 'workforce.payroll.finalized';
    case RecognitionCreated = 'workforce.recognition.created';
    case RecognitionDecided = 'workforce.recognition.decided';

    public function legacyEvent(): string
    {
        return match ($this) {
            self::LeaveSubmitted => 'leave.submitted',
            self::LeaveApproved => 'leave.approved',
            self::LeaveRejected => 'leave.rejected',
            self::LeaveNotificationDispatched => 'leave.notification.dispatched',
            self::PayrollLocked => 'payroll.locked',
            self::PayrollUnlocked => 'payroll.unlocked',
            self::PayrollFinalized => 'payroll.finalized',
            self::RecognitionCreated => 'recognition.created',
            self::RecognitionDecided => 'recognition.decided',
        };
    }
}
