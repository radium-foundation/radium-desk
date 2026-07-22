<?php

namespace Tests\Feature;

use App\Data\Operations\IraOwnerReportData;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\Incident;
use App\Models\IraNotification;
use App\Models\LeaveRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\IraOwnerIntelligenceService;
use App\Services\Operations\IraOwnerReportFormatter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IraOwnerIntelligenceReportTest extends TestCase
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

        $this->enableTelegramNotifications();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_morning_report_sends_only_to_superadmin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('900001', 'Ravi Owner');
        $admin = $this->createAdminWithTelegram('900002');
        $opsAdmin = $this->createOpsAdminWithTelegram('900003');
        $agent = $this->createSupportAgentWithTelegram('900004');

        $this->artisan('ira:send-owner-intelligence --period=morning')->assertSuccessful();

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $owner->id,
            'notification_type' => IraNotificationType::OwnerIntelligenceReport->value,
            'status' => IraNotificationStatus::Sent->value,
        ]);

        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $admin->id,
            'notification_type' => IraNotificationType::OwnerIntelligenceReport->value,
        ]);
        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $opsAdmin->id,
            'notification_type' => IraNotificationType::OwnerIntelligenceReport->value,
        ]);
        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $agent->id,
            'notification_type' => IraNotificationType::OwnerIntelligenceReport->value,
        ]);
    }

    public function test_evening_report_sends_only_to_superadmin(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 20:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 502],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('910001', 'Ravi Owner');

        $this->artisan('ira:send-owner-intelligence --period=evening')->assertSuccessful();

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $owner->id,
            'notification_type' => IraNotificationType::OwnerIntelligenceReport->value,
            'status' => IraNotificationStatus::Sent->value,
        ]);
    }

    public function test_admin_does_not_receive_owner_reports(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 503],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('920001');
        $admin = $this->createAdminWithTelegram('920002');
        $opsAdmin = $this->createOpsAdminWithTelegram('920003');

        $recipients = app(IraCommunicationService::class)->ownerIntelligenceRecipients();

        $this->assertCount(1, $recipients);
        $this->assertSame($owner->id, $recipients[0]->id);

        $this->artisan('ira:send-owner-intelligence --period=morning')->assertSuccessful();

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $owner->id,
            'notification_type' => IraNotificationType::OwnerIntelligenceReport->value,
        ]);

        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $admin->id,
            'notification_type' => IraNotificationType::OwnerIntelligenceReport->value,
        ]);

        $this->assertDatabaseMissing('ira_notifications', [
            'user_id' => $opsAdmin->id,
            'notification_type' => IraNotificationType::OwnerIntelligenceReport->value,
        ]);
    }

    public function test_present_and_absent_data_included_in_morning_report(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $presentAgent = User::factory()->create([
            'name' => 'Present Agent',
            'is_active' => true,
            'availability_status' => TeamAvailabilityStatus::Available,
        ]);
        $presentAgent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $report = app(IraOwnerIntelligenceService::class)->buildMorningReport();
        $messages = app(IraOwnerReportFormatter::class)->formatTelegramMessages($report, 'Ravi');

        $combined = implode("\n", $messages);

        $this->assertStringContainsString('Present:', $combined);
        $this->assertStringContainsString('Absent:', $combined);
    }

    public function test_leave_data_included_in_report(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create([
            'name' => 'Leave Agent',
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-10',
            'reason' => 'Personal leave',
            'status' => LeaveRequestStatus::Approved,
        ]);

        LeaveRequest::query()->create([
            'user_id' => $agent->id,
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-16',
            'reason' => 'Pending approval',
            'status' => LeaveRequestStatus::Pending,
        ]);

        $report = app(IraOwnerIntelligenceService::class)->buildMorningReport();
        $messages = app(IraOwnerReportFormatter::class)->formatTelegramMessages($report, 'Ravi');
        $combined = implode("\n", $messages);

        $this->assertStringContainsString('On leave:', $combined);
        $this->assertStringContainsString('Pending leave approvals: 1', $combined);
    }

    public function test_sla_risk_included_in_report(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        $this->createIncidentFor($agent, 'RD-OWNER-SLA');

        $report = app(IraOwnerIntelligenceService::class)->buildMorningReport();
        $messages = app(IraOwnerReportFormatter::class)->formatTelegramMessages($report, 'Ravi');
        $combined = implode("\n", $messages);

        $this->assertStringContainsString('SLA risk:', $combined);
        $this->assertStringContainsString('Open cases:', $combined);
    }

    public function test_empty_day_does_not_fail(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 504],
            ], 200),
        ]);

        $owner = $this->createOwnerWithTelegram('930001');

        $this->artisan('ira:send-owner-intelligence --period=morning')->assertSuccessful();
        $this->artisan('ira:send-owner-intelligence --period=evening')->assertSuccessful();

        $morningReport = app(IraOwnerIntelligenceService::class)->buildMorningReport();
        $eveningReport = app(IraOwnerIntelligenceService::class)->buildEveningReport();

        $this->assertSame('morning', $morningReport->period);
        $this->assertSame('evening', $eveningReport->period);

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $owner->id,
            'notification_type' => IraNotificationType::OwnerIntelligenceReport->value,
        ]);
    }

    public function test_telegram_length_is_safe(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 10:00:00', 'Asia/Kolkata'));

        $report = new IraOwnerReportData(
            date: '2026-07-10',
            period: 'morning',
            team: [
                'present' => array_map(fn (int $index): string => "Present Member {$index}", range(1, 20)),
                'absent' => array_map(fn (int $index): string => "Absent Member {$index}", range(1, 20)),
                'on_leave' => array_map(fn (int $index): string => "Leave Member {$index}", range(1, 20)),
                'late_arrivals' => array_map(fn (int $index): string => "Late Member {$index}", range(1, 20)),
                'pending_leave_approvals' => 12,
                'pending_leave_requesters' => ['A', 'B', 'C'],
            ],
            operations: [
                'open_cases' => 45,
                'sla_overdue' => 12,
                'sla_warning' => 8,
                'overdue_cases' => 12,
                'escalations_pending' => 3,
                'unassigned_important' => 5,
                'waiting_customers' => 20,
                'cases_created' => 0,
                'cases_closed' => 0,
                'escalated_today' => 0,
            ],
            previousDay: [
                'unresolved_carry_forward' => 10,
                'critical_events' => array_map(
                    fn (int $index): string => "Critical operational event {$index}: extended detail about customer impact, SLA breach risk, and required owner attention today.",
                    range(1, 15),
                ),
            ],
            attendance: [
                'on_time_logins' => 0,
                'late_logins' => 0,
                'manual_logouts' => 0,
                'timeout_events' => 0,
                'extra_working_members' => [],
                'away_timeout_members' => [],
            ],
            people: [
                'highlights' => [],
                'bottlenecks' => [],
            ],
            snapshot: new \App\Data\Operations\IraOperationalSnapshotData(
                date: '2026-07-10',
                operations: ['open_cases' => 45, 'overdue' => 12, 'warning' => 8],
                team: ['available' => 5, 'leave' => 2],
                performance: ['completed_cases' => 0],
            ),
        );

        $parts = app(IraOwnerReportFormatter::class)->formatTelegramMessages($report, 'Ravi');

        $this->assertNotEmpty($parts);
        $this->assertGreaterThan(1, count($parts));

        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(IraOwnerReportFormatter::TELEGRAM_MAX_LENGTH, strlen($part));
        }
    }

    private function createOwnerWithTelegram(string $chatId, string $name = 'Owner User'): User
    {
        $owner = User::factory()->create([
            'name' => $name,
            'first_name' => explode(' ', $name)[0],
            'last_name' => explode(' ', $name)[1] ?? '',
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $owner;
    }

    private function createAdminWithTelegram(string $chatId): User
    {
        $admin = User::factory()->create([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $admin;
    }

    private function createOpsAdminWithTelegram(string $chatId): User
    {
        $opsAdmin = User::factory()->create([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $opsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $opsAdmin;
    }

    private function createSupportAgentWithTelegram(string $chatId): User
    {
        $agent = User::factory()->create([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        return $agent;
    }

    private function createIncidentFor(User $agent, string $orderId): Incident
    {
        $creator = User::factory()->create();
        $creator->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => null,
            'customer_name' => 'Owner Report Customer',
            'customer_email' => 'owner-report@example.com',
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
            'title' => 'Owner intelligence case',
            'description' => 'Owner intelligence case.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $agent->id,
        ]);
    }
}
