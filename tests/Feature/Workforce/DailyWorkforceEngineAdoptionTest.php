<?php

namespace Tests\Feature\Workforce;

use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Workforce\DailyWorkforceEngine;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use App\Services\Workforce\WorkforceMember360Service;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Milestone 2: Engine is the Workforce Management read façade.
 * Old matrix / Member 360 cores must match Engine-backed paths byte-for-byte on payloads.
 */
class DailyWorkforceEngineAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private MonthlyAttendanceMatrixService $matrix;

    private DailyWorkforceEngine $engine;

    private WorkforceMember360Service $member360;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->matrix = app(MonthlyAttendanceMatrixService::class);
        $this->engine = app(DailyWorkforceEngine::class);
        $this->member360 = app(WorkforceMember360Service::class);

        config([
            'presence.active_threshold_minutes' => 5,
            'presence.away_timeout_minutes' => 15,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_engine_matrix_matches_monthly_attendance_matrix_service(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Matrix Agent');
        $this->seedSession($agent, '2026-07-07', '09:00:00', onTime: true, activeSeconds: 6 * 3600, overtimeSeconds: 1800);
        $this->seedSession($agent, '2026-07-08', '09:20:00', onTime: false, activeSeconds: 5 * 3600);

        $month = Carbon::parse('2026-07-01');
        $at = Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata');

        $old = $this->matrix->build($month, $at);
        $new = $this->engine->matrix($month, $at);

        $this->assertSame($old->monthValue, $new->monthValue);
        $this->assertSame($old->monthLabel, $new->monthLabel);
        $this->assertSame($old->month->toDateString(), $new->month->toDateString());
        $this->assertSame($old->generatedAt->toIso8601String(), $new->generatedAt->toIso8601String());
        $this->assertSame($this->teamSummarySnapshot($old->teamSummary), $this->teamSummarySnapshot($new->teamSummary));
        $this->assertSame($this->dayHeadersSnapshot($old->days), $this->dayHeadersSnapshot($new->days));
        $this->assertSame($this->membersSnapshot($old->members), $this->membersSnapshot($new->members));
    }

    public function test_engine_month_ledger_matches_build_for_user_aggregate(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('Ledger Agent');
        $this->seedSession($agent, '2026-07-07', '09:00:00', onTime: true, activeSeconds: 6 * 3600);
        $this->seedSession($agent, '2026-07-08', '09:20:00', onTime: false, activeSeconds: 5 * 3600);

        $month = Carbon::parse('2026-07-01');
        $at = Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata');

        $oldRow = $this->matrix->buildForUser($agent, $month, $at);
        $ledger = $this->engine->month($agent, $month, $at);

        $this->assertSame($this->memberRowSnapshot($oldRow), $this->memberRowSnapshot($ledger->memberRow()));
        $this->assertSame($oldRow->summary->presentDays, $ledger->summary()->presentDays);
        $this->assertSame($oldRow->summary->absentDays, $ledger->summary()->absentDays);
        $this->assertSame($oldRow->summary->lateDays, $ledger->summary()->lateDays);
        $this->assertSame($oldRow->summary->leaveDays, $ledger->summary()->leaveDays);
        $this->assertSame($oldRow->summary->hoursLabel, $ledger->summary()->hoursLabel);
        $this->assertSame($oldRow->summary->overtimeLabel, $ledger->summary()->overtimeLabel);
    }

    public function test_member_360_via_engine_matches_matrix_backed_attendance_summary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent('360 Agent');
        $this->seedSession($agent, '2026-07-07', '09:00:00', onTime: true, activeSeconds: 6 * 3600, overtimeSeconds: 900);
        $this->seedSession($agent, '2026-07-08', '09:20:00', onTime: false, activeSeconds: 5 * 3600);

        $month = Carbon::parse('2026-07-01');
        $at = Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata');

        $matrixRow = $this->matrix->buildForUser($agent, $month, $at);
        $profile = $this->member360->build($agent, $month, '2026-07-07', $at);
        $attendance = $profile->attendance;

        $expectedPercent = $this->member360->attendancePercent(
            $matrixRow->summary->presentDays,
            $matrixRow->summary->absentDays,
            $matrixRow->summary->lateDays,
        );
        $denominator = $matrixRow->summary->presentDays
            + $matrixRow->summary->absentDays
            + $matrixRow->summary->lateDays;

        $this->assertSame($matrixRow->summary->presentDays, $attendance->presentDays);
        $this->assertSame($matrixRow->summary->absentDays, $attendance->absentDays);
        $this->assertSame($matrixRow->summary->leaveDays, $attendance->leaveDays);
        $this->assertSame($matrixRow->summary->halfDayDays, $attendance->halfDayDays);
        $this->assertSame($matrixRow->summary->extraDays, $attendance->extraDays);
        $this->assertSame($matrixRow->summary->payableDays, $attendance->payableDays);
        $this->assertSame($matrixRow->summary->lateDays, $attendance->lateDays);
        $this->assertSame($matrixRow->summary->hoursLabel, $attendance->hoursLabel);
        $this->assertSame($matrixRow->summary->overtimeLabel, $attendance->overtimeLabel);
        $this->assertSame($matrixRow->summary->activeDurationSeconds, $attendance->activeDurationSeconds);
        $this->assertSame($matrixRow->summary->overtimeSeconds, $attendance->overtimeSeconds);
        $this->assertSame($expectedPercent, $attendance->attendancePercent);
        $this->assertSame($denominator, $attendance->denominatorDays);
        $this->assertSame('2026-07', $attendance->monthValue);
        $this->assertSame('July 2026', $attendance->monthLabel);
        $this->assertSame('2026-07-07', $profile->focusedDay);

        $this->assertSame(
            $this->member360->attendanceTrendSeries($matrixRow),
            $profile->trends->attendanceSeries,
        );
        $this->assertSame(
            $this->member360->lateTrendSeries($matrixRow),
            $profile->trends->lateSeries,
        );
        $this->assertSame(
            $this->member360->otTrendSeries($matrixRow),
            $profile->trends->otSeries,
        );
    }

    public function test_attendance_controller_serves_engine_matrix_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = $this->createScheduledAgent('HTTP Agent');
        $this->seedSession($agent, '2026-07-14', '09:00:00', onTime: true, activeSeconds: 6 * 3600);

        $report = $this->engine->matrix(Carbon::parse('2026-07-01'));

        $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('data-summary="present"', false)
            ->assertSee('>'.$report->teamSummary->present.'<', false)
            ->assertSee($agent->name);
    }

    /**
     * @param  object{present: int, absent: int, leave: int, late: int, holiday: int}  $summary
     * @return array<string, int>
     */
    private function teamSummarySnapshot(object $summary): array
    {
        return [
            'present' => $summary->present,
            'absent' => $summary->absent,
            'leave' => $summary->leave,
            'late' => $summary->late,
            'holiday' => $summary->holiday,
        ];
    }

    /**
     * @param  list<object>  $days
     * @return list<array<string, mixed>>
     */
    private function dayHeadersSnapshot(array $days): array
    {
        return array_map(static fn (object $day): array => [
            'date' => $day->date instanceof Carbon ? $day->date->toDateString() : (string) $day->date,
            'dayNumber' => $day->dayNumber,
            'weekdayLabel' => $day->weekdayLabel,
            'isWeekend' => $day->isWeekend,
            'isHoliday' => $day->isHoliday,
            'isFuture' => $day->isFuture,
            'holidayName' => $day->holidayName,
        ], $days);
    }

    /**
     * @param  list<object>  $members
     * @return list<array<string, mixed>>
     */
    private function membersSnapshot(array $members): array
    {
        return array_map(fn (object $member): array => $this->memberRowSnapshot($member), $members);
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRowSnapshot(object $member): array
    {
        $cells = [];

        foreach ($member->cells as $date => $cell) {
            $cells[$date] = [
                'userId' => $cell->userId,
                'workDate' => $cell->workDate,
                'kind' => $cell->kind->value,
                'shortLabel' => $cell->shortLabel,
                'tone' => $cell->tone,
                'tooltip' => $cell->tooltip,
                'interactive' => $cell->interactive,
                'disabled' => $cell->disabled,
                'attendanceStatus' => $cell->attendanceStatus?->value,
                'drawerPayload' => $cell->drawerPayload,
            ];
        }

        return [
            'userId' => $member->userId,
            'name' => $member->name,
            'roleLabel' => $member->roleLabel,
            'summary' => [
                'presentDays' => $member->summary->presentDays,
                'absentDays' => $member->summary->absentDays,
                'leaveDays' => $member->summary->leaveDays,
                'lateDays' => $member->summary->lateDays,
                'holidayDays' => $member->summary->holidayDays,
                'weeklyOffDays' => $member->summary->weeklyOffDays,
                'extraDays' => $member->summary->extraDays,
                'activeDurationSeconds' => $member->summary->activeDurationSeconds,
                'overtimeSeconds' => $member->summary->overtimeSeconds,
                'hoursLabel' => $member->summary->hoursLabel,
                'overtimeLabel' => $member->summary->overtimeLabel,
            ],
            'cells' => $cells,
        ];
    }

    private function seedSession(
        User $agent,
        string $date,
        string $loginTime,
        bool $onTime,
        int $activeSeconds,
        int $overtimeSeconds = 0,
    ): void {
        $loginAt = Carbon::parse("{$date} {$loginTime}", 'Asia/Kolkata');
        $logoutAt = Carbon::parse("{$date} 18:00:00", 'Asia/Kolkata');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => $date,
            'login_at' => $loginAt,
            'logout_at' => $logoutAt,
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => (int) $loginAt->diffInSeconds($logoutAt),
            'on_time_login' => $onTime,
            'active_duration_seconds' => $activeSeconds,
            'overtime_seconds' => $overtimeSeconds,
            'expected_working_minutes' => 490,
        ]);
    }

    private function createScheduledAgent(string $name): User
    {
        $agent = User::factory()->create([
            'name' => $name,
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $agent->fresh(['workSchedule', 'roles']);
    }
}
