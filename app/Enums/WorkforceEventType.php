<?php

namespace App\Enums;

/**
 * Workforce Rule Book §8 Event Catalog.
 * Active producers: AttendanceRecorded, LeaveApproved, LeaveRejected,
 * ContributionQualified / ExtraDayEarned (flag-gated). Remaining types are reserved.
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

    public function isReserved(): bool
    {
        return match ($this) {
            self::LeaveCancelled,
            self::HalfDayRecorded,
            self::WeeklyOffWorked,
            self::HolidayWorked,
            self::PerformanceCalculated,
            self::SalesCredited,
            self::IncentiveAwarded,
            self::PayrollLocked => true,
            default => false,
        };
    }
}
