<?php

namespace Tests\Feature;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\SupportAppointmentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IraNotification;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\SupportAppointment;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\AppointmentReminderConfigurationResolver;
use App\Services\Operations\AppointmentReminderExecutionService;
use App\Services\Operations\SupportAppointmentReminderService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupportAppointmentTelegramReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'team_telegram.enabled' => true,
            'team_telegram.appointment_reminders.enabled' => true,
            'team_telegram.appointment_reminders.role_thresholds_minutes' => [
                'default' => [30, 10, 0],
                'support_specialist' => [30, 10, 0],
            ],
            'ira.communication.cooldown_minutes' => 60,
        ]);

        $this->enableTelegramNotifications();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_thirty_minute_reminder_is_sent_to_assigned_engineer(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 301],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Reminder Agent', '301301301');
        $incident = $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-REM-30',
            customerName: 'Abhinav Sharma',
            slot: SupportAppointmentTimeSlot::Morning,
        );
        $appointment = $incident->supportAppointments->first();

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        $notification = IraNotification::query()
            ->where('user_id', $agent->id)
            ->where('notification_type', IraNotificationType::SupportAppointmentReminder->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Sent, $notification->status);
        $this->assertStringContainsString('📅 Appointment Reminder', $notification->message);
        $this->assertStringContainsString('⏰ Starts in 30 minutes', $notification->message);
        $this->assertStringContainsString('👤 Abhinav Sharma', $notification->message);
        $this->assertStringContainsString('📦 RD-REM-30', $notification->message);
        $this->assertStringContainsString('🕒 9:00 AM', $notification->message);
        $this->assertStringContainsString('👨‍🔧 Reminder', $notification->message);
        $this->assertStringContainsString('🛠 General', $notification->message);

        $this->assertDatabaseHas('automation_executions', [
            'support_appointment_id' => $appointment->id,
            'policy_key' => AppointmentReminderExecutionService::POLICY_KEY,
            'schedule_step' => 30,
            'action_type' => AutomationPolicyActionType::AppointmentReminderTelegram->value,
            'status' => AutomationExecutionStatus::Success->value,
            'idempotency_key' => "appointment-reminder.{$appointment->id}.30.2026-07-06",
        ]);

        $execution = AutomationExecution::query()->first();
        $this->assertSame('appointment-reminder', $execution->metadata['automation_type'] ?? null);
        $this->assertSame($agent->id, $execution->metadata['engineer_id'] ?? null);
        $this->assertSame(30, $execution->metadata['threshold_minutes'] ?? null);

        Http::assertSentCount(1);
    }

    public function test_ten_minute_reminder_is_sent_to_assigned_engineer(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 310],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:50:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Ten Min Agent', '310310310');
        $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-REM-10',
            customerName: 'Ten Minute Customer',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        $notification = IraNotification::query()
            ->where('user_id', $agent->id)
            ->where('notification_type', IraNotificationType::SupportAppointmentReminder->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('⏰ Starts in 10 minutes', $notification->message);
        Http::assertSentCount(1);
    }

    public function test_start_reminder_is_sent_at_appointment_time(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 300],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Start Agent', '300300300');
        $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-REM-0',
            customerName: 'Start Customer',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        $notification = IraNotification::query()
            ->where('user_id', $agent->id)
            ->where('notification_type', IraNotificationType::SupportAppointmentReminder->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('⏰ Starting now', $notification->message);
        Http::assertSentCount(1);
    }

    public function test_duplicate_reminder_uses_automation_execution_history(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 500],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Dedupe Agent', '500500500');
        $incident = $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-REM-DEDUPE',
            customerName: 'Dedupe Customer',
            slot: SupportAppointmentTimeSlot::Morning,
        );
        $appointment = $incident->supportAppointments->first();

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();
        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        Http::assertSentCount(1);

        $this->assertSame(
            1,
            AutomationExecution::query()
                ->where('support_appointment_id', $appointment->id)
                ->where('schedule_step', 30)
                ->count(),
        );

        $this->assertSame(
            1,
            IraNotification::query()
                ->where('user_id', $agent->id)
                ->where('notification_type', IraNotificationType::SupportAppointmentReminder->value)
                ->count(),
        );
    }

    public function test_cancelled_appointment_does_not_send_reminder(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Cancelled Agent', '401401401');
        $incident = $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-REM-CANCEL',
            customerName: 'Cancelled Customer',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $incident->supportAppointments->first()?->update([
            'status' => SupportAppointmentStatus::Cancelled,
        ]);

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        $this->assertSame(0, AutomationExecution::query()->count());
        Http::assertNothingSent();
    }

    public function test_completed_appointment_does_not_send_reminder(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Completed Agent', '402402402');
        $incident = $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-REM-DONE',
            customerName: 'Completed Customer',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $incident->supportAppointments->first()?->update([
            'status' => SupportAppointmentStatus::Completed,
        ]);

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        $this->assertSame(0, AutomationExecution::query()->count());
        Http::assertNothingSent();
    }

    public function test_superseded_appointment_does_not_send_reminder(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Reschedule Agent', '403403403');
        $incident = $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-REM-RESCHED',
            customerName: 'Rescheduled Customer',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        $oldAppointment = $incident->supportAppointments->first();
        $oldAppointment?->update(['status' => SupportAppointmentStatus::Superseded]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Afternoon,
            'phone_number' => '9876543210',
            'status' => SupportAppointmentStatus::Scheduled,
        ]);

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        $this->assertSame(
            0,
            AutomationExecution::query()
                ->where('support_appointment_id', $oldAppointment?->id)
                ->count(),
        );

        Http::assertNothingSent();
    }

    public function test_unassigned_appointment_does_not_send_reminder(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $incident = $this->createOpenIncident('RD-REM-UNASSIGNED', 'Unassigned Customer', 'FM220');
        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
        ]);

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        $this->assertSame(0, AutomationExecution::query()->count());
        Http::assertNothingSent();
    }

    public function test_quiet_rules_skip_engineers_on_leave(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Leave Agent', '404404404');
        $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-REM-LEAVE',
            customerName: 'Leave Customer',
            slot: SupportAppointmentTimeSlot::Morning,
        );

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => now()->toDateString(),
            'end_date' => now()->toDateString(),
            'reason' => 'Personal leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        $this->assertSame(0, AutomationExecution::query()->count());
        Http::assertNothingSent();
    }

    public function test_appointment_type_line_is_omitted_when_unavailable(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 311],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Typeless Agent', '311311311');
        $incident = $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-REM-TYPELESS',
            customerName: 'Typeless Customer',
            slot: SupportAppointmentTimeSlot::Morning,
        );
        $incident->update(['category' => '', 'title' => '']);

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        $notification = IraNotification::query()
            ->where('user_id', $agent->id)
            ->where('notification_type', IraNotificationType::SupportAppointmentReminder->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringNotContainsString('🛠', $notification->message);
        $this->assertNull($notification->payload['context']['appointment_type'] ?? null);
    }

    public function test_appointment_type_line_uses_incident_category_when_available(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 312],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createSupportAgentWithTelegram('Typed Agent', '312312312');
        $incident = $this->createScheduledCase(
            assignee: $agent,
            orderId: 'RD-REM-TYPED',
            customerName: 'Typed Customer',
            slot: SupportAppointmentTimeSlot::Morning,
        );
        $incident->update(['category' => 'Driver Installation']);

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        $notification = IraNotification::query()
            ->where('user_id', $agent->id)
            ->where('notification_type', IraNotificationType::SupportAppointmentReminder->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('🛠 Driver Installation', $notification->message);
        $this->assertSame('Driver Installation', $notification->payload['context']['appointment_type'] ?? null);

        $execution = AutomationExecution::query()->first();
        $this->assertSame('appointment-reminder', $execution->policy_key);
        $this->assertSame('Driver Installation', $execution->metadata['appointment_type'] ?? null);
        $this->assertSame('appointment-reminder', $execution->metadata['automation_type'] ?? null);
    }

    public function test_threshold_due_logic_matches_agent_dashboard_windows(): void
    {
        $service = app(SupportAppointmentReminderService::class);
        $startsAt = Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata');

        $this->assertTrue($service->isThresholdDue($startsAt, 30, Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata')));
        $this->assertFalse($service->isThresholdDue($startsAt, 30, Carbon::parse('2026-07-06 08:20:00', 'Asia/Kolkata')));

        $this->assertTrue($service->isThresholdDue($startsAt, 10, Carbon::parse('2026-07-06 08:50:00', 'Asia/Kolkata')));
        $this->assertTrue($service->isThresholdDue($startsAt, 0, Carbon::parse('2026-07-06 09:00:00', 'Asia/Kolkata')));
        $this->assertTrue($service->isThresholdDue($startsAt, 0, Carbon::parse('2026-07-06 09:03:00', 'Asia/Kolkata')));
        $this->assertFalse($service->isThresholdDue($startsAt, 0, Carbon::parse('2026-07-06 09:06:00', 'Asia/Kolkata')));
    }

    public function test_threshold_resolution_uses_configuration_resolver(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        config([
            'team_telegram.appointment_reminders.role_thresholds_minutes' => [
                'default' => [30, 10, 0],
                'support_specialist' => [15, 5],
            ],
        ]);

        $configuration = app(AppointmentReminderConfigurationResolver::class)->forUser($agent);

        $this->assertSame([15, 5], $configuration->thresholdsMinutes);
    }

    private function createSupportAgentWithTelegram(string $name, string $chatId): User
    {
        $parts = explode(' ', $name, 2);

        $agent = User::factory()->create([
            'name' => $name,
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? '',
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        return $agent;
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
            'title' => 'Appointment reminder test case',
            'description' => 'Appointment reminder test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function createScheduledCase(
        User $assignee,
        string $orderId,
        string $customerName,
        SupportAppointmentTimeSlot $slot,
    ): Incident {
        $incident = $this->createOpenIncident($orderId, $customerName, 'FM220');
        $incident->update(['assigned_to_user_id' => $assignee->id]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => now()->toDateString(),
            'preferred_time_slot' => $slot,
            'phone_number' => '9876543210',
        ]);

        return $incident->fresh(['order', 'supportAppointments']);
    }
}
