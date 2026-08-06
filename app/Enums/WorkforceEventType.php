<?php

namespace App\Enums;

/**
 * Workforce Rule Book §8 Event Catalog.
 * Active producers: AttendanceRecorded, LeaveApproved, LeaveRejected,
 * PayrollLocked, PayrollFinalized, ContributionQualified / ExtraDayEarned (flag-gated),
 * WeeklyOffWorked / HolidayWorked / RecognitionRecommended / RecognitionDecided.
 * Remaining types are reserved.
 */
enum WorkforceEventType: string
{
    case AttendanceRecorded = 'workforce.attendance.recorded';
    case LeaveApproved = 'workforce.leave.approved';
    case LeaveRejected = 'workforce.leave.rejected';
    case LeaveCancelled = 'workforce.leave.cancelled';
    case HalfDayRecorded = 'workforce.attendance.half_day_recorded';
    case WeeklyOffWorked = 'workforce.contribution.weekly_off_worked';
    case HolidayWorked = 'workforce.contribution.holiday_worked';
    case ExtraDayEarned = 'workforce.extra.day_earned';
    case ContributionQualified = 'workforce.contribution.qualified';
    case PerformanceCalculated = 'workforce.performance.calculated';
    case SalesCredited = 'workforce.sales.credited';
    case IncentiveAwarded = 'workforce.incentive.awarded';
    case PayrollLocked = 'workforce.payroll.locked';
    case PayrollFinalized = 'workforce.payroll.finalized';
    case RecognitionRecommended = 'workforce.recognition.recommended';
    case RecognitionDecided = 'workforce.recognition.decided';
    case ShortAttendanceReviewDecided = 'workforce.attendance.short_review.decided';

    public function isReserved(): bool
    {
        return match ($this) {
            self::LeaveCancelled,
            self::HalfDayRecorded,
            self::PerformanceCalculated,
            self::SalesCredited,
            self::IncentiveAwarded => true,
            default => false,
        };
    }
}
