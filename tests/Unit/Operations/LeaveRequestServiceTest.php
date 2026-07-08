<?php

namespace Tests\Unit\Operations;

use App\Enums\LeaveRequestStatus;
use App\Models\LeaveRequest;
use App\Models\SystemSetting;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Notifications\LeaveRequestDecisionNotification;
use App\Notifications\LeaveRequestSubmittedNotification;
use App\Services\Operations\LeaveRequestService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LeaveRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeaveRequestService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(LeaveRequestService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        config(['services.telegram.bot_token' => 'test-bot-token']);
        $this->enableTelegramChannel();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_submit_sends_approver_notification(): void
    {
        Notification::fake();

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 42],
            ], 200),
        ]);

        $supportAgent = User::factory()->create(['is_active' => true]);
        $supportAgent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $operationsAdmin = User::factory()->create([
            'is_active' => true,
            'telegram_chat_id' => '123456789',
            'telegram_notifications_enabled' => true,
        ]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->service->submit($supportAgent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        Notification::assertSentTo($operationsAdmin, LeaveRequestSubmittedNotification::class);
        Http::assertSentCount(1);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'leave.notification.dispatched',
            'auditable_type' => LeaveRequest::class,
        ]);
    }

    public function test_approve_sends_requester_notification(): void
    {
        Notification::fake();

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 43],
            ], 200),
        ]);

        $supportAgent = $this->createScheduledSupportAgent([
            'telegram_chat_id' => '987654321',
            'telegram_notifications_enabled' => true,
        ]);

        $operationsAdmin = User::factory()->create(['is_active' => true]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $leaveRequest = $this->service->submit($supportAgent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        Notification::fake();

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 44],
            ], 200),
        ]);

        $this->service->approve($leaveRequest, $operationsAdmin);

        Notification::assertSentTo($supportAgent, LeaveRequestDecisionNotification::class);
        Http::assertSentCount(1);
    }

    public function test_reject_sends_requester_notification(): void
    {
        Notification::fake();

        $supportAgent = $this->createScheduledSupportAgent([
            'telegram_chat_id' => '987654321',
            'telegram_notifications_enabled' => true,
        ]);

        $operationsAdmin = User::factory()->create(['is_active' => true]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $leaveRequest = $this->service->submit($supportAgent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        Notification::fake();

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 45],
            ], 200),
        ]);

        $this->service->reject($leaveRequest, $operationsAdmin, 'Coverage needed');

        Notification::assertSentTo($supportAgent, LeaveRequestDecisionNotification::class);
        Http::assertSentCount(1);
    }

    public function test_telegram_disabled_user_is_skipped(): void
    {
        Notification::fake();

        Http::fake();

        $supportAgent = User::factory()->create(['is_active' => true]);
        $supportAgent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $operationsAdmin = User::factory()->create([
            'is_active' => true,
            'telegram_chat_id' => '123456789',
            'telegram_notifications_enabled' => false,
        ]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->service->submit($supportAgent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        Notification::assertSentTo($operationsAdmin, LeaveRequestSubmittedNotification::class);
        Http::assertNothingSent();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'leave.notification.dispatched',
            'auditable_type' => LeaveRequest::class,
        ]);
    }

    public function test_existing_leave_approval_logic_unchanged(): void
    {
        $supportAgent = User::factory()->create();
        $supportAgent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $operationsAdmin = User::factory()->create();
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $owner = User::factory()->create();
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $supportLeave = $this->service->submit($supportAgent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        $operationsLeave = $this->service->submit($operationsAdmin, [
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-16',
            'reason' => 'Operations leave',
        ]);

        $this->assertTrue($this->service->canReview($operationsAdmin, $supportLeave));
        $this->assertFalse($this->service->canReview($supportAgent, $supportLeave));

        $this->assertTrue($this->service->canReview($owner, $operationsLeave));
        $this->assertFalse($this->service->canReview($operationsAdmin, $operationsLeave));

        $this->service->approve($supportLeave, $operationsAdmin);
        $this->service->approve($operationsLeave, $owner);

        $this->assertSame(LeaveRequestStatus::Approved, $supportLeave->fresh()->status);
        $this->assertSame(LeaveRequestStatus::Approved, $operationsLeave->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createScheduledSupportAgent(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
        ], $overrides));
        $user->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

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

    private function enableTelegramChannel(): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'notifications.telegram.enabled'],
            ['value' => '1'],
        );

        app(\App\Services\SystemSettingsService::class)->forget('notifications.telegram.enabled');
    }
}
