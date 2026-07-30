<?php

namespace App\Data\Workforce;

readonly class AttendanceMatrixTeamSummary
{
    public function __construct(
        public int $present,
        public int $absent,
        public int $leave,
        public int $late,
        public int $holiday,
    ) {}
}
