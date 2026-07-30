<?php

namespace App\Data\Workforce;

use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;

readonly class AttendanceMatrixCell
{
    /**
     * @param  array<string, mixed>  $drawerPayload
     */
    public function __construct(
        public int $userId,
        public string $workDate,
        public AttendanceMatrixCellKind $kind,
        public string $shortLabel,
        public string $tone,
        public string $tooltip,
        public bool $interactive,
        public bool $disabled,
        public ?AttendanceDayStatus $attendanceStatus,
        public array $drawerPayload,
    ) {}
}
