<?php

namespace App\Data\Workforce;

readonly class AttendanceMatrixMemberSummary
{
    public function __construct(
        public int $presentDays,
        public int $absentDays,
        public int $leaveDays,
        public int $lateDays,
        public int $holidayDays,
        public int $weeklyOffDays,
        public int $extraDays,
        public int $activeDurationSeconds,
        public int $overtimeSeconds,
        public string $hoursLabel,
        public string $overtimeLabel,
    ) {}
}
