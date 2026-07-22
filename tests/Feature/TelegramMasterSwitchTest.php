<?php

namespace Tests\Feature;

use App\Data\OperatorAlert;
use App\Enums\AlertSeverity;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationCategory;
use App\Enums\RefundStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Enums\TeamBroadcastAudience;
use App\Models\Incident;
use App\Models\IraNotification;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\SupportAppointment;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Alerts\OperatorAlertDispatcher;
use App\Services\IncidentReferenceService;
use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\TeamTelegramBroadcastService;
use App\Services\RefundNotificationService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\Telegram\TelegramBotService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TelegramMasterSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'team_telegram.enabled' => true,
            'team_telegram.appointment_reminders.enabled' => true,
            'team_telegram.appointment_reminders.role_thresholds_minutes' => [
                'default' => [30, 10, 0],
                'support_specialist' => [30, 10, 0],
            ],
            'ira.communication.cooldown_minutes' => 60,
            'operator_alerts.enabled' => true,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_telegram_off_blocks_ira_assignment(): void
    {
        Http::fake();

        $this->disableTelegramNotifications();

        $assignee = $this->createAgentWithTelegram('111222333');
        $incident = $this->createIncidentForAssignee($assignee);

        app(IraCommunicationService::class)->sendManualAssignment(
            assignee: $assignee,
            customer: 'Test Customer',
            device: 'Device X',
            time: 'Morning',
            caseReference: $incident->reference_no,
            context: ['incident_id' => $incident->id],
        );

        Http::assertNothingSent();

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $assignee->id,
            'notification_type' => IraNotificationType::ManualAssignment->value,
            'status' => IraNotificationStatus::Skipped->value,
        ]);
    }

    public function test_telegram_off_blocks_team_broadcast(): void
    {
        Http::fake();

        $this->disableTelegramNotifications();

        $sender = $this->createSuperadminWithTelegram('900000001');
        $recipient = $this->createAgentWithTelegram('900000002');

        $result = app(TeamTelegramBroadcastService::class)->broadcast(
            sender: $sender,
            message: 'System maintenance tonight.',
            audience: TeamBroadcastAudience::Selected,
            selectedUserIds: [$recipient->id],
            subject: 'Notice',
        );

        $this->assertSame(1, $result['recipients']);
        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
        Http::assertNothingSent();
    }

    public function test_telegram_off_blocks_appointment_reminder(): void
    {
        Http::fake();

        $this->disableTelegramNotifications();

        Carbon::setTestNow(Carbon::parse('2026-07-06 08:30:00', 'Asia/Kolkata'));

        $agent = $this->createAgentWithTelegram('301301301');
        $this->createScheduledAppointment($agent);

        $this->artisan('team-telegram:send-appointment-reminders')->assertSuccessful();

        Http::assertNothingSent();

        $notification = IraNotification::query()
            ->where('user_id', $agent->id)
            ->where('notification_type', IraNotificationType::SupportAppointmentReminder->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Skipped, $notification->status);
    }

    public function test_telegram_off_blocks_leave_notification(): void
    {
        Notification::fake();
        Http::fake();

        $this->disableTelegramNotifications();

        $supportAgent = User::factory()->create(['is_active' => true]);
        $supportAgent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $operationsAdmin = User::factory()->create([
            'is_active' => true,
            'telegram_chat_id' => '123456789',
            'telegram_notifications_enabled' => true,
        ]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        app(\App\Services\Operations\LeaveRequestService::class)->submit($supportAgent, [
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-12',
            'reason' => 'Personal leave',
        ]);

        Http::assertNothingSent();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'workforce.leave.notification.dispatched',
        ]);
    }

    public function test_telegram_off_blocks_refund_notification(): void
    {
        Http::fake();

        $this->disableTelegramNotifications();

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $approver = User::factory()->create([
            'is_active' => true,
            'telegram_chat_id' => '555666777',
            'telegram_notifications_enabled' => true,
        ]);
        $approver->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-REFUND-MASTER',
            'serial_number' => 'SN-REFUND-MASTER',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-2026-000099',
            'amount' => 500,
            'reason' => 'Duplicate payment received from customer.',
            'status' => RefundStatus::Pending,
            'requested_by' => $agent->id,
        ]);

        app(RefundNotificationService::class)->notifyApproversOfSubmission($refund->fresh(['requester']));

        Http::assertNothingSent();
    }

    public function test_telegram_off_blocks_operator_alert(): void
    {
        Http::fake();

        $this->disableTelegramNotifications();

        $recipient = $this->createAgentWithTelegram('777888999');

        $alert = new OperatorAlert(
            title: 'Test Alert',
            message: 'Needs attention',
            severity: AlertSeverity::Medium,
            category: NotificationCategory::Assignment,
            icon: 'bi-bell',
            actionUrl: '/incidents/1',
            entityType: 'incident',
            entityId: 1,
            deduplicationKey: 'master-switch:operator:1',
        );

        $result = app(OperatorAlertDispatcher::class)->dispatch(
            alert: $alert,
            recipients: $recipient,
            deliverTelegram: true,
            telegramMessage: "Test Alert\n\nOpen in Radium Desk",
        );

        $this->assertTrue($result->dispatched);
        $this->assertFalse($result->telegramSent());
        Http::assertNothingSent();
    }

    public function test_telegram_on_restores_ira_assignment_delivery(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ], 200),
        ]);

        $this->enableTelegramNotifications();

        $assignee = $this->createAgentWithTelegram('444555666');
        $incident = $this->createIncidentForAssignee($assignee);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        app(ServiceCaseAssignmentService::class)->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $admin,
            auditContext: ['assignment_method' => 'manual'],
        );

        Http::assertSentCount(1);

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $assignee->id,
            'notification_type' => IraNotificationType::ManualAssignment->value,
            'status' => IraNotificationStatus::Sent->value,
        ]);
    }

    public function test_system_setting_description_reflects_master_switch(): void
    {
        $description = config('system_settings.settings')['notifications.telegram.enabled']['description'];

        $this->assertSame(
            'Master switch for all Telegram notifications across Radium Desk.',
            $description,
        );
    }

    public function test_telegram_bot_service_skipped_result_matches_disabled_message(): void
    {
        Http::fake();

        $this->disableTelegramNotifications();

        $result = app(TelegramBotService::class)->sendMessage('123', 'hello');

        $this->assertTrue($result->skipped);
        $this->assertSame(TelegramBotService::DISABLED_BY_SYSTEM_SETTINGS, $result->error);
        Http::assertNothingSent();
    }

    private function createSuperadminWithTelegram(string $chatId): User
    {
        $user = User::factory()->create([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    private function createAgentWithTelegram(string $chatId): User
    {
        $user = User::factory()->create([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $this->createWorkSchedule($user);

        return $user;
    }

    private function createWorkSchedule(User $user): void
    {
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
    }

    private function createIncidentForAssignee(User $assignee): Incident
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-MASTER-'.uniqid(),
            'serial_number' => 'SN-MASTER',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'customer_name' => 'Test Customer',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Master switch test',
            'description' => 'Test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'assigned_to_user_id' => $assignee->id,
        ]);
    }

    private function createScheduledAppointment(User $agent): Incident
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => 'RD-REM-MASTER',
            'serial_number' => 'SN-REM-MASTER',
            'product_name' => 'Device',
            'device_model' => 'Model',
            'customer_name' => 'Reminder Customer',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Reminder case',
            'description' => 'Reminder test.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        SupportAppointment::query()->create([
            'incident_id' => $incident->id,
            'preferred_date' => Carbon::parse('2026-07-06', 'Asia/Kolkata')->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning,
            'phone_number' => '9876543210',
        ]);

        return $incident->fresh('supportAppointments');
    }
}
