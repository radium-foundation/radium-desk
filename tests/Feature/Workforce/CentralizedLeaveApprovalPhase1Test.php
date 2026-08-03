<?php

namespace Tests\Feature\Workforce;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Services\Operations\LeaveRequestService;
use App\Services\Platform\Cards\PendingLeaveApprovalsCardProvider;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CentralizedLeaveApprovalPhase1Test extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $leaveService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->leaveService = app(LeaveRequestService::class);

        Carbon::setTestNow(Carbon::parse('2026-08-03 10:00:00', 'Asia/Kolkata'));
        config([
            'workforce.leave_approver.email' => 'shipra@radiumbox.com',
            'workforce_calendar.retroactive_leave_days' => 14,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_shipra_can_approve_admin_leave_avinash_bugfix(): void
    {
        $shipra = $this->createShipra();
        $avinash = User::factory()->create([
            'email' => 'avinash@radiumbox.com',
            'name' => 'Avinash Jha',
            'is_active' => true,
        ]);
        $avinash->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $leave = $this->leaveService->submit($avinash, [
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-03',
            'reason' => 'Madhursravni pooja',
        ]);

        $this->assertTrue($this->leaveService->canReview($shipra, $leave));

        $this->leaveService->approve($leave, $shipra, 'Approved for pooja');

        $this->assertSame(LeaveRequestStatus::Approved, $leave->fresh()->status);
        $this->assertSame($shipra->id, $leave->fresh()->reviewed_by);
    }

    public function test_only_designated_approver_email_can_review_any_role(): void
    {
        $shipra = $this->createShipra();
        $otherOpsAdmin = User::factory()->create([
            'email' => 'other-ops@radiumbox.com',
            'is_active' => true,
        ]);
        $otherOpsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agentLeave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-05',
            'end_date' => '2026-08-05',
            'reason' => 'Agent leave',
        ]);

        $adminLeave = $this->leaveService->submit($admin, [
            'start_date' => '2026-08-06',
            'end_date' => '2026-08-06',
            'reason' => 'Admin leave',
        ]);

        $this->assertTrue($this->leaveService->canReview($shipra, $agentLeave));
        $this->assertTrue($this->leaveService->canReview($shipra, $adminLeave));
        $this->assertFalse($this->leaveService->canReview($otherOpsAdmin, $agentLeave));
        $this->assertFalse($this->leaveService->canReview($otherOpsAdmin, $adminLeave));
        $this->assertFalse($this->leaveService->canReview($superadmin, $adminLeave));
    }

    public function test_submission_notifies_only_designated_approver(): void
    {
        Notification::fake();

        $shipra = $this->createShipra();
        $otherOpsAdmin = User::factory()->create([
            'email' => 'other-ops@radiumbox.com',
            'is_active' => true,
        ]);
        $otherOpsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-11',
            'reason' => 'Travel',
        ]);

        Notification::assertSentTo($shipra, LeaveRequestSubmittedNotification::class);
        Notification::assertNotSentTo($otherOpsAdmin, LeaveRequestSubmittedNotification::class);
    }

    public function test_shipra_can_self_approve_with_audit_metadata(): void
    {
        $shipra = $this->createShipra();

        $leave = $this->leaveService->submit($shipra, [
            'start_date' => '2026-08-12',
            'end_date' => '2026-08-12',
            'reason' => 'Shipra personal leave',
        ]);

        $this->assertTrue($this->leaveService->canReview($shipra, $leave));

        $this->leaveService->approve($leave, $shipra, 'Self-approved by Leave Authority');

        $this->assertSame(LeaveRequestStatus::Approved, $leave->fresh()->status);
        $this->assertSame($shipra->id, $leave->fresh()->reviewed_by);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'workforce.leave.approved',
            'auditable_type' => LeaveRequest::class,
            'auditable_id' => $leave->id,
            'user_id' => $shipra->id,
        ]);

        $audit = \App\Models\AuditLog::query()
            ->where('event', 'workforce.leave.approved')
            ->where('auditable_id', $leave->id)
            ->first();

        $this->assertTrue((bool) ($audit?->new_values['self_approved'] ?? false));
        $this->assertSame($shipra->id, $audit?->new_values['approved_by'] ?? null);
        $this->assertSame($shipra->id, $audit?->new_values['actor'] ?? null);
    }

    public function test_other_users_cannot_self_approve(): void
    {
        $this->createShipra();

        $admin = User::factory()->create([
            'email' => 'avinash@radiumbox.com',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $opsOther = User::factory()->create([
            'email' => 'other-ops@radiumbox.com',
            'is_active' => true,
        ]);
        $opsOther->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $adminLeave = $this->leaveService->submit($admin, [
            'start_date' => '2026-08-12',
            'end_date' => '2026-08-12',
            'reason' => 'Admin self leave',
        ]);

        $agentLeave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-13',
            'end_date' => '2026-08-13',
            'reason' => 'Agent self leave',
        ]);

        $opsLeave = $this->leaveService->submit($opsOther, [
            'start_date' => '2026-08-14',
            'end_date' => '2026-08-14',
            'reason' => 'Ops self leave',
        ]);

        $this->assertFalse($this->leaveService->canReview($admin, $adminLeave));
        $this->assertFalse($this->leaveService->canReview($agent, $agentLeave));
        $this->assertFalse($this->leaveService->canReview($opsOther, $opsLeave));
    }

    public function test_shipra_still_approves_everyone_else(): void
    {
        $shipra = $this->createShipra();

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create([
            'email' => 'avinash@radiumbox.com',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agentLeave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-15',
            'end_date' => '2026-08-15',
            'reason' => 'Agent leave',
        ]);

        $adminLeave = $this->leaveService->submit($admin, [
            'start_date' => '2026-08-16',
            'end_date' => '2026-08-16',
            'reason' => 'Admin leave',
        ]);

        $this->assertTrue($this->leaveService->canReview($shipra, $agentLeave));
        $this->assertTrue($this->leaveService->canReview($shipra, $adminLeave));

        $this->leaveService->approve($agentLeave, $shipra, 'Approved agent leave');
        $this->leaveService->approve($adminLeave, $shipra, 'Approved admin leave');

        $agentAudit = \App\Models\AuditLog::query()
            ->where('event', 'workforce.leave.approved')
            ->where('auditable_id', $agentLeave->id)
            ->first();

        $this->assertFalse((bool) ($agentAudit?->new_values['self_approved'] ?? true));
        $this->assertSame($shipra->id, $agentAudit?->new_values['approved_by'] ?? null);
        $this->assertSame($shipra->id, $agentAudit?->new_values['actor'] ?? null);
        $this->assertSame(LeaveRequestStatus::Approved, $agentLeave->fresh()->status);
        $this->assertSame(LeaveRequestStatus::Approved, $adminLeave->fresh()->status);
    }

    public function test_non_designated_users_still_cannot_approve_any_leave(): void
    {
        $this->createShipra();

        $otherOpsAdmin = User::factory()->create([
            'email' => 'other-ops@radiumbox.com',
            'is_active' => true,
        ]);
        $otherOpsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-17',
            'end_date' => '2026-08-17',
            'reason' => 'Needs approval',
        ]);

        $this->assertFalse($this->leaveService->canReview($otherOpsAdmin, $leave));
        $this->assertFalse($this->leaveService->canReview($superadmin, $leave));
        $this->assertFalse($this->leaveService->canReview($admin, $leave));
        $this->assertFalse($this->leaveService->canReview($agent, $leave));
    }

    public function test_pending_approvals_group_today_and_upcoming(): void
    {
        $this->createShipra();

        $todayUser = User::factory()->create(['name' => 'Shubhanshi Rathore', 'is_active' => true]);
        $todayUser->assignRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST);

        $upcomingUser = User::factory()->create(['name' => 'Sushant Shetty', 'is_active' => true]);
        $upcomingUser->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->leaveService->submit($todayUser, [
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-03',
            'reason' => 'personal reason',
        ]);

        $this->leaveService->submit($upcomingUser, [
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-11',
            'reason' => 'Odisha travel',
        ]);

        $grouped = $this->leaveService->pendingApprovalsGrouped();

        $this->assertCount(1, $grouped['today']);
        $this->assertCount(1, $grouped['upcoming']);
        $this->assertSame('personal reason', $grouped['today']->first()->reason);
        $this->assertSame('Odisha travel', $grouped['upcoming']->first()->reason);
    }

    public function test_leave_index_shows_inline_pending_actions_for_shipra(): void
    {
        $shipra = $this->createShipra();
        $agent = User::factory()->create(['name' => 'Demo Agent', 'is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-03',
            'reason' => 'Need half day off for personal work',
        ]);

        $this->actingAs($shipra)
            ->get(route('leave-requests.index'))
            ->assertOk()
            ->assertSee('Pending Leave Approvals')
            ->assertSee('Today')
            ->assertSee('Approve')
            ->assertSee('Reject')
            ->assertSee('Need half day off for personal work');

        $this->actingAs($shipra)
            ->post(route('leave-requests.approve', $leave), [
                'review_notes' => 'Approved from index',
                'return_to' => 'index',
            ])
            ->assertRedirect(route('leave-requests.index'));

        $this->assertSame(LeaveRequestStatus::Approved, $leave->fresh()->status);
    }

    public function test_workforce_pending_card_visible_only_to_designated_approver_when_pending_exists(): void
    {
        $shipra = $this->createShipra();
        $otherOpsAdmin = User::factory()->create([
            'email' => 'other-ops@radiumbox.com',
            'is_active' => true,
        ]);
        $otherOpsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $otherOpsAdmin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-03',
            'reason' => 'Today leave',
        ]);

        $this->actingAs($shipra)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee('Pending Leave Approvals')
            ->assertSee('Review');

        $this->actingAs($otherOpsAdmin)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertDontSee('Pending Leave Approvals');
    }

    public function test_mission_control_pending_leave_card_authorizes_only_when_pending_for_shipra(): void
    {
        $shipra = $this->createShipra();
        $card = app(PendingLeaveApprovalsCardProvider::class);

        $this->assertFalse($card->authorize($shipra));

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-11',
            'reason' => 'Upcoming leave',
        ]);

        $this->assertTrue($card->authorize($shipra));

        $payload = $card->load($shipra);
        $this->assertSame(1, $payload->meta['count'] ?? 0);
        $this->assertSame('Upcoming leave', LeaveRequest::query()->find($payload->meta['items'][0]['id'])->reason);
    }

    private function createShipra(): User
    {
        $shipra = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'name' => 'Shipra',
            'is_active' => true,
        ]);
        $shipra->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $shipra->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $shipra;
    }
}
