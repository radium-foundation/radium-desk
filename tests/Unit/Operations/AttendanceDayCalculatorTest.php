<?php

namespace Tests\Unit\Operations;

use App\Enums\AttendanceDayStatus;
use App\Enums\CompanyHolidayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\CompanyHoliday;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Operations\AttendanceDayCalculator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceDayCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceDayCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->calculator = app(AttendanceDayCalculator::class);

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

    public function test_scheduled_off_on_weekly_off_without_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(weeklyOffDays: [Carbon::MONDAY]);

        $result = $this->calculator->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-06'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::ScheduledOff, $result->status);
    }

    public function test_on_leave_without_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-07',
            'end_date' => '2026-07-07',
            'reason' => 'Approved leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $result = $this->calculator->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::OnLeave, $result->status);
        $this->assertTrue($result->isOnLeave);
    }

    public function test_not_started_when_shift_started_without_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 10:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        $result = $this->calculator->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::NotStarted, $result->status);
    }

    public function test_skips_pre_shift_day_before_shift_start_when_allowed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        $result = $this->calculator->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
            allowPreShiftSkip: true,
        );

        $this->assertNull($result);
    }

    public function test_completed_for_on_time_closed_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-07',
            'login_at' => Carbon::parse('2026-07-07 08:58:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-07 18:05:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 33000,
            'on_time_login' => true,
        ]);

        $result = $this->calculator->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::Completed, $result->status);
        $this->assertTrue($result->onTimeLogin);
    }

    public function test_late_for_late_closed_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 18:30:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-07',
            'login_at' => Carbon::parse('2026-07-07 09:20:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-07 18:05:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 31500,
            'on_time_login' => false,
        ]);

        $result = $this->calculator->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::Late, $result->status);
        $this->assertFalse($result->onTimeLogin);
        $this->assertSame(20, $result->minutesLate);
    }

    public function test_active_for_open_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 11:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-07',
            'login_at' => Carbon::parse('2026-07-07 09:00:00', 'Asia/Kolkata'),
            'last_activity_at' => Carbon::parse('2026-07-07 10:58:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-07 10:58:00', 'Asia/Kolkata'),
            'on_time_login' => true,
        ]);

        $result = $this->calculator->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::Active, $result->status);
        $this->assertNull($result->finalizedAt);
    }

    public function test_away_for_open_session_past_timeout_threshold(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 11:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-07',
            'login_at' => Carbon::parse('2026-07-07 09:00:00', 'Asia/Kolkata'),
            'last_activity_at' => Carbon::parse('2026-07-07 10:40:00', 'Asia/Kolkata'),
            'last_tick_at' => Carbon::parse('2026-07-07 10:40:00', 'Asia/Kolkata'),
            'on_time_login' => true,
        ]);

        $result = $this->calculator->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::Away, $result->status);
    }

    public function test_extra_for_session_on_weekly_off(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent(weeklyOffDays: [Carbon::MONDAY]);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-06 12:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 7200,
            'on_time_login' => null,
        ]);

        $result = $this->calculator->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-06'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::Extra, $result->status);
    }

    public function test_scheduled_off_on_company_holiday_without_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 10:00:00', 'Asia/Kolkata'));

        CompanyHoliday::query()->create([
            'holiday_date' => '2026-07-07',
            'name' => 'Company Holiday',
            'type' => CompanyHolidayType::Company,
        ]);

        $agent = $this->createScheduledAgent();

        $result = $this->calculator->compute(
            user: $agent,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(AttendanceDayStatus::ScheduledOff, $result->status);
        $this->assertTrue($result->isCompanyHoliday);
    }

    public function test_non_attendance_tracked_user_returns_null(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $result = $this->calculator->compute(
            user: $owner,
            workDate: Carbon::parse('2026-07-07'),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNull($result);
    }

    /**
     * @param  list<int>  $weeklyOffDays
     */
    private function createScheduledAgent(array $weeklyOffDays = [Carbon::SUNDAY]): User
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => $weeklyOffDays,
        ]);

        return $agent->fresh(['workSchedule']);
    }
}
