<?php

namespace App\Enums;

/**
 * Reasons for ExtraQualificationDecision (Rule Book §9 + Contribution Policy).
 */
enum ExtraQualificationReason: string
{
    case FeatureDisabled = 'feature_disabled';
    case NoAttendanceDay = 'no_attendance_day';
    case WorkingDayNeverExtra = 'working_day_never_extra';
    case LeaveNeverExtra = 'leave_never_extra';
    case WeeklyOffNoWork = 'weekly_off_no_work';
    case WeeklyOffInsufficientContribution = 'weekly_off_insufficient_contribution';
    case WeeklyOffQualified = 'weekly_off_qualified';
    case HolidayNoWork = 'holiday_no_work';
    case HolidayInsufficientContribution = 'holiday_insufficient_contribution';
    case HolidayQualified = 'holiday_qualified';

    public function label(): string
    {
        return match ($this) {
            self::FeatureDisabled => 'Extra qualification disabled — mirrored attendance Extra status',
            self::NoAttendanceDay => 'No attendance day to qualify',
            self::WorkingDayNeverExtra => 'Working day Present/attendance must not become EX',
            self::LeaveNeverExtra => 'Leave never qualifies as Extra',
            self::WeeklyOffNoWork => 'Weekly Off with no work remains WO',
            self::WeeklyOffInsufficientContribution => 'Weekly Off with login/insufficient contribution remains WO',
            self::WeeklyOffQualified => 'Weekly Off with qualified contribution → EX',
            self::HolidayNoWork => 'Holiday with no work remains Holiday',
            self::HolidayInsufficientContribution => 'Holiday with login/insufficient contribution remains Holiday',
            self::HolidayQualified => 'Holiday with qualified contribution → EX',
        };
    }
}
