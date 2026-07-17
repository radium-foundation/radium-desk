<?php

namespace Tests\Feature;

use App\Enums\AttendanceDayStatus;
use App\Enums\CompanyHolidayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\CompanyHoliday;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Models\WorkSession;
use App\Services\Operations\CompanyHolidayService;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceRegisterFreshnessTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $leaveService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->leaveService = app(LeaveRequestService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));
        config(['workforce_calendar.retroactive_leave_days' => 2]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_leave_approval_refreshes_attendance_for_leave_range(): void
    {
        $agent = $this->createScheduledAgent();
        $operationsAdmin = $this->createOperationsAdmin();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-10',
            'login_at' => Carbon::parse('2026-07-10 09:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-10 18:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 32400,
            'on_time_login' => true,
        ]);

        $staleRow = WorkforceAttendanceDay::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-10',
            'status' => AttendanceDayStatus::Completed,
            'calendar_status' => 'working',
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 1,
            'computed_at' => now()->subHour(),
            'source_version' => 1,
        ]);

        $this->assertFalse($staleRow->is_on_leave);

        $leaveRequest = $this->leaveService->submit($agent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Family event',
        ]);

        $this->leaveService->approve($leaveRequest, $operationsAdmin, 'Approved for planned leave');

        $refreshed = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-10')
            ->first();

        $this->assertNotNull($refreshed);
        $this->assertSame(LeaveRequestStatus::Approved, $leaveRequest->fresh()->status);
        $this->assertTrue($refreshed->is_on_leave);
        $this->assertSame(AttendanceDayStatus::OnLeave, $refreshed->status);
    }

    public function test_holiday_create_refreshes_attendance_for_tracked_members(): void
    {
        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-10',
            'login_at' => Carbon::parse('2026-07-10 09:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-10 12:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 10800,
            'on_time_login' => true,
        ]);

        WorkforceAttendanceDay::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-10',
            'status' => AttendanceDayStatus::Completed,
            'calendar_status' => 'working',
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 1,
            'computed_at' => now()->subHour(),
            'source_version' => 1,
        ]);

        app(CompanyHolidayService::class)->create([
            'holiday_date' => '2026-07-10',
            'name' => 'Company offsite',
            'type' => CompanyHolidayType::Company->value,
        ]);

        $refreshed = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-10')
            ->first();

        $this->assertNotNull($refreshed);
        $this->assertTrue($refreshed->is_company_holiday);
        $this->assertSame(AttendanceDayStatus::Extra, $refreshed->status);
    }

    public function test_holiday_delete_refreshes_attendance_for_tracked_members(): void
    {
        $agent = $this->createScheduledAgent();

        $holiday = CompanyHoliday::query()->create([
            'holiday_date' => '2026-07-10',
            'name' => 'Temporary holiday',
            'type' => CompanyHolidayType::National,
        ]);

        WorkforceAttendanceDay::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-10',
            'status' => AttendanceDayStatus::ScheduledOff,
            'calendar_status' => 'holiday',
            'is_working_day' => false,
            'is_company_holiday' => true,
            'is_on_leave' => false,
            'has_schedule' => true,
            'session_count' => 0,
            'computed_at' => now()->subHour(),
            'source_version' => 1,
        ]);

        app(CompanyHolidayService::class)->delete($holiday);

        $refreshed = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-10')
            ->first();

        $this->assertNotNull($refreshed);
        $this->assertFalse($refreshed->is_company_holiday);
        $this->assertSame(AttendanceDayStatus::NotStarted, $refreshed->status);
    }

    public function test_scheduler_registers_nightly_attendance_reconciliation(): void
    {
        $events = collect(app(Schedule::class)->events());

        $nightly = $events->first(
            fn ($event): bool => $event->description === 'attendance:reconcile-days-nightly',
        );

        $this->assertNotNull($nightly);
        $this->assertSame('0 1 * * *', $nightly->getExpression());
    }

    public function test_nightly_reconciliation_command_limits_to_provided_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 01:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-09',
            'login_at' => Carbon::parse('2026-07-09 09:00:00', 'Asia/Kolkata'),
            'logout_at' => Carbon::parse('2026-07-09 18:00:00', 'Asia/Kolkata'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'session_duration_seconds' => 32400,
            'on_time_login' => true,
        ]);

        $this->artisan('attendance:reconcile-days', [
            '--from' => '2026-07-09',
            '--to' => '2026-07-10',
        ])->assertSuccessful();

        $this->assertSame(2, WorkforceAttendanceDay::query()->where('user_id', $agent->id)->count());
        $this->assertDatabaseMissing('workforce_attendance_days', [
            'user_id' => $agent->id,
            'work_date' => '2026-07-08',
        ]);

        $row = WorkforceAttendanceDay::query()
            ->where('user_id', $agent->id)
            ->whereDate('work_date', '2026-07-09')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(AttendanceDayStatus::Completed, $row->status);
    }

    private function createScheduledAgent(): User
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
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $agent->fresh(['workSchedule']);
    }

    private function createOperationsAdmin(): User
    {
        $operationsAdmin = User::factory()->create(['is_active' => true]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $operationsAdmin;
    }
}
