<?php

namespace Tests\Feature\Workforce;

use App\Enums\LeaveRequestStatus;
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

class LeaveRequestNotificationWorkflowTest extends TestCase
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

    public function test_agent_submission_notifies_designated_approver_exactly_once(): void
    {
        Notification::fake();

        $shipra = $this->createDesignatedApprover();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-11',
            'reason' => 'Travel',
            'duration' => 'full_day',
        ]);

        Notification::assertSentToTimes($shipra, LeaveRequestSubmittedNotification::class, 1);
        Notification::assertNotSentTo($agent, LeaveRequestSubmittedNotification::class);
    }

    public function test_approval_notifies_requester_exactly_once(): void
    {
        Notification::fake();

        $shipra = $this->createDesignatedApprover();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-11',
            'reason' => 'Travel',
        ]);

        Notification::fake();

        $this->leaveService->approve($leave, $shipra, 'Approved for travel');

        Notification::assertSentToTimes($agent, LeaveRequestDecisionNotification::class, 1);
        Notification::assertNotSentTo($shipra, LeaveRequestDecisionNotification::class);

        $notification = Notification::sent($agent, LeaveRequestDecisionNotification::class)->first();
        $payload = $notification->toArray($agent);

        $this->assertSame('Leave Request Approved', $payload['title']);
        $this->assertStringContainsString('approved', $payload['message']);
        $this->assertStringContainsString('2026-08-10 to 2026-08-11', $payload['message']);
    }

    public function test_rejection_notifies_requester_exactly_once_with_review_notes(): void
    {
        Notification::fake();

        $shipra = $this->createDesignatedApprover();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-12',
            'end_date' => '2026-08-12',
            'reason' => 'Personal',
            'duration' => 'half_day',
        ]);

        Notification::fake();

        $this->leaveService->reject($leave, $shipra, 'Coverage needed on that day');

        Notification::assertSentToTimes($agent, LeaveRequestDecisionNotification::class, 1);
        Notification::assertNotSentTo($shipra, LeaveRequestDecisionNotification::class);

        $notification = Notification::sent($agent, LeaveRequestDecisionNotification::class)->first();
        $payload = $notification->toArray($agent);

        $this->assertSame('Leave Request Rejected', $payload['title']);
        $this->assertStringContainsString('rejected', $payload['message']);
        $this->assertStringContainsString('Half Day', $payload['message']);
        $this->assertStringContainsString('Coverage needed on that day', $payload['message']);
    }

    public function test_repeated_approve_attempt_does_not_send_duplicate_notifications(): void
    {
        Notification::fake();

        $shipra = $this->createDesignatedApprover();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-15',
            'end_date' => '2026-08-15',
            'reason' => 'Personal',
        ]);

        Notification::fake();

        $this->leaveService->approve($leave, $shipra, 'Approved once');

        try {
            $this->leaveService->approve($leave->fresh(), $shipra, 'Approved again');
            $this->fail('Expected ValidationException when approving a non-pending leave request.');
        } catch (ValidationException) {
            // Expected: state transition guard prevents duplicate decision notifications.
        }

        Notification::assertSentToTimes($agent, LeaveRequestDecisionNotification::class, 1);
        $this->assertSame(LeaveRequestStatus::Approved, $leave->fresh()->status);
    }

    public function test_repeated_reject_attempt_does_not_send_duplicate_notifications(): void
    {
        Notification::fake();

        $shipra = $this->createDesignatedApprover();
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $leave = $this->leaveService->submit($agent, [
            'start_date' => '2026-08-16',
            'end_date' => '2026-08-16',
            'reason' => 'Personal',
        ]);

        Notification::fake();

        $this->leaveService->reject($leave, $shipra, 'Rejected once');

        try {
            $this->leaveService->reject($leave->fresh(), $shipra, 'Rejected again');
            $this->fail('Expected ValidationException when rejecting a non-pending leave request.');
        } catch (ValidationException) {
            // Expected: state transition guard prevents duplicate decision notifications.
        }

        Notification::assertSentToTimes($agent, LeaveRequestDecisionNotification::class, 1);
        $this->assertSame(LeaveRequestStatus::Rejected, $leave->fresh()->status);
    }

    public function test_submission_notification_includes_requester_and_leave_details(): void
    {
        Notification::fake();

        $this->createDesignatedApprover();
        $agent = User::factory()->create([
            'name' => 'Demo Agent',
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->leaveService->submit($agent, [
            'start_date' => '2026-08-20',
            'end_date' => '2026-08-20',
            'reason' => 'Half day off',
            'duration' => 'half_day',
        ]);

        $shipra = User::query()->where('email', 'shipra@radiumbox.com')->firstOrFail();
        $notification = Notification::sent($shipra, LeaveRequestSubmittedNotification::class)->first();
        $payload = $notification->toArray($shipra);

        $this->assertSame('Leave Request Submitted', $payload['title']);
        $this->assertStringContainsString('New leave request from Demo', $payload['message']);
        $this->assertStringContainsString('Half Day', $payload['message']);
        $this->assertStringContainsString('2026-08-20', $payload['message']);
    }

    private function createDesignatedApprover(): User
    {
        $shipra = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'name' => 'Shipra',
            'is_active' => true,
        ]);
        $shipra->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $shipra;
    }
}
