<?php

namespace Tests\Feature;

use App\Data\Operations\IraCommunicationInput;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\TeamBroadcastAudience;
use App\Enums\WaitingReason;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\IraNotification;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\ProductionWatchdogService;
use App\Services\Operations\TeamTelegramBroadcastService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductionWatchdogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'ira.communication.cooldown_minutes' => 60,
            'ira.watchdog.automation_failure_threshold' => 2,
            'app.url' => 'http://localhost',
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }

    public function test_watchdog_ignores_skipped_enquiry_spam_automation_blocks(): void
    {
        Http::fake([
            'localhost/*' => Http::response('OK', 200),
        ]);

        $this->createSkippedEnquirySpamAutomationExecutions(3);

        $alerts = app(ProductionWatchdogService::class)->collectCriticalAlerts();

        $automationAlerts = array_values(array_filter(
            $alerts,
            fn ($alert) => $alert->key === 'automation:failures',
        ));

        $this->assertSame([], $automationAlerts);
    }

    public function test_watchdog_sends_critical_automation_alert_to_superadmin(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 77],
            ], 200),
            'localhost/*' => Http::response('OK', 200),
        ]);

        $owner = $this->createOwnerWithTelegram('811111111');

        $this->createFailedAutomationExecutions(2);

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $owner->id,
            'notification_type' => IraNotificationType::CriticalSystemAlert->value,
            'status' => IraNotificationStatus::Sent->value,
        ]);

        $this->assertGreaterThanOrEqual(1, IraNotification::query()
            ->where('notification_type', IraNotificationType::CriticalSystemAlert->value)
            ->where('status', IraNotificationStatus::Sent->value)
            ->count());
    }

    public function test_critical_alert_respects_cooldown_dedupe(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 78],
            ], 200),
            'localhost/*' => Http::response('OK', 200),
        ]);

        $this->createOwnerWithTelegram('822222222');

        $this->createFailedAutomationExecutions(2);

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $sentAfterFirst = IraNotification::query()
            ->where('status', IraNotificationStatus::Sent->value)
            ->count();

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $sentAfterSecond = IraNotification::query()
            ->where('status', IraNotificationStatus::Sent->value)
            ->count();

        $this->assertSame($sentAfterFirst, $sentAfterSecond);
        $this->assertGreaterThanOrEqual(1, $sentAfterFirst);
    }

    public function test_team_broadcast_sends_announcement_to_selected_members(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 79],
            ], 200),
        ]);

        $superadmin = $this->createOwnerWithTelegram('833333333', 'Super Admin');
        $agent = User::factory()->create([
            'telegram_chat_id' => '844444444',
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $result = app(TeamTelegramBroadcastService::class)->broadcast(
            sender: $superadmin,
            message: 'Emergency maintenance tonight at 11 PM.',
            audience: TeamBroadcastAudience::Selected,
            selectedUserIds: [$agent->id],
            subject: 'Maintenance Notice',
        );

        $this->assertSame(1, $result['recipients']);
        $this->assertSame(1, $result['sent']);

        $this->assertDatabaseHas('ira_notifications', [
            'user_id' => $agent->id,
            'notification_type' => IraNotificationType::TeamAnnouncement->value,
            'status' => IraNotificationStatus::Sent->value,
        ]);
    }

    public function test_watchdog_records_uptime_probe(): void
    {
        $date = now()->toDateString();
        Cache::put('watchdog:uptime:'.$date, [
            'total' => 10,
            'degraded' => 2,
            'incidents' => 1,
            'last_healthy' => true,
        ], now()->addDay());

        $summary = app(ProductionWatchdogService::class)->todayUptimeSummary();

        $this->assertSame(10, $summary['total_checks']);
        $this->assertSame(80.0, $summary['uptime_percent']);
        $this->assertSame(1, $summary['downtime_incidents']);
    }

    public function test_critical_alert_message_includes_affected_count(): void
    {
        Http::fake();

        $owner = $this->createOwnerWithTelegram('855555555');

        $results = app(IraCommunicationService::class)->dispatch(new IraCommunicationInput(
            event: IraNotificationType::CriticalSystemAlert,
            context: [
                'label' => 'Queue',
                'message' => '3 failed job(s) in dead-letter queue.',
                'affected_count' => 3,
                'dedupe_key' => 'watchdog:queue:dead_letter',
            ],
        ));

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Affected: 3', $results[0]->message);
        $this->assertSame($owner->id, $results[0]->user_id);
    }

    private function createSkippedEnquirySpamAutomationExecutions(int $count): void
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-WATCHDOG-SKIP-'.uniqid(),
            'customer_name' => 'Test Customer',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Watchdog skipped block test case',
            'description' => 'Test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);
        $waitingState = IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subHour(),
            'sla_paused' => true,
            'reminder_policy_key' => 'request_serial',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        for ($index = 0; $index < $count; $index++) {
            AutomationExecution::query()->create([
                'waiting_state_id' => $waitingState->id,
                'policy_key' => 'request_serial',
                'schedule_step' => $index + 1,
                'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
                'action_key' => 'request_serial_number',
                'channel' => 'whatsapp',
                'status' => AutomationExecutionStatus::Skipped,
                'idempotency_key' => 'watchdog.skipped.test.'.$index.'.'.uniqid(),
                'error_message' => 'Automated customer notification blocked for enquiry/spam case.',
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        }
    }

    private function createFailedAutomationExecutions(int $count): void
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-WATCHDOG-'.uniqid(),
            'customer_name' => 'Test Customer',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Watchdog test case',
            'description' => 'Test case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);
        $waitingState = IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subHour(),
            'sla_paused' => true,
            'reminder_policy_key' => 'request_serial',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        for ($index = 0; $index < $count; $index++) {
            AutomationExecution::query()->create([
                'waiting_state_id' => $waitingState->id,
                'policy_key' => 'request_serial',
                'schedule_step' => $index + 1,
                'action_type' => AutomationPolicyActionType::WhatsAppTemplate,
                'action_key' => 'request_serial_number',
                'channel' => 'whatsapp',
                'status' => AutomationExecutionStatus::Failed,
                'idempotency_key' => 'watchdog.test.'.$index.'.'.uniqid(),
                'error_message' => 'Automation failed in test.',
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        }
    }

    private function createOwnerWithTelegram(string $chatId, string $name = 'Owner User'): User
    {
        $owner = User::factory()->create([
            'name' => $name,
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $owner;
    }
}
