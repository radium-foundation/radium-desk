<?php

namespace App\Data\Workforce;

use Illuminate\Support\Carbon;

readonly class AttendanceMatrixReport
{
    /**
     * @param  list<AttendanceMatrixDayHeader>  $days
     * @param  list<AttendanceMatrixMemberRow>  $members
     */
    public function __construct(
        public Carbon $month,
        public string $monthLabel,
        public string $monthValue,
        public array $days,
        public array $members,
        public AttendanceMatrixTeamSummary $teamSummary,
        public Carbon $generatedAt,
    ) {}
}
