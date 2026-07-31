<?php

namespace Tests\Unit\Workforce;

use App\Data\Workforce\AttendanceMatrixCell;
use App\Data\Workforce\AttendanceMatrixMemberRow;
use App\Data\Workforce\AttendanceMatrixMemberSummary;
use App\Enums\AttendanceMatrixCellKind;
use App\Services\Workforce\WorkforceMember360Service;
use Tests\TestCase;

class WorkforceMember360ServiceTest extends TestCase
{
    public function test_attendance_percent_uses_present_absent_late_denominator(): void
    {
        $service = app(WorkforceMember360Service::class);

        $this->assertSame(50.0, $service->attendancePercent(1, 1, 0));
        $this->assertSame(66.7, $service->attendancePercent(2, 0, 1));
        $this->assertSame(0.0, $service->attendancePercent(0, 0, 0));
    }

    public function test_trend_series_map_cell_kinds_and_ot_seconds(): void
    {
        $service = app(WorkforceMember360Service::class);
        $row = new AttendanceMatrixMemberRow(
            userId: 1,
            name: 'Agent',
            roleLabel: 'Agent',
            cells: [
                '2026-07-01' => $this->cell('2026-07-01', AttendanceMatrixCellKind::Present, 0),
                '2026-07-02' => $this->cell('2026-07-02', AttendanceMatrixCellKind::Late, 1800),
                '2026-07-03' => $this->cell('2026-07-03', AttendanceMatrixCellKind::Absent, 0),
                '2026-07-04' => $this->cell('2026-07-04', AttendanceMatrixCellKind::Future, 0),
            ],
            summary: new AttendanceMatrixMemberSummary(
                presentDays: 1,
                absentDays: 1,
                leaveDays: 0,
                halfDayDays: 0,
                lateDays: 1,
                holidayDays: 0,
                weeklyOffDays: 0,
                extraDays: 0,
                payableDays: 2.0,
                activeDurationSeconds: 0,
                overtimeSeconds: 1800,
                hoursLabel: '0m',
                overtimeLabel: '30m',
            ),
        );

        $attendance = $service->attendanceTrendSeries($row);
        $late = $service->lateTrendSeries($row);
        $ot = $service->otTrendSeries($row);

        $this->assertSame(2, $attendance[0]['value']);
        $this->assertSame(1, $attendance[1]['value']);
        $this->assertSame(0, $attendance[2]['value']);
        $this->assertSame(-1, $attendance[3]['value']);

        $this->assertSame(0, $late[0]['value']);
        $this->assertSame(1, $late[1]['value']);

        $this->assertSame(0, $ot[0]['value']);
        $this->assertSame(1800, $ot[1]['value']);
    }

    private function cell(string $date, AttendanceMatrixCellKind $kind, int $otSeconds): AttendanceMatrixCell
    {
        return new AttendanceMatrixCell(
            userId: 1,
            workDate: $date,
            kind: $kind,
            shortLabel: $kind->shortLabel(),
            tone: $kind->tone(),
            tooltip: $kind->label(),
            interactive: $kind->isInteractive(),
            disabled: $kind === AttendanceMatrixCellKind::Future,
            attendanceStatus: null,
            drawerPayload: [
                'overtime_seconds' => $otSeconds,
            ],
        );
    }
}
