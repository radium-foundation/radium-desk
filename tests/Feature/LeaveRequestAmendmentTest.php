<?php

namespace Tests\Feature;

use App\Enums\AttendanceDayStatus;
use App\Enums\LeaveAmendmentStatus;
use App\Enums\LeaveAmendmentType;
use App\Enums\LeaveRequestStatus;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestAmendment;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\AttendanceDayCalculator;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Operations\LeaveRequestAmendmentService;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LeaveRequestAmendmentTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $leaveService;

    private LeaveRequestAmendmentService $amendmentService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->leaveService = app(LeaveRequestService::class);
        $this->amendmentService = app(LeaveRequestAmendmentService::class);

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00', 'Asia/Kolkata'));
        config([
            'workforce.leave_approver.email' => 'leave-authority@radiumbox.com',
            'workforce_calendar.retroactive_leave_days' => 14,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_agent_can_create_normal_leave_request(): void
    {
        $agent = $this->createAgent();

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-12',
            'end_date' => '2026-08-14',
            'reason' => 'Family event',
        ]);

        $this->assertSame(LeaveRequestStatus::Pending, $leave->status);
    }

    public function test_agent_can_edit_pending_leave_request(): void
    {
        $agent = $this->createAgent();
        $leave = $this->createPendingLeave($agent, '2026-08-12', '2026-08-14');

        $this->actingAs($agent)
            ->put(route('leave-requests.update', $leave), [
                'start_date' => '2026-08-13',
                'end_date' => '2026-08-15',
                'duration' => 'full_day',
                'reason' => 'Updated reason',
            ])
            ->assertRedirect(route('leave-requests.show', $leave));

        $leave->refresh();
        $this->assertSame('2026-08-13', $leave->start_date->toDateString());
        $this->assertSame('Updated reason', $leave->reason);
    }

    public function test_agent_cannot_directly_edit_approved_leave(): void
    {
        $agent = $this->createAgent();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $this->actingAs($agent)
            ->put(route('leave-requests.update', $leave), [
                'start_date' => '2026-08-13',
                'end_date' => '2026-08-15',
                'duration' => 'full_day',
                'reason' => 'Should fail',
            ])
            ->assertForbidden();
    }

    public function test_agent_can_request_cancellation_of_approved_leave(): void
    {
        $agent = $this->createAgent();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $this->actingAs($agent)
            ->post(route('leave-requests.amendments.store', $leave), [
                'type' => LeaveAmendmentType::Cancellation->value,
                'reason' => 'Plans changed',
            ])
            ->assertRedirect(route('leave-requests.show', $leave));

        $amendment = LeaveRequestAmendment::query()->first();
        $this->assertSame(LeaveAmendmentType::Cancellation, $amendment->type);
        $this->assertSame(LeaveAmendmentStatus::Pending, $amendment->status);
        $this->assertSame(LeaveRequestStatus::Approved, $leave->fresh()->status);
    }

    public function test_agent_can_request_date_change_of_approved_leave(): void
    {
        $agent = $this->createAgent();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $this->actingAs($agent)
            ->post(route('leave-requests.amendments.store', $leave), [
                'type' => LeaveAmendmentType::DateChange->value,
                'proposed_start_date' => '2026-08-12',
                'proposed_end_date' => '2026-08-13',
                'proposed_duration' => 'full_day',
                'reason' => 'Shorter leave',
            ])
            ->assertRedirect(route('leave-requests.show', $leave));

        $amendment = LeaveRequestAmendment::query()->first();
        $this->assertSame(LeaveAmendmentType::DateChange, $amendment->type);
        $this->assertSame('2026-08-13', $amendment->proposed_end_date->toDateString());
    }

    public function test_agent_can_request_extension_of_approved_leave(): void
    {
        $agent = $this->createAgent();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $this->actingAs($agent)
            ->post(route('leave-requests.amendments.store', $leave), [
                'type' => LeaveAmendmentType::DateChange->value,
                'proposed_start_date' => '2026-08-12',
                'proposed_end_date' => '2026-08-18',
                'proposed_duration' => 'full_day',
                'reason' => 'Need more time',
            ])
            ->assertRedirect(route('leave-requests.show', $leave));

        $amendment = LeaveRequestAmendment::query()->first();
        $this->assertSame('2026-08-18', $amendment->proposed_end_date->toDateString());
    }

    public function test_amendment_remains_pending_until_approval(): void
    {
        $agent = $this->createAgent();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $amendment = $this->amendmentService->submitAgentAmendment($agent, $leave, [
            'type' => LeaveAmendmentType::DateChange->value,
            'proposed_start_date' => '2026-08-12',
            'proposed_end_date' => '2026-08-18',
            'proposed_duration' => 'full_day',
            'reason' => 'Extension',
        ]);

        $this->assertSame(LeaveAmendmentStatus::Pending, $amendment->status);
        $this->assertSame('2026-08-14', $leave->fresh()->end_date->toDateString());
    }

    public function test_hr_can_approve_amendment(): void
    {
        $agent = $this->createAgent();
        $manager = $this->createLeaveManager();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $amendment = $this->amendmentService->submitAgentAmendment($agent, $leave, [
            'type' => LeaveAmendmentType::DateChange->value,
            'proposed_start_date' => '2026-08-12',
            'proposed_end_date' => '2026-08-18',
            'proposed_duration' => 'full_day',
            'reason' => 'Extension',
        ]);

        $this->actingAs($manager)
            ->post(route('leave-request-amendments.approve', $amendment), [
                'review_notes' => 'Approved extension',
            ])
            ->assertRedirect(route('leave-requests.show', $leave));

        $this->assertSame(LeaveAmendmentStatus::Approved, $amendment->fresh()->status);
    }

    public function test_hr_can_reject_amendment(): void
    {
        $agent = $this->createAgent();
        $manager = $this->createLeaveManager();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $amendment = $this->amendmentService->submitAgentAmendment($agent, $leave, [
            'type' => LeaveAmendmentType::Cancellation->value,
            'reason' => 'Changed mind',
        ]);

        $this->actingAs($manager)
            ->post(route('leave-request-amendments.reject', $amendment), [
                'review_notes' => 'Cannot cancel now',
            ])
            ->assertRedirect(route('leave-requests.show', $leave));

        $this->assertSame(LeaveAmendmentStatus::Rejected, $amendment->fresh()->status);
        $this->assertSame(LeaveRequestStatus::Approved, $leave->fresh()->status);
    }

    public function test_approved_amendment_updates_leave_dates(): void
    {
        $agent = $this->createAgent();
        $manager = $this->createLeaveManager();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $amendment = $this->amendmentService->submitAgentAmendment($agent, $leave, [
            'type' => LeaveAmendmentType::DateChange->value,
            'proposed_start_date' => '2026-08-12',
            'proposed_end_date' => '2026-08-18',
            'proposed_duration' => 'full_day',
            'reason' => 'Extension',
        ]);

        $this->amendmentService->approve($amendment, $manager, 'Approved');

        $leave->refresh();
        $this->assertSame('2026-08-18', $leave->end_date->toDateString());
        $this->assertSame(LeaveRequestStatus::Approved, $leave->status);
    }

    public function test_hr_can_cancel_approved_leave(): void
    {
        $agent = $this->createAgent();
        $manager = $this->createLeaveManager();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $this->actingAs($manager)
            ->post(route('leave-requests.manage-cancel', $leave), [
                'reason' => 'No longer needed',
                'review_notes' => 'Cancelled by HR',
            ])
            ->assertRedirect(route('leave-requests.show', $leave));

        $this->assertSame(LeaveRequestStatus::Cancelled, $leave->fresh()->status);
    }

    public function test_cancellation_updates_attendance_correctly(): void
    {
        $agent = $this->createScheduledAgent();
        $manager = $this->createLeaveManager();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        app(AttendanceRegisterService::class)->refreshDateRange(
            $agent,
            Carbon::parse('2026-08-12'),
            Carbon::parse('2026-08-14'),
        );

        $this->assertSame(
            AttendanceDayStatus::OnLeave,
            $this->attendanceStatusFor($agent, '2026-08-12'),
        );

        $this->amendmentService->manageCancellation($manager, $leave, [
            'reason' => 'Cancelled',
            'review_notes' => 'HR cancel',
        ]);

        $this->assertNotSame(
            AttendanceDayStatus::OnLeave,
            $this->attendanceStatusFor($agent, '2026-08-12'),
        );
    }

    public function test_date_extension_updates_attendance_correctly(): void
    {
        $agent = $this->createScheduledAgent();
        $manager = $this->createLeaveManager();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        app(AttendanceRegisterService::class)->refreshDateRange(
            $agent,
            Carbon::parse('2026-08-12'),
            Carbon::parse('2026-08-14'),
        );

        $this->assertNotSame(
            AttendanceDayStatus::OnLeave,
            $this->attendanceStatusFor($agent, '2026-08-18'),
        );

        $amendment = $this->amendmentService->submitAgentAmendment($agent, $leave, [
            'type' => LeaveAmendmentType::DateChange->value,
            'proposed_start_date' => '2026-08-12',
            'proposed_end_date' => '2026-08-18',
            'proposed_duration' => 'full_day',
            'reason' => 'Extension',
        ]);

        $this->amendmentService->approve($amendment, $manager, 'Approved');

        $this->assertSame(
            AttendanceDayStatus::OnLeave,
            $this->attendanceStatusFor($agent, '2026-08-18'),
        );
    }

    public function test_date_reduction_updates_attendance_correctly(): void
    {
        $agent = $this->createScheduledAgent();
        $manager = $this->createLeaveManager();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        app(AttendanceRegisterService::class)->refreshDateRange(
            $agent,
            Carbon::parse('2026-08-12'),
            Carbon::parse('2026-08-14'),
        );

        $this->assertSame(
            AttendanceDayStatus::OnLeave,
            $this->attendanceStatusFor($agent, '2026-08-14'),
        );

        $amendment = $this->amendmentService->submitAgentAmendment($agent, $leave, [
            'type' => LeaveAmendmentType::DateChange->value,
            'proposed_start_date' => '2026-08-12',
            'proposed_end_date' => '2026-08-13',
            'proposed_duration' => 'full_day',
            'reason' => 'Shorter leave',
        ]);

        $this->amendmentService->approve($amendment, $manager, 'Approved');

        $this->assertNotSame(
            AttendanceDayStatus::OnLeave,
            $this->attendanceStatusFor($agent, '2026-08-14'),
        );
        $this->assertSame(
            AttendanceDayStatus::OnLeave,
            $this->attendanceStatusFor($agent, '2026-08-13'),
        );
    }

    public function test_agent_cannot_approve_own_amendment(): void
    {
        $agent = $this->createAgentWithManagePermission();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $amendment = $this->amendmentService->submitAgentAmendment($agent, $leave, [
            'type' => LeaveAmendmentType::Cancellation->value,
            'reason' => 'Self approve attempt',
        ]);

        $this->assertFalse($this->amendmentService->canReviewAmendment($agent, $amendment));

        $this->actingAs($agent)
            ->post(route('leave-request-amendments.approve', $amendment), [
                'review_notes' => 'Self approve',
            ])
            ->assertForbidden();
    }

    public function test_unauthorized_user_cannot_manage_another_employees_leave(): void
    {
        $agent = $this->createAgent();
        $otherAgent = $this->createAgent();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $this->actingAs($otherAgent)
            ->post(route('leave-requests.manage-cancel', $leave), [
                'reason' => 'Unauthorized',
                'review_notes' => 'Should fail',
            ])
            ->assertForbidden();
    }

    public function test_hr_permission_works_through_role_permission_system(): void
    {
        $manager = $this->createLeaveManager();

        $this->assertTrue($manager->can('leave-requests.manage'));
        $this->assertTrue($this->amendmentService->canManage($manager));
    }

    public function test_only_one_pending_amendment_per_leave_request(): void
    {
        $agent = $this->createAgent();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $this->amendmentService->submitAgentAmendment($agent, $leave, [
            'type' => LeaveAmendmentType::Cancellation->value,
            'reason' => 'First request',
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->amendmentService->submitAgentAmendment($agent, $leave, [
            'type' => LeaveAmendmentType::Cancellation->value,
            'reason' => 'Second request',
        ]);
    }

    public function test_audit_history_is_preserved_for_amendments(): void
    {
        $agent = $this->createAgent();
        $manager = $this->createLeaveManager();
        $leave = $this->createApprovedLeave($agent, '2026-08-12', '2026-08-14');

        $amendment = $this->amendmentService->submitAgentAmendment($agent, $leave, [
            'type' => LeaveAmendmentType::DateChange->value,
            'proposed_start_date' => '2026-08-12',
            'proposed_end_date' => '2026-08-18',
            'proposed_duration' => 'full_day',
            'reason' => 'Extension',
        ]);

        $this->amendmentService->approve($amendment, $manager, 'Approved');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'workforce.leave.amendment.submitted',
            'auditable_type' => LeaveRequest::class,
            'auditable_id' => $leave->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'workforce.leave.amendment.approved',
            'auditable_type' => LeaveRequest::class,
            'auditable_id' => $leave->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'workforce.leave.updated',
            'auditable_type' => LeaveRequest::class,
            'auditable_id' => $leave->id,
        ]);

        $this->assertDatabaseHas('leave_request_amendments', [
            'leave_request_id' => $leave->id,
            'status' => LeaveAmendmentStatus::Approved->value,
        ]);

        $amendment->refresh();
        $this->assertSame('2026-08-14', $amendment->previous_end_date->toDateString());
        $this->assertSame('2026-08-18', $amendment->proposed_end_date->toDateString());
    }

    public function test_agent_role_does_not_receive_leave_manage_permission(): void
    {
        $agent = $this->createAgent();

        $this->assertFalse($agent->can('leave-requests.manage'));
    }

    public function test_leave_manage_permission_is_distinct_from_create(): void
    {
        $manager = $this->createLeaveManager();

        $this->assertTrue($manager->can('leave-requests.manage'));
        $this->assertTrue($manager->can('leave-requests.create'));
        $this->assertTrue(Permission::findByName('leave-requests.manage', 'web')->exists);
    }

    private function createAgent(): User
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $agent;
    }

    private function createAgentWithManagePermission(): User
    {
        $agent = $this->createAgent();
        $agent->givePermissionTo('leave-requests.manage');

        return $agent->fresh();
    }

    private function createLeaveManager(): User
    {
        $manager = User::factory()->create([
            'email' => 'hr-manager@radiumbox.com',
            'is_active' => true,
        ]);
        $manager->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $manager->fresh();
    }

    private function createPendingLeave(User $agent, string $start, string $end): LeaveRequest
    {
        return $this->leaveService->submit($agent, [
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Pending leave',
        ]);
    }

    private function createApprovedLeave(User $agent, string $start, string $end): LeaveRequest
    {
        $approver = User::factory()->create([
            'email' => 'leave-authority@radiumbox.com',
            'is_active' => true,
        ]);
        $approver->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Approved leave',
        ]);

        return $this->leaveService->approve($leave, $approver, 'Approved');
    }

    private function createScheduledAgent(): User
    {
        $agent = $this->createAgent();

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $agent->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'weekly_off_days' => [Carbon::SUNDAY],
            'effective_from' => '2000-01-01',
        ]);

        return $agent->fresh(['workSchedule']);
    }

    private function attendanceStatusFor(User $agent, string $date): AttendanceDayStatus
    {
        $result = app(AttendanceDayCalculator::class)->compute(
            user: $agent,
            workDate: Carbon::parse($date),
            referenceAt: now(),
            allowPreShiftSkip: false,
        );

        $this->assertNotNull($result);

        return $result->status;
    }
}
