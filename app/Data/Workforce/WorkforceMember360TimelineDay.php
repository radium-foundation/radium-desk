<?php

namespace App\Data\Workforce;

use App\Enums\AttendanceMatrixCellKind;

readonly class WorkforceMember360TimelineDay
{
    public function __construct(
        public string $workDate,
        public string $dayLabel,
        public AttendanceMatrixCellKind $kind,
        public string $kindLabel,
        public string $tone,
        public ?string $loginLabel,
        public ?string $logoutLabel,
        public ?string $hoursLabel,
        public ?int $minutesLate,
        public bool $isFocused,
        public bool $isFuture,
    ) {}
}
