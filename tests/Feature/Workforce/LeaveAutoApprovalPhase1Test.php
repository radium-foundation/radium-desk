<?php

namespace Tests\Feature\Workforce;

use App\Enums\LeaveApprovalSource;
use App\Enums\LeaveDuration;
use App\Enums\LeaveRequestStatus;
use App\Enums\WorkforceAuditEvent;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestDecisionNotification;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeaveAutoApprovalPhase1Test extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $leaveService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->leaveService = app(LeaveRequestService::class);

        Carbon::setTestNow(Carbon::parse('2026-08-04 10:00:00', 'Asia/Kolkata'));
        $this->configurePilotPolicyBaseline();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_flag_off_eligible_thursday_stays_pending_and_notifies_approver(): void
    {
        Notification::fake();

        $approver = $this->designatedApprover();
        $agent = $this->createSupportAgent();

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Personal',
        ]);

        $this->assertSame(LeaveRequestStatus::Pending, $leave->status);
        $this->assertSame(LeaveApprovalSource::Manual, $leave->approval_source);
        Notification::assertSentToTimes($approver, LeaveRequestSubmittedNotification::class, 1);
        Notification::assertNotSentTo($agent, LeaveRequestDecisionNotification::class);
    }

    public function test_flag_on_eligible_thursday_auto_approves_with_audit_fields(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $agent = $this->createSupportAgent();

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Personal',
        ]);

        $leave->refresh();

        $this->assertSame(LeaveRequestStatus::Approved, $leave->status);
        $this->assertSame(LeaveApprovalSource::Auto, $leave->approval_source);
        $this->assertNull($leave->reviewed_by);
        $this->assertSame('Auto-approved by leave policy rules', $leave->review_notes);

        $this->assertDatabaseHas('audit_logs', [
            'event' => WorkforceAuditEvent::LeaveApproved->value,
            'auditable_type' => LeaveRequest::class,
            'auditable_id' => $leave->id,
        ]);
    }

    public function test_flag_on_auto_approved_notifies_requester_not_approver(): void
    {
        Notification::fake();
        config(['leave_policy.auto_approval.enabled' => true]);

        $approver = $this->designatedApprover();
        $agent = $this->createSupportAgent();

        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Personal',
        ]);

        Notification::assertNotSentTo($approver, LeaveRequestSubmittedNotification::class);
        Notification::assertSentToTimes($agent, LeaveRequestDecisionNotification::class, 1);
    }

    public function test_saturday_short_notice_remains_pending_when_auto_enabled(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $agent = $this->createSupportAgent();

        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Kolkata'));

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-08',
            'end_date' => '2026-08-08',
            'reason' => 'Saturday',
        ]);

        $this->assertSame(LeaveRequestStatus::Pending, $leave->status);
        $this->assertTrue((bool) data_get($leave->policy_metadata, 'sunday_sandwich.applied'));
    }

    public function test_manual_exception_remains_pending_when_auto_enabled(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $agent = $this->createSupportAgent();

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Exception',
            'manual_exception' => true,
        ]);

        $this->assertSame(LeaveRequestStatus::Pending, $leave->status);
        $this->assertTrue($leave->is_manual_exception);
    }

    public function test_monday_leave_remains_pending_when_auto_enabled(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $agent = $this->createSupportAgent();

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-10',
            'reason' => 'Monday',
        ]);

        $this->assertSame(LeaveRequestStatus::Pending, $leave->status);
    }

    public function test_span_exceeding_pilot_limit_remains_pending(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $agent = $this->createSupportAgent();

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-07',
            'reason' => 'Two days',
        ]);

        $this->assertSame(LeaveRequestStatus::Pending, $leave->status);
    }

    public function test_admin_pair_coverage_blocks_peer_auto_approval(): void
    {
        config([
            'leave_policy.auto_approval.enabled' => true,
            'leave_policy.coverage.admin_pair_emails' => [
                'dileep@test.com',
                'avinash@test.com',
            ],
        ]);

        $dileep = User::factory()->create(['email' => 'dileep@test.com', 'is_active' => true]);
        $avinash = User::factory()->create(['email' => 'avinash@test.com', 'is_active' => true]);
        $dileep->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $avinash->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $first = $this->leaveService->submit($dileep, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Admin',
        ]);
        $this->assertSame(LeaveRequestStatus::Approved, $first->status);

        $this->expectException(ValidationException::class);
        $this->leaveService->submit($avinash, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Conflict',
        ]);
    }

    public function test_manual_approval_still_works_when_auto_skipped(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $approver = $this->designatedApprover();
        $agent = $this->createSupportAgent();

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-10',
            'reason' => 'Manual Monday',
        ]);

        $approved = $this->leaveService->approve($leave, $approver, 'Approved manually');

        $this->assertSame(LeaveRequestStatus::Approved, $approved->status);
        $this->assertSame(LeaveApprovalSource::Manual, $approved->approval_source);
        $this->assertSame($approver->id, $approved->reviewed_by);
    }

    public function test_auto_approved_leave_cannot_be_manually_approved_again(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $approver = $this->designatedApprover();
        $agent = $this->createSupportAgent();

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Auto',
        ]);

        $this->assertSame(LeaveRequestStatus::Approved, $leave->status);

        $this->expectException(ValidationException::class);
        $this->leaveService->approve($leave, $approver, 'Duplicate approval');
    }

    public function test_overlapping_second_submission_rejected_after_auto_approved_first(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $agent = $this->createSupportAgent();

        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'First',
        ]);

        $this->expectException(ValidationException::class);
        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Overlap',
        ]);
    }

    public function test_employee_half_day_still_rejected_on_submit(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $agent = $this->createSupportAgent();

        $this->expectException(ValidationException::class);
        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'duration' => LeaveDuration::HalfDay->value,
            'reason' => 'Half day',
        ]);
    }

    public function test_auto_approval_records_submitted_and_approved_audit_events(): void
    {
        config(['leave_policy.auto_approval.enabled' => true]);

        $agent = $this->createSupportAgent();

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Audit',
        ]);

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('auditable_id', $leave->id)
                ->where('event', WorkforceAuditEvent::LeaveSubmitted->value)
                ->count(),
        );
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('auditable_id', $leave->id)
                ->where('event', WorkforceAuditEvent::LeaveApproved->value)
                ->count(),
        );
    }

    private function configurePilotPolicyBaseline(): void
    {
        config([
            'leave_policy.coverage.triad_emails' => [
                'triad1@test.com',
                'triad2@test.com',
                'triad3@test.com',
            ],
            'leave_policy.coverage.admin_pair_emails' => [],
            'leave_policy.auto_approval.enabled' => false,
            'workforce.leave_approver.email' => 'shipra@radiumbox.com',
            'workforce_calendar.retroactive_leave_days' => 14,
        ]);

        User::factory()->create(['email' => 'triad1@test.com', 'is_active' => true]);
        User::factory()->create(['email' => 'triad2@test.com', 'is_active' => true]);
        User::factory()->create(['email' => 'triad3@test.com', 'is_active' => true]);
    }

    private function createSupportAgent(): User
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        return $agent;
    }

    private function designatedApprover(): User
    {
        $operationsAdmin = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $operationsAdmin;
    }
}
