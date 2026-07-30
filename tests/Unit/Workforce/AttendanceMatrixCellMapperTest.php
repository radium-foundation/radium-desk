<?php

namespace Tests\Unit\Workforce;

use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;
use App\Models\WorkforceAttendanceDay;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceMatrixCellMapperTest extends TestCase
{
    private AttendanceMatrixCellMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new AttendanceMatrixCellMapper;
    }

    public function test_future_dates_map_to_future_kind(): void
    {
        $today = Carbon::parse('2026-07-15');
        $kind = $this->mapper->kindFor(null, Carbon::parse('2026-07-20'), $today);

        $this->assertSame(AttendanceMatrixCellKind::Future, $kind);
        $this->assertFalse($kind->isInteractive());
    }

    public function test_maps_register_statuses_without_reinventing_math(): void
    {
        $today = Carbon::parse('2026-07-15');

        $cases = [
            [AttendanceDayStatus::Completed, false, false, true, AttendanceMatrixCellKind::Present],
            [AttendanceDayStatus::Late, false, false, false, AttendanceMatrixCellKind::Late],
            [AttendanceDayStatus::Active, false, false, false, AttendanceMatrixCellKind::Late],
            [AttendanceDayStatus::NotStarted, true, false, null, AttendanceMatrixCellKind::Absent],
            [AttendanceDayStatus::OnLeave, true, false, null, AttendanceMatrixCellKind::Leave],
            [AttendanceDayStatus::ScheduledOff, false, true, null, AttendanceMatrixCellKind::Holiday],
            [AttendanceDayStatus::ScheduledOff, false, false, null, AttendanceMatrixCellKind::WeeklyOff],
            [AttendanceDayStatus::Extra, false, true, true, AttendanceMatrixCellKind::Extra],
        ];

        foreach ($cases as [$status, $isWorkingDay, $isHoliday, $onTime, $expected]) {
            $day = new WorkforceAttendanceDay([
                'status' => $status,
                'is_working_day' => $isWorkingDay,
                'is_company_holiday' => $isHoliday,
                'on_time_login' => $onTime,
            ]);

            $this->assertSame(
                $expected,
                $this->mapper->kindFor($day, Carbon::parse('2026-07-10'), $today),
                "Failed for status {$status->value}",
            );
        }
    }

    public function test_tooltip_includes_login_leave_and_holiday_context(): void
    {
        $day = new WorkforceAttendanceDay([
            'status' => AttendanceDayStatus::OnLeave,
            'first_login_at' => null,
            'active_duration_seconds' => 0,
            'overtime_seconds' => 0,
        ]);

        $tooltip = $this->mapper->tooltipFor(
            AttendanceMatrixCellKind::Leave,
            $day,
            Carbon::parse('2026-07-10'),
            ['leave_reason' => 'Family event'],
        );

        $this->assertStringContainsString('Leave', $tooltip);
        $this->assertStringContainsString('Reason: Family event', $tooltip);
        $this->assertStringContainsString('Register: On leave', $tooltip);
    }

    public function test_drawer_payload_exposes_register_fields(): void
    {
        $day = new WorkforceAttendanceDay([
            'status' => AttendanceDayStatus::Completed,
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'first_login_at' => Carbon::parse('2026-07-10 09:05:00'),
            'last_logout_at' => Carbon::parse('2026-07-10 18:00:00'),
            'on_time_login' => true,
            'minutes_late' => null,
            'session_count' => 1,
            'active_duration_seconds' => 3600,
            'overtime_seconds' => 0,
            'away_timeout_count' => 0,
            'manual_logout_count' => 1,
        ]);

        $payload = $this->mapper->drawerPayload(
            userId: 42,
            employeeName: 'Asha Agent',
            workDate: Carbon::parse('2026-07-10'),
            kind: AttendanceMatrixCellKind::Present,
            day: $day,
        );

        $this->assertSame(42, $payload['user_id']);
        $this->assertSame('Asha Agent', $payload['employee_name']);
        $this->assertSame('2026-07-10', $payload['work_date']);
        $this->assertSame('present', $payload['kind']);
        $this->assertSame('completed', $payload['attendance_status']);
        $this->assertSame(3600, $payload['active_duration_seconds']);
        $this->assertNotNull($payload['first_login_at']);
    }
}
