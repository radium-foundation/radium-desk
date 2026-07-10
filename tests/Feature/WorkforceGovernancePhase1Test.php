<?php

namespace Tests\Feature;

use App\Enums\LeaveRequestStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\LeaveRequestService;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\SmartAssignmentService;
use App\Services\Operations\TeamTelegramQuietRulesService;
use App\Services\Operations\WorkCalendarService;
use App\Services\Operations\WorkforceAuthorityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkforceGovernancePhase1Test extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $leaveService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->leaveService = app(LeaveRequestService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_employee_leave_requires_operations_admin_approver(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $leaveRequest = $this->leaveService->submit($agent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        $this->assertTrue($this->leaveService->canReview($operationsAdmin, $leaveRequest));
        $this->assertFalse($this->leaveService->canReview($admin, $leaveRequest));
        $this->assertFalse($this->leaveService->canReview($owner, $leaveRequest));
    }

    public function test_escalation_specialist_leave_can_be_reviewed_by_operations_admin(): void
    {
        $specialist = User::factory()->create();
        $specialist->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $leaveRequest = $this->leaveService->submit($specialist, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-11',
            'reason' => 'Planned leave',
        ]);

        $this->assertTrue($this->leaveService->canReview($operationsAdmin, $leaveRequest));

        $this->leaveService->approve($leaveRequest, $operationsAdmin, 'Approved for planned leave');

        $this->assertSame(LeaveRequestStatus::Approved, $leaveRequest->fresh()->status);
    }

    public function test_operations_admin_and_admin_leave_require_superadmin(): void
    {
        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $operationsLeave = $this->leaveService->submit($operationsAdmin, [
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-16',
            'reason' => 'Operations leave',
        ]);

        $adminLeave = $this->leaveService->submit($admin, [
            'start_date' => '2026-07-17',
            'end_date' => '2026-07-18',
            'reason' => 'Admin leave',
        ]);

        $this->assertFalse($this->leaveService->canReview($operationsAdmin, $operationsLeave));
        $this->assertFalse($this->leaveService->canReview($admin, $operationsLeave));
        $this->assertTrue($this->leaveService->canReview($owner, $operationsLeave));
        $this->assertTrue($this->leaveService->canReview($owner, $adminLeave));
    }

    public function test_self_approval_is_blocked(): void
    {
        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $operationsLeave = $this->leaveService->submit($operationsAdmin, [
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-16',
            'reason' => 'Operations leave',
        ]);

        $ownerLeave = $this->leaveService->submit($owner, [
            'start_date' => '2026-07-20',
            'end_date' => '2026-07-21',
            'reason' => 'Owner leave',
        ]);

        $this->assertFalse($this->leaveService->canReview($operationsAdmin, $operationsLeave));
        $this->assertFalse($this->leaveService->canReview($owner, $ownerLeave));
    }

    public function test_review_note_is_required_for_approve_and_reject(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $leaveRequest = $this->leaveService->submit($agent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        try {
            $this->leaveService->approve($leaveRequest, $operationsAdmin, null);
            $this->fail('Expected validation exception for missing review notes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review_notes', $exception->errors());
        }

        try {
            $this->leaveService->reject($leaveRequest, $operationsAdmin, '   ');
            $this->fail('Expected validation exception for blank review notes.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('review_notes', $exception->errors());
        }
    }

    public function test_leave_actions_create_audit_logs(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $leaveRequest = $this->leaveService->submit($agent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'leave.submitted',
            'auditable_type' => LeaveRequest::class,
            'auditable_id' => $leaveRequest->id,
            'user_id' => $agent->id,
        ]);

        $this->leaveService->approve($leaveRequest, $operationsAdmin, 'Approved for planned leave');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'leave.approved',
            'auditable_type' => LeaveRequest::class,
            'auditable_id' => $leaveRequest->id,
            'user_id' => $operationsAdmin->id,
        ]);

        $approvedAudit = AuditLog::query()
            ->where('event', 'leave.approved')
            ->where('auditable_id', $leaveRequest->id)
            ->first();

        $this->assertSame($operationsAdmin->id, $approvedAudit?->new_values['reviewer_id'] ?? null);
        $this->assertSame('Approved for planned leave', $approvedAudit?->new_values['review_notes'] ?? null);
    }

    public function test_admin_roles_are_attendance_tracked_but_superadmin_is_not(): void
    {
        $roleService = app(OperationsRoleService::class);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->assertTrue($roleService->isAttendanceTracked($admin));
        $this->assertTrue($roleService->isAttendanceTracked($operationsAdmin));
        $this->assertFalse($roleService->isAttendanceTracked($owner));
        $this->assertTrue($roleService->isAttendanceTracked($agent));
    }

    public function test_admin_attendance_tracking_does_not_change_assignment_pool(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $admin->id,
            'work_start_time' => '09:30:00',
            'work_end_time' => '18:30:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        $admin = $admin->fresh(['workSchedule']);

        $session = app(PresenceEngineService::class)->startSession($admin);
        $this->assertNotNull($session);

        $authority = app(WorkforceAuthorityService::class);
        $this->assertFalse($authority->isEligibleForNormalAssignment($admin));
        $this->assertFalse(app(SmartAssignmentService::class)->isEligible($admin));
        $this->assertContains('not_assignment_pool', $authority->blockReasons($admin));
    }

    public function test_overnight_schedule_marks_late_evening_as_working(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 23:30:00', 'Asia/Kolkata'));

        $operationsAdmin = $this->createOvernightScheduleUser();

        $workCalendar = app(WorkCalendarService::class);

        $this->assertTrue($workCalendar->isWithinWorkingHours($operationsAdmin->workSchedule, now()));
        $this->assertTrue($workCalendar->isOnScheduledShift($operationsAdmin, now()));
        $this->assertSame(
            WorkCalendarDayStatus::Working->value,
            $workCalendar->todayStatusFor($operationsAdmin)['status'],
        );
        $this->assertTrue(app(TeamTelegramQuietRulesService::class)->shouldDeliver($operationsAdmin, now()));
    }

    public function test_overnight_schedule_marks_early_morning_after_midnight_as_not_working(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-07 01:00:00', 'Asia/Kolkata'));

        $operationsAdmin = $this->createOvernightScheduleUser();

        $workCalendar = app(WorkCalendarService::class);

        $this->assertFalse($workCalendar->isWithinWorkingHours($operationsAdmin->workSchedule, now()));
        $this->assertFalse($workCalendar->isOnScheduledShift($operationsAdmin, now()));
        $this->assertSame(
            WorkCalendarDayStatus::OutsideHours->value,
            $workCalendar->todayStatusFor($operationsAdmin)['status'],
        );
        $this->assertFalse(app(TeamTelegramQuietRulesService::class)->shouldDeliver($operationsAdmin, now()));
    }

    public function test_overnight_schedule_calculates_expected_working_minutes(): void
    {
        $operationsAdmin = $this->createOvernightScheduleUser();

        $minutes = app(WorkCalendarService::class)->expectedWorkingMinutes($operationsAdmin->workSchedule);

        $this->assertSame(840, $minutes);
    }

    private function createOvernightScheduleUser(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '10:00:00',
            'work_end_time' => '00:00:00',
            'lunch_start_time' => null,
            'lunch_end_time' => null,
            'short_break_count' => 0,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $user->fresh(['workSchedule']);
    }
}
