<?php

namespace App\Enums;

enum WorkforceAuditEvent: string
{
    case LeaveSubmitted = 'workforce.leave.submitted';
    case LeaveApproved = 'workforce.leave.approved';
    case LeaveRejected = 'workforce.leave.rejected';
    case LeaveCancelled = 'workforce.leave.cancelled';
    case LeaveUpdated = 'workforce.leave.updated';
    case LeaveAmendmentSubmitted = 'workforce.leave.amendment.submitted';
    case LeaveAmendmentApproved = 'workforce.leave.amendment.approved';
    case LeaveAmendmentRejected = 'workforce.leave.amendment.rejected';
    case LeaveNotificationDispatched = 'workforce.leave.notification.dispatched';
    case PayrollLocked = 'workforce.payroll.locked';
    case PayrollUnlocked = 'workforce.payroll.unlocked';
    case PayrollFinalized = 'workforce.payroll.finalized';
    case RecognitionCreated = 'workforce.recognition.created';
    case RecognitionDecided = 'workforce.recognition.decided';
    case ShortAttendanceReviewCreated = 'workforce.attendance.short_review.created';
    case ShortAttendanceReviewDecided = 'workforce.attendance.short_review.decided';

    public function legacyEvent(): string
    {
        return match ($this) {
            self::LeaveSubmitted => 'leave.submitted',
            self::LeaveApproved => 'leave.approved',
            self::LeaveRejected => 'leave.rejected',
            self::LeaveCancelled => 'leave.cancelled',
            self::LeaveUpdated => 'leave.updated',
            self::LeaveAmendmentSubmitted => 'leave.amendment.submitted',
            self::LeaveAmendmentApproved => 'leave.amendment.approved',
            self::LeaveAmendmentRejected => 'leave.amendment.rejected',
            self::LeaveNotificationDispatched => 'leave.notification.dispatched',
            self::PayrollLocked => 'payroll.locked',
            self::PayrollUnlocked => 'payroll.unlocked',
            self::PayrollFinalized => 'payroll.finalized',
            self::RecognitionCreated => 'recognition.created',
            self::RecognitionDecided => 'recognition.decided',
            self::ShortAttendanceReviewCreated => 'attendance.short_review.created',
            self::ShortAttendanceReviewDecided => 'attendance.short_review.decided',
        };
    }
}
