<?php

namespace Tests\Feature;

use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\WaitingReason;
use App\Events\Operations\SupportAppointmentSmartAssigned;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\IraNotification;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\TeamWorkBriefingService;
use App\Services\ServiceCaseAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamTelegramWorkAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'team_telegram.enabled' => true,
            'ira.communication.cooldown_minutes' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_daily_briefing_sent(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 11],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Shipra Agent', '111222333');
        $this->createWorkSchedule($agent);

        $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-TG-MORNING',
            customerName: 'Morning Customer',
            deviceModel: 'FM220',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $this->artisan('team-telegram:send-daily-briefings')->assertSuccessful();

        $notification = IraNotification::query()
            ->where('user_id', $agent->id)
            ->where('notification_type', IraNotificationType::TeamDailyBriefing->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Sent, $notification->status);
        $this->assertStringContainsString('Good morning Shipra', $notification->message);
        $this->assertStringContainsString('Morning: 1', $notification->message);
        Http::assertSentCount(1);
    }

    public function test_leave_skipped_for_daily_briefing(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Leave Agent', '222333444');
        $this->createWorkSchedule($agent);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Personal leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->artisan('team-telegram:send-daily-briefings')->assertSuccessful();

        $this->assertSame(
            0,
            IraNotification::query()
                ->where('user_id', $agent->id)
                ->where('notification_type', IraNotificationType::TeamDailyBriefing->value)
                ->count(),
        );

        Http::assertNothingSent();
    }

    public function test_smart_assignment_sends_exactly_one_telegram_notification(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 77],
            ], 200),
        ]);

        $assignee = $this->createSupportAgentWithTelegram('Smart Agent', '888777666');
        [$incident, $appointment] = $this->createAssignmentFixtures($assignee);

        event(new SupportAppointmentSmartAssigned(
            incident: $incident,
            appointment: $appointment,
            assignee: $assignee,
            result: \App\Data\Operations\SmartAssignmentResult::assigned(
                assignee: $assignee,
                reasons: ['Available'],
                context: ['factors' => ['Available']],
            ),
        ));

        Http::assertSentCount(1);

        $this->assertSame(
            1,
            IraNotification::query()
                ->where('user_id', $assignee->id)
                ->where('notification_type', IraNotificationType::SmartAssignment->value)
                ->where('status', IraNotificationStatus::Sent->value)
                ->count(),
        );
    }

    public function test_manual_assignment_triggers_telegram_alert(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 55],
            ], 200),
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $assignee = $this->createSupportAgentWithTelegram('Manual Agent', '333444555', RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->createOpenIncident('RD-TG-MANUAL', 'Manual Customer', 'FM220U');

        app(ServiceCaseAssignmentService::class)->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $admin,
            auditContext: ['assignment_method' => 'manual'],
        );

        $notification = IraNotification::query()
            ->where('user_id', $assignee->id)
            ->where('notification_type', IraNotificationType::ManualAssignment->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Sent, $notification->status);
        $this->assertStringContainsString('New support assigned', $notification->message);
        $this->assertStringContainsString('Manual Customer', $notification->message);
        Http::assertSentCount(1);
    }

    public function test_slot_reminder_sends_once(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 99],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 09:15:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Slot Agent', '444555666');
        $this->createWorkSchedule($agent);

        $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-TG-SLOT-1',
            customerName: 'Ravi',
            deviceModel: 'FM220',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-TG-SLOT-2',
            customerName: 'Amit',
            deviceModel: 'FM220U',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $this->artisan('team-telegram:send-slot-reminders')->assertSuccessful();
        $this->artisan('team-telegram:send-slot-reminders')->assertSuccessful();

        Http::assertSentCount(1);

        $notification = IraNotification::query()
            ->where('user_id', $agent->id)
            ->where('notification_type', IraNotificationType::SupportSlotReminder->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Sent, $notification->status);
        $this->assertStringContainsString('Morning Support Queue', $notification->message);
        $this->assertStringContainsString('1. Ravi - FM220', $notification->message);
        $this->assertStringContainsString('2. Amit - FM220U', $notification->message);
        $this->assertStringContainsString('Review in My Work.', $notification->message);

        $this->assertSame(
            1,
            IraNotification::query()
                ->where('user_id', $agent->id)
                ->where('notification_type', IraNotificationType::SupportSlotReminder->value)
                ->count(),
        );
    }

    public function test_disabled_telegram_is_skipped(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = User::factory()->create([
            'first_name' => 'Disabled',
            'last_name' => 'Agent',
            'name' => 'Disabled Agent',
            'telegram_chat_id' => '555666777',
            'telegram_notifications_enabled' => false,
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        $this->createWorkSchedule($agent);

        $this->artisan('team-telegram:send-daily-briefings')->assertSuccessful();

        $notification = IraNotification::query()
            ->where('user_id', $agent->id)
            ->where('notification_type', IraNotificationType::TeamDailyBriefing->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Skipped, $notification->status);
        Http::assertNothingSent();
    }

    public function test_follow_up_count_is_scoped_to_recipient(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));

        $agentA = $this->createSupportAgentWithTelegram('Briefing Agent A', '101010101');
        $agentB = $this->createSupportAgentWithTelegram('Briefing Agent B', '202020202');
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        for ($index = 1; $index <= 2; $index++) {
            $this->createAssignedWaitingCase("RD-BRIEF-WAIT-A-{$index}", $creator, $agentA);
        }

        for ($index = 1; $index <= 3; $index++) {
            $this->createAssignedWaitingCase("RD-BRIEF-WAIT-B-{$index}", $creator, $agentB);
        }

        $briefingService = app(TeamWorkBriefingService::class);

        $this->assertSame(2, $briefingService->buildFor($agentA)->followUpCount);
        $this->assertSame(3, $briefingService->buildFor($agentB)->followUpCount);

        Carbon::setTestNow();
    }

    private function createSupportAgentWithTelegram(
        string $name,
        string $chatId,
        string $role = RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
    ): User {
        $parts = explode(' ', $name, 2);

        $agent = User::factory()->create([
            'name' => $name,
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $agent->assignRole($role);

        return $agent;
    }

    private function createWorkSchedule(User $user): TeamMemberWorkSchedule
    {
        return TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);
    }

    private function createOpenIncident(string $orderId, string $customerName, string $deviceModel): Incident
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => $deviceModel,
            'device_model' => $deviceModel,
            'transaction_id' => null,
            'customer_name' => $customerName,
            'customer_email' => strtolower(str_replace(' ', '.', $customerName)).'@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Internal,
            'title' => 'Team telegram case',
            'description' => 'Team telegram case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createScheduledCase(
        User $assignee,
        string $orderId,
        string $customerName,
        string $deviceModel,
        SupportAppointmentTimeSlot $slot,
    ): Incident {
        $incident = $this->createOpenIncident($orderId, $customerName, $deviceModel);
        $incident->update(['assigned_to_user_id' => $assignee->id]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->toDateString(),
            'preferred_time_slot' => $slot,
            'phone_number' => '9876543210',
        ]);

        return $incident->fresh(['order', 'supportAppointments']);
    }

    /**
     * @return array{0: Incident, 1: SupportAppointment}
     */
    private function createAssignmentFixtures(User $assignee): array
    {
        $incident = $this->createOpenIncident('RD-TG-ASSIGN', 'Test Customer', 'FM220 Device');
        $incident->update(['assigned_to_user_id' => $assignee->id]);

        $appointment = SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->addDay()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
        ]);

        return [$incident->fresh(['order']), $appointment];
    }

    private function createAssignedWaitingCase(string $orderId, User $creator, User $assignee): Incident
    {
        $incident = $this->createOpenIncident($orderId, 'Waiting Customer', 'FM220');
        $incident->update(['assigned_to_user_id' => $assignee->id]);

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now(),
            'sla_paused' => true,
            'created_by' => $creator->id,
        ]);

        return $incident->fresh(['activeWaitingState', 'order', 'supportAppointments', 'assignee']);
    }
}
