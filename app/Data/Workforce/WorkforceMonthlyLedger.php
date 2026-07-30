<?php

namespace App\Data\Workforce;

use Illuminate\Support\Carbon;

/**
 * Readonly monthly aggregate (DTO/view only).
 * Wraps existing MonthlyAttendanceMatrixService::buildForUser output.
 * No database table. No persistence.
 */
readonly class WorkforceMonthlyLedger
{
    public function __construct(
        private Carbon $month,
        private AttendanceMatrixMemberRow $memberRow,
    ) {}

    public static function fromMemberRow(Carbon $month, AttendanceMatrixMemberRow $memberRow): self
    {
        return new self($month->copy()->startOfMonth(), $memberRow);
    }

    public function month(): Carbon
    {
        return $this->month->copy();
    }

    public function monthValue(): string
    {
        return $this->month->format('Y-m');
    }

    public function monthLabel(): string
    {
        return $this->month->format('F Y');
    }

    public function userId(): int
    {
        return $this->memberRow->userId;
    }

    public function name(): string
    {
        return $this->memberRow->name;
    }

    public function roleLabel(): ?string
    {
        return $this->memberRow->roleLabel;
    }

    /**
     * @return array<string, AttendanceMatrixCell>
     */
    public function cells(): array
    {
        return $this->memberRow->cells;
    }

    public function summary(): AttendanceMatrixMemberSummary
    {
        return $this->memberRow->summary;
    }

    /**
     * Underlying matrix row — same object MonthlyAttendanceMatrixService returns.
     */
    public function memberRow(): AttendanceMatrixMemberRow
    {
        return $this->memberRow;
    }
}
