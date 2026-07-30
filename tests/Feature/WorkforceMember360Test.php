<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkforceMember360Test extends TestCase
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

    public function test_unauthorized_users_cannot_open_member_360(): void
    {
        $agent = $this->createAgentWithSchedule('Blocked Agent');

        $this->actingAs($agent)
            ->get(route('workforce-management.members.show', $agent))
            ->assertForbidden();
    }

    public function test_admin_can_load_member_360_fragment(): void
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
            'active_duration_seconds' => 6 * 3600,
            'expected_working_minutes' => 490,
        ]);

        $this->actingAs($admin)
            ->get(route('workforce-management.members.show', [
                'user' => $agent->id,
                'month' => '2026-07',
            ]))
            ->assertOk()
            ->assertSee('Drawer Agent')
            ->assertSee('Attendance Summary')
            ->assertSee('data-wm360-attendance-percent', false)
            ->assertSee('Overview')
            ->assertSee('Soon');
    }

    public function test_focused_day_is_highlighted_in_timeline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $agent = $this->createAgentWithSchedule('Focus Agent');

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
            ->get(route('workforce-management.members.show', [
                'user' => $agent->id,
                'month' => '2026-07',
                'day' => '2026-07-14',
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-focused-day="2026-07-14"', $html);
        $this->assertStringContainsString('id="wm360-focused-day"', $html);
        $this->assertStringContainsString('data-wm360-day="2026-07-14"', $html);
    }

    public function test_upcoming_leave_and_timeline_are_rendered(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $agent = $this->createAgentWithSchedule('Leave Agent');

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-21',
            'reason' => 'Family travel',
            'status' => LeaveRequestStatus::Approved,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);

        WorkSession::query()->create([
            'user_id' => $agent->id,
            'work_date' => '2026-07-09',
            'login_at' => Carbon::parse('2026-07-09 09:00:00'),
            'logout_at' => Carbon::parse('2026-07-09 18:00:00'),
            'ended_reason' => WorkSessionEndReason::ManualLogout,
            'on_time_login' => true,
            'active_duration_seconds' => 5 * 3600,
            'expected_working_minutes' => 490,
        ]);

        $this->actingAs($admin)
            ->get(route('workforce-management.members.show', [
                'user' => $agent->id,
                'month' => '2026-07',
            ]))
            ->assertOk()
            ->assertSee('Family travel')
            ->assertSee('Upcoming')
            ->assertSee('Attendance Timeline')
            ->assertSee('Jul 9')
            ->assertSee('Working Hours');
    }

    public function test_attendance_page_includes_member_360_host_and_employee_trigger(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $this->createAgentWithSchedule('Clickable Agent');

        $html = $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index', ['month' => '2026-07']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-wm360-drawer', $html);
        $this->assertStringContainsString('data-wm360-open-member', $html);
        $this->assertStringContainsString('data-attendance-drawer-trigger', $html);
    }

    public function test_non_tracked_user_returns_not_found(): void
    {
        $admin = $this->createAdmin();
        $customer = User::factory()->create(['name' => 'Not Tracked']);

        $this->actingAs($admin)
            ->get(route('workforce-management.members.show', $customer))
            ->assertNotFound();
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
