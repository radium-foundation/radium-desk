<?php

namespace Tests\Feature\Workforce;

use App\Enums\LeaveAmendmentStatus;
use App\Enums\LeaveAmendmentType;
use App\Enums\LeaveRequestStatus;
use App\Models\User;
use App\Services\Operations\LeaveRequestAmendmentService;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LeavePolicyAmendmentCoverageTest extends TestCase
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

        config([
            'leave_policy.coverage.admin_pair_emails' => ['dileep@test.com', 'avinash@test.com'],
            'leave_policy.coverage.triad_emails' => [],
            'workforce.leave_approver.email' => 'leave-authority@radiumbox.com',
        ]);
    }

    public function test_hr_date_change_to_saturday_applies_sunday_sandwich_on_amendment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 10:00:00', 'Asia/Kolkata'));

        $manager = $this->operationsAdmin();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-12',
            'end_date' => '2026-08-12',
            'reason' => 'Original',
        ]);
        $this->leaveService->approve($leave, $manager, 'Ok');

        $amendment = $this->amendmentService->manageDateChange($manager, $leave, [
            'proposed_start_date' => '2026-08-08',
            'proposed_end_date' => '2026-08-08',
            'reason' => 'Move to Saturday',
            'review_notes' => 'HR direct Saturday move',
        ]);

        $this->assertSame(LeaveAmendmentStatus::Approved, $amendment->status);
        $leave->refresh();
        $this->assertSame('2026-08-09', $leave->policy_effective_end_date?->toDateString());
        $this->assertSame('2026-08-08', $leave->start_date->toDateString());
    }

    public function test_hr_date_change_blocked_when_admin_pair_would_conflict(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));

        $manager = $this->operationsAdmin();
        $dileep = User::factory()->create(['email' => 'dileep@test.com', 'is_active' => true]);
        $avinash = User::factory()->create(['email' => 'avinash@test.com', 'is_active' => true]);
        $dileep->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $avinash->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $dileepLeave = $this->leaveService->submit($dileep, [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Away',
        ]);
        $this->leaveService->approve($dileepLeave, $manager, 'Ok');

        $avinashLeave = $this->leaveService->submit($avinash, [
            'start_date' => '2026-08-12',
            'end_date' => '2026-08-12',
            'reason' => 'Trip',
        ]);
        $this->leaveService->approve($avinashLeave, $manager, 'Ok');

        $this->expectException(ValidationException::class);
        $this->amendmentService->manageDateChange($manager, $avinashLeave, [
            'proposed_start_date' => '2026-08-07',
            'proposed_end_date' => '2026-08-07',
            'reason' => 'Conflict move',
            'review_notes' => 'Should fail',
        ]);
    }

    public function test_agent_date_change_amendment_approval_enforces_coverage(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));

        $manager = $this->operationsAdmin();
        $dileep = User::factory()->create(['email' => 'dileep@test.com', 'is_active' => true]);
        $avinash = User::factory()->create(['email' => 'avinash@test.com', 'is_active' => true]);
        $dileep->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $avinash->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $dileepLeave = $this->leaveService->submit($dileep, [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Away',
        ]);
        $this->leaveService->approve($dileepLeave, $manager, 'Ok');

        $avinashLeave = $this->leaveService->submit($avinash, [
            'start_date' => '2026-08-12',
            'end_date' => '2026-08-12',
            'reason' => 'Trip',
        ]);
        $this->leaveService->approve($avinashLeave, $manager, 'Ok');

        $amendment = $this->amendmentService->submitAgentAmendment($avinash, $avinashLeave, [
            'type' => LeaveAmendmentType::DateChange->value,
            'proposed_start_date' => '2026-08-07',
            'proposed_end_date' => '2026-08-07',
            'reason' => 'Reschedule',
        ]);

        $this->assertSame(LeaveAmendmentStatus::Pending, $amendment->status);

        $this->expectException(ValidationException::class);
        $this->amendmentService->approve($amendment, $manager, 'Should block');
    }

    public function test_duplicate_approve_on_already_approved_leave_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-05 10:00:00', 'Asia/Kolkata'));

        $manager = $this->operationsAdmin();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-07',
            'end_date' => '2026-08-07',
            'reason' => 'Once',
        ]);
        $this->leaveService->approve($leave, $manager, 'Ok');
        $this->assertSame(LeaveRequestStatus::Approved, $leave->fresh()->status);

        $this->expectException(ValidationException::class);
        $this->leaveService->approve($leave->fresh(), $manager, 'Again');
    }

    private function operationsAdmin(): User
    {
        $user = User::factory()->create(['email' => 'leave-authority@radiumbox.com', 'is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $user;
    }
}
