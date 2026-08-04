<?php

namespace Tests\Feature;

use App\Enums\CompanyHolidayType;
use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\CompanyHoliday;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Workforce\MonthlyAttendanceMatrixService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthlyAttendanceMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_unauthorized_users_cannot_access_attendance_page(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('workforce-management.attendance.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_matrix_for_selected_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $agent = $this->createAgentWithSchedule('Matrix Agent');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-14',
            'login_at' => Carbon::parse('2026-07-14 09:00:00'),
            'logout_at' => Carbon::parse('2026-07-14 18:00:00'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'on_time_login' => true,
            'active_duration_seconds' => 7 * 3600,
            'expected_working_minutes' => 490,
        ]);

        $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('Workforce Management')
            ->assertSee('Attendance')
            ->assertSee('Matrix Agent')
            ->assertSee('data-attendance-matrix', false)
            ->assertSee('Present')
            ->assertSee('Month totals')
            ->assertSee('Person-days')
            ->assertSee('P · Present', false)
            ->assertSee('L · Late', false)
            ->assertSee('V · Leave', false)
            ->assertSee('name="month"', false)
            ->assertSee('value="2026-07"', false)
            ->assertSee('data-attendance-search', false);
    }

    public function test_month_filter_defaults_to_current_month_and_accepts_query(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index'))
            ->assertOk()
            ->assertSee('value="2026-07"', false);

        $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index', ['month' => '2026-06']))
            ->assertOk()
            ->assertSee('value="2026-06"', false)
            ->assertSee('June 2026');
    }

    public function test_search_input_and_employee_row_data_attributes_are_rendered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $this->createAgentWithSchedule('Searchable Alpha');
        $this->createAgentWithSchedule('Other Beta');

        $html = $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index', ['month' => '2026-07']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-attendance-search', $html);
        $this->assertStringContainsString('data-employee-name="searchable alpha"', $html);
        $this->assertStringContainsString('data-employee-name="other beta"', $html);
    }

    public function test_team_summary_and_member_counts_reflect_register_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00', 'Asia/Kolkata'));

        CompanyHoliday::query()->create([
            'holiday_date' => '2026-07-08',
            'name' => 'Company Event',
            'type' => CompanyHolidayType::Company,
        ]);

        $admin = $this->createAdmin();
        $agent = $this->createAgentWithSchedule('Summary Agent');

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-09',
            'end_date' => '2026-07-09',
            'reason' => 'Personal',
            'status' => LeaveRequestStatus::Approved,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-06',
            'login_at' => Carbon::parse('2026-07-06 09:00:00'),
            'logout_at' => Carbon::parse('2026-07-06 18:00:00'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'on_time_login' => true,
            'active_duration_seconds' => 6 * 3600,
            'overtime_seconds' => 1800,
            'expected_working_minutes' => 490,
        ]);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-07',
            'login_at' => Carbon::parse('2026-07-07 10:30:00'),
            'logout_at' => Carbon::parse('2026-07-07 18:00:00'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'on_time_login' => false,
            'active_duration_seconds' => 5 * 3600,
            'expected_working_minutes' => 490,
        ]);

        $report = app(MonthlyAttendanceMatrixService::class)->build(Carbon::parse('2026-07-01'));
        $member = collect($report->members)->firstWhere('userId', $agent->id);

        $this->assertNotNull($member);
        $this->assertSame(1, $member->summary->presentDays);
        $this->assertSame(1, $member->summary->lateDays);
        $this->assertSame(1, $member->summary->leaveDays);
        $this->assertGreaterThanOrEqual(1, $member->summary->holidayDays);
        $this->assertSame(1, $report->teamSummary->present);
        $this->assertSame(1, $report->teamSummary->late);
        $this->assertSame(1, $report->teamSummary->leave);
        $this->assertGreaterThanOrEqual(1, $report->teamSummary->holiday);
        $this->assertSame('11h 0m', $member->summary->hoursLabel);
        $this->assertSame('30m', $member->summary->overtimeLabel);

        $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index', ['month' => '2026-07']))
            ->assertOk()
            ->assertSee('data-summary="present"', false)
            ->assertSee('>'.$report->teamSummary->present.'<', false);
    }

    public function test_future_days_render_as_disabled_cells(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $agent = $this->createAgentWithSchedule('Future Agent');

        $report = app(MonthlyAttendanceMatrixService::class)->build(Carbon::parse('2026-07-01'));
        $member = collect($report->members)->firstWhere('userId', $agent->id);
        $futureCell = $member->cells['2026-07-20'] ?? null;

        $this->assertNotNull($futureCell);
        $this->assertTrue($futureCell->disabled);
        $this->assertFalse($futureCell->interactive);
        $this->assertSame('future', $futureCell->kind->value);

        $html = $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index', ['month' => '2026-07']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('attendance-matrix-cell--future', $html);
        $this->assertStringContainsString('is-disabled', $html);
    }

    public function test_interactive_cells_include_drawer_payload_and_tooltip_data(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $agent = $this->createAgentWithSchedule('Drawer Agent');

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-14',
            'login_at' => Carbon::parse('2026-07-14 09:00:00'),
            'logout_at' => Carbon::parse('2026-07-14 18:00:00'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'on_time_login' => true,
            'active_duration_seconds' => 3600,
            'expected_working_minutes' => 490,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index', ['month' => '2026-07']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-attendance-drawer-trigger', $html);
        $this->assertStringContainsString('data-drawer-payload=', $html);
        $this->assertStringContainsString('data-work-date="2026-07-14"', $html);
        $this->assertStringContainsString('title="', $html);
    }

    public function test_workspace_nav_links_leave_and_hides_unimplemented_tabs(): void
    {
        config(['workforce_recognition.enabled' => false]);

        $admin = $this->createAdmin();

        $html = $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index'))
            ->assertOk()
            ->assertSee('Leave')
            ->assertDontSee('Calendar')
            ->assertDontSee('Performance')
            ->assertDontSee('Soon')
            ->getContent();

        $this->assertStringContainsString(route('leave-requests.index'), $html);
        $this->assertStringNotContainsString('Work Recognition', $html);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['name' => 'Ops Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createAgentWithSchedule(string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $user->update([
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $user->fresh(['workSchedule']);
    }
}
