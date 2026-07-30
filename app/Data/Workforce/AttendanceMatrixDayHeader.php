<?php

namespace App\Data\Workforce;

use Illuminate\Support\Carbon;

readonly class AttendanceMatrixDayHeader
{
    public function __construct(
        public Carbon $date,
        public int $dayNumber,
        public string $weekdayLabel,
        public bool $isWeekend,
        public bool $isHoliday,
        public bool $isFuture,
        public ?string $holidayName,
    ) {}
}
