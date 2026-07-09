<?php

namespace Tests\Feature;

use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IraNotification;
use App\Models\Order;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Data\Operations\IraCommunicationInput;
use App\Data\Operations\IraOperationalRisk;
use App\Enums\AI\AIRiskLevel;
use App\Enums\IraRiskCategory;
use App\Services\Operations\IraCommunicationService;
use App\Services\ServiceCaseAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Tests\TestCase;

class IraNotificationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'ira.communication.cooldown_minutes' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();

        parent::tearDown();
    }

    public function test_assignment_during_assignee_working_hours_sends_telegram(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 101],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 10:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $assignee = $this->createSupportAgentWithTelegram('Hours Agent', '111222333');
        $this->createWorkSchedule($assignee);
        $incident = $this->createOpenIncident('RD-HOURS-IN');

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
        Http::assertSentCount(1);
    }

    public function test_assignment_outside_assignee_hours_suppresses_telegram(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $assignee = $this->createSupportAgentWithTelegram('Off Hours Agent', '222333444');
        $this->createWorkSchedule($assignee);
        $incident = $this->createOpenIncident('RD-HOURS-OUT');

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
        $this->assertSame(IraNotificationStatus::Skipped, $notification->status);
        $this->assertStringContainsString('outside working hours', (string) $notification->error_message);
        Http::assertNothingSent();
    }

    public function test_high_priority_outside_hours_still_sends_telegram(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 102],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $assignee = $this->createSupportAgentWithTelegram('Priority Agent', '333444555');
        $this->createWorkSchedule($assignee);
        $incident = $this->createOpenIncident('RD-HOURS-HP', highPriority: true);

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
        Http::assertSentCount(1);
    }

    public function test_escalation_specialist_assignment_outside_hours_suppresses_telegram(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $specialist = $this->createSupportAgentWithTelegram(
            'Escalation Agent',
            '444555666',
            RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST,
        );
        $this->createWorkSchedule($specialist);
        $incident = $this->createOpenIncident('RD-HOURS-ESC');

        app(ServiceCaseAssignmentService::class)->assignWithAuditContext(
            incident: $incident,
            assignee: $specialist,
            actor: $admin,
            auditContext: ['assignment_method' => 'manual'],
        );

        $notification = IraNotification::query()
            ->where('user_id', $specialist->id)
            ->where('notification_type', IraNotificationType::ManualAssignment->value)
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame(IraNotificationStatus::Skipped, $notification->status);
        Http::assertNothingSent();
    }

    public function test_assignment_succeeds_even_when_telegram_suppressed(): void
    {
        Http::fake();

        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $admin = $this->createAdmin();
        $assignee = $this->createSupportAgentWithTelegram('Assigned Agent', '555666777');
        $this->createWorkSchedule($assignee);
        $incident = $this->createOpenIncident('RD-HOURS-OK');

        NotificationFacade::fake();

        $result = app(ServiceCaseAssignmentService::class)->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $admin,
            auditContext: ['assignment_method' => 'manual'],
        );

        $this->assertSame($assignee->id, $result->assigned_to_user_id);
        NotificationFacade::assertSentTo($assignee, \App\Notifications\ServiceCaseAssignedNotification::class);
        Http::assertNothingSent();
    }

    public function test_non_assignment_ira_telegram_is_unaffected_by_working_hours_policy(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 104],
            ], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-07-09 20:00:00', 'Asia/Kolkata'));

        $owner = User::factory()->create([
            'name' => 'Owner User',
            'telegram_chat_id' => '999888777',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
        $this->createWorkSchedule($owner);

        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::RiskAlert,
            insight: new IraOperationalRisk(
                key: 'customer.sla_danger',
                title: 'SLA Breach Risk',
                category: IraRiskCategory::Customer,
                severity: AIRiskLevel::High,
                message: '3 cases risk SLA breach.',
                context: ['overdue' => 2, 'warning' => 1],
            ),
            context: ['dedupe_key' => 'customer.sla_danger'],
        ));

        $this->assertNotEmpty($results);
        $this->assertSame(IraNotificationStatus::Sent, $results[0]->status);
        Http::assertSentCount(1);
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
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

    private function createOpenIncident(string $orderId, bool $highPriority = false): Incident
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'transaction_id' => null,
            'customer_name' => 'Policy Customer',
            'customer_email' => 'policy@example.com',
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
            'title' => 'Policy test case',
            'description' => 'Policy test case.',
            'status' => IncidentStatus::Open,
            'high_priority' => $highPriority,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }
}
