<?php

namespace App\Data\Workforce;

readonly class AttendanceMatrixMemberRow
{
    /**
     * @param  array<string, AttendanceMatrixCell>  $cells  keyed by Y-m-d
     */
    public function __construct(
        public int $userId,
        public string $name,
        public ?string $roleLabel,
        public array $cells,
        public AttendanceMatrixMemberSummary $summary,
    ) {}
}
