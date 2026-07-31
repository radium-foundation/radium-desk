<?php

namespace Tests\Feature\Workforce;

use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;
use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Operations\AttendanceDayCalculator;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HalfDayLeaveAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

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

    public function test_approved_half_day_leave_with_work_is_half_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->approveLeave($agent, '2026-07-15', LeaveDuration::HalfDay);
        $this->seedClosedSession($agent, '2026-07-15', '09:00:00', '13:00:00');

        $result = app(AttendanceDayCalculator::class)->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-15'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::HalfDay, $result->status);
        $this->assertTrue($result->isOnLeave);
        $this->assertGreaterThan(0, $result->sessionCount);

        $day = app(AttendanceRegisterService::class)->refreshDay(
            $agent,
            Carbon::parse('2026-07-15'),
            now(),
            allowPreShiftSkip: false,
        );

        $kind = app(AttendanceMatrixCellMapper::class)->kindFor(
            $day,
            Carbon::parse('2026-07-15'),
            now()->startOfDay(),
        );

        $this->assertSame(AttendanceMatrixCellKind::HalfDay, $kind);
        $this->assertSame(0.5, $kind->payableDayFraction());
    }

    public function test_approved_half_day_leave_without_work_is_half_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->approveLeave($agent, '2026-07-15', LeaveDuration::HalfDay);

        $result = app(AttendanceDayCalculator::class)->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-15'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::HalfDay, $result->status);
        $this->assertTrue($result->isOnLeave);
        $this->assertSame(0, $result->sessionCount);
        $this->assertSame(0.5, AttendanceMatrixCellKind::HalfDay->payableDayFraction());
    }

    public function test_approved_full_day_leave_is_leave(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 18:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();
        $this->approveLeave($agent, '2026-07-15', LeaveDuration::FullDay);
        $this->seedClosedSession($agent, '2026-07-15', '09:00:00', '13:00:00');

        $result = app(AttendanceDayCalculator::class)->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-15'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::OnLeave, $result->status);

        $kind = app(AttendanceMatrixCellMapper::class)->kindFor(
            app(AttendanceRegisterService::class)->refreshDay(
                $agent,
                Carbon::parse('2026-07-15'),
                now(),
                allowPreShiftSkip: false,
            ),
            Carbon::parse('2026-07-15'),
            now()->startOfDay(),
        );

        $this->assertSame(AttendanceMatrixCellKind::Leave, $kind);
        $this->assertSame(0.0, $kind->payableDayFraction());
    }

    public function test_sunday_extra_unaffected_by_half_day_leave(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-26 18:00:00', 'Asia/Kolkata')); // Sunday

        $agent = $this->createScheduledAgent(weeklyOffDays: [Carbon::SUNDAY]);
        $this->approveLeave($agent, '2026-07-26', LeaveDuration::HalfDay);
        $this->seedClosedSession($agent, '2026-07-26', '10:00:00', '14:00:00');

        $result = app(AttendanceDayCalculator::class)->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-26'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::Extra, $result->status);
    }

    public function test_monthly_summary_counts_half_day_separately_with_payable_fraction(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-31 18:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(weeklyOffDays: [Carbon::SUNDAY]);

        $this->approveLeave($agent, '2026-07-14', LeaveDuration::FullDay);
        $this->approveLeave($agent, '2026-07-15', LeaveDuration::HalfDay);
        $this->seedClosedSession($agent, '2026-07-15', '09:00:00', '13:00:00');
        $this->seedClosedSession($agent, '2026-07-16', '09:00:00', '18:00:00');
        $this->seedClosedSession($agent, '2026-07-26', '10:00:00', '14:00:00'); // Sunday Extra

        $row = app(MonthlyAttendanceMatrixService::class)->buildForUser(
            $agent,
            Carbon::parse('2026-07-01'),
            now(),
        );

        $this->assertSame(1, $row->summary->leaveDays);
        $this->assertSame(1, $row->summary->halfDayDays);
        $this->assertSame(1, $row->summary->extraDays);
        $this->assertGreaterThanOrEqual(1, $row->summary->presentDays);
        $this->assertSame(0.5, AttendanceMatrixCellKind::HalfDay->payableDayFraction());
        $this->assertEqualsWithDelta(
            $row->summary->presentDays + $row->summary->lateDays + 0.5,
            $row->summary->payableDays,
            0.01,
        );
    }

    private function createScheduledAgent(array $weeklyOffDays = [Carbon::SUNDAY]): User
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'weekly_off_days' => $weeklyOffDays,
            'effective_from' => '2000-01-01',
        ]);

        return $agent->fresh(['workSchedule', 'roles']);
    }

    private function approveLeave(User $user, string $date, LeaveDuration $duration): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'user_id' => $user->id,
            'start_date' => $date,
            'end_date' => $date,
            'reason' => $duration->label().' leave',
            'duration' => $duration,
            'status' => LeaveRequestStatus::Approved,
        ]);
    }

    private function seedClosedSession(
        User $user,
        string $date,
        string $loginTime,
        string $logoutTime,
    ): void {
        WorkSession::query()->create([
            'user_id' => $user->id,
            'work_date' => $date,
            'login_at' => Carbon::parse($date.' '.$loginTime, 'Asia/Kolkata'),
            'logout_at' => Carbon::parse($date.' '.$logoutTime, 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 4 * 3600,
            'active_duration_seconds' => 4 * 3600,
            'on_time_login' => true,
        ]);
    }
}
