<?php

namespace Tests\Unit\Operations;

use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\WorkforceAuthorityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WorkforceAuthorityServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkforceAuthorityService $authority;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->authority = app(WorkforceAuthorityService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_approved_leave_overrides_available_status(): void
    {
        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        $this->openWorkSession($agent);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Approved leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->assertTrue($this->authority->isOnApprovedLeave($agent));
        $this->assertSame(TeamAvailabilityStatus::Offline, $this->authority->effectiveAvailability($agent));
        $this->assertFalse($this->authority->isOnDuty($agent));
        $this->assertFalse($this->authority->isEligibleForNormalAssignment($agent));
        $this->assertContains('approved_leave', $this->authority->blockReasons($agent));
    }

    public function test_no_active_session_blocks_duty(): void
    {
        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);

        $this->assertFalse($this->authority->isPresent($agent));
        $this->assertSame(TeamAvailabilityStatus::Offline, $this->authority->effectiveAvailability($agent));
        $this->assertFalse($this->authority->isOnDuty($agent));
        $this->assertFalse($this->authority->isEligibleForNormalAssignment($agent));
        $this->assertContains('not_present', $this->authority->blockReasons($agent));
    }

    public function test_available_with_active_session_allows_duty(): void
    {
        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        $this->openWorkSession($agent);

        $this->assertTrue($this->authority->isPresent($agent));
        $this->assertSame(TeamAvailabilityStatus::Available, $this->authority->effectiveAvailability($agent));
        $this->assertTrue($this->authority->isOnDuty($agent));
        $this->assertTrue($this->authority->isEligibleForNormalAssignment($agent));
    }

    public function test_busy_remains_on_duty(): void
    {
        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Busy);
        $this->openWorkSession($agent);

        $this->assertSame(TeamAvailabilityStatus::Busy, $this->authority->effectiveAvailability($agent));
        $this->assertTrue($this->authority->isOnDuty($agent));
        $this->assertTrue($this->authority->isEligibleForNormalAssignment($agent));
    }

    public function test_offline_blocks_duty(): void
    {
        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        $this->openWorkSession($agent);

        app(\App\Services\Operations\TeamAvailabilityService::class)->updateStatus(
            $agent,
            TeamAvailabilityStatus::Offline,
        );
        $agent->refresh();

        $this->assertSame(TeamAvailabilityStatus::Offline, $this->authority->effectiveAvailability($agent));
        $this->assertFalse($this->authority->isOnDuty($agent));
        $this->assertFalse($this->authority->isEligibleForNormalAssignment($agent));
        $this->assertContains('availability_offline', $this->authority->blockReasons($agent));
    }

    public function test_admin_role_excluded_from_normal_assignment(): void
    {
        $admin = User::factory()->create([
            'availability_status' => TeamAvailabilityStatus::Available,
            'availability_updated_at' => now(),
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $admin->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        $this->assertNull(app(PresenceEngineService::class)->startSession($admin->fresh(['workSchedule'])));

        $this->assertFalse($this->authority->isOnDuty($admin));
        $this->assertFalse($this->authority->isEligibleForNormalAssignment($admin));
        $this->assertContains('not_assignment_pool', $this->authority->blockReasons($admin));
    }

    public function test_attendance_tracked_roles_include_hardware_team(): void
    {
        $hardwareUser = User::factory()->create();
        $hardwareUser->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $roleService = app(\App\Services\Operations\OperationsRoleService::class);

        $this->assertTrue($roleService->isAttendanceTracked($hardwareUser));
        $this->assertFalse($roleService->isNormalAssignmentPool($hardwareUser));
        $this->assertFalse($roleService->isAttendanceTracked($admin));
        $this->assertFalse($roleService->isNormalAssignmentPool($admin));
    }

    public function test_snapshot_for_includes_authority_fields(): void
    {
        $agent = $this->createScheduledAgent(TeamAvailabilityStatus::Available);
        $this->openWorkSession($agent);

        $snapshot = $this->authority->snapshotFor($agent);

        $this->assertTrue($snapshot['calendar_allows']);
        $this->assertFalse($snapshot['on_approved_leave']);
        $this->assertTrue($snapshot['is_present']);
        $this->assertSame('available', $snapshot['stored_availability']);
        $this->assertSame('available', $snapshot['effective_availability']);
        $this->assertTrue($snapshot['on_duty']);
        $this->assertTrue($snapshot['eligible_for_normal_assignment']);
        $this->assertArrayHasKey('work_calendar', $snapshot);
        $this->assertArrayHasKey('presence', $snapshot);
        $this->assertArrayHasKey('availability', $snapshot);
    }

    private function createScheduledAgent(TeamAvailabilityStatus $status): User
    {
        $user = User::factory()->create([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

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

    private function openWorkSession(User $user): void
    {
        app(PresenceEngineService::class)->startSession($user);
    }
}
