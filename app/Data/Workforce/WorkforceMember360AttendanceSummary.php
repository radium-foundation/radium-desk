<?php

namespace App\Data\Workforce;

readonly class WorkforceMember360AttendanceSummary
{
    public function __construct(
        public string $monthLabel,
        public string $monthValue,
        public float $attendancePercent,
        public string $attendancePercentLabel,
        public int $presentDays,
        public int $halfDayDays,
        public int $absentDays,
        public int $leaveDays,
        public int $lateDays,
        public int $extraDays,
        public float $payableDays,
        public string $overtimeLabel,
        public string $hoursLabel,
        public int $activeDurationSeconds,
        public int $overtimeSeconds,
        public int $denominatorDays,
    ) {}
}
