<?php

namespace Tests\Feature;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\IraNotificationStatus;
use App\Enums\IraNotificationType;
use App\Enums\WaitingReason;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\IraNotification;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Operations\AutomationHealthService;
use App\Services\Operations\ProductionWatchdogService;
use App\Services\Platform\Health\AutomationHealthProvider;
use App\Services\Platform\PlatformAutomationOverviewService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntelligentAutomationAlertSemanticsTest extends TestCase
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
            'ira.watchdog.enabled' => true,
            'app.url' => 'http://localhost',
            'automation.scheduler.enabled' => true,
        ]);

        foreach ([
            'automation.scheduler.enabled' => true,
            'notifications.telegram.enabled' => true,
        ] as $key => $enabled) {
            \App\Models\SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $enabled ? '1' : '0'],
            );
            app(\App\Services\SystemSettingsService::class)->forget($key);
        }

        Cache::flush();
    }

    public function test_historical_already_closed_failures_do_not_create_watchdog_or_telegram_alerts(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
            'localhost/*' => Http::response('OK', 200),
        ]);

        $this->createOwnerWithTelegram('900000001');
        $this->createFailedExecutions(3, 'Service case is already closed.');

        $alerts = app(ProductionWatchdogService::class)->collectCriticalAlerts();
        $automationAlerts = array_values(array_filter(
            $alerts,
            fn ($alert) => $alert->key === 'automation:failures',
        ));

        $this->assertSame([], $automationAlerts);

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();

        $this->assertSame(0, IraNotification::query()
            ->where('notification_type', IraNotificationType::CriticalSystemAlert->value)
            ->count());
    }

    public function test_open_failures_send_telegram_once_and_suppress_unchanged_repeats(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 2]], 200),
            'localhost/*' => Http::response('OK', 200),
        ]);

        $this->createOwnerWithTelegram('900000002');
        $this->createFailedExecutions(2, 'Channel timeout');

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertSame(1, IraNotification::query()
            ->where('notification_type', IraNotificationType::CriticalSystemAlert->value)
            ->where('status', IraNotificationStatus::Sent->value)
            ->count());

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertSame(1, IraNotification::query()
            ->where('notification_type', IraNotificationType::CriticalSystemAlert->value)
            ->where('status', IraNotificationStatus::Sent->value)
            ->count());
    }

    public function test_severity_increase_sends_updated_telegram_alert(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 3]], 200),
            'localhost/*' => Http::response('OK', 200),
        ]);

        $this->createOwnerWithTelegram('900000003');
        $this->createFailedExecutions(2, 'Channel timeout');

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertSame(1, IraNotification::query()->where('status', IraNotificationStatus::Sent->value)->count());

        $this->createFailedExecutions(1, 'Channel timeout');

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertSame(2, IraNotification::query()->where('status', IraNotificationStatus::Sent->value)->count());
    }

    public function test_resolved_open_failures_clear_fingerprint_and_allow_realert(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 4]], 200),
            'localhost/*' => Http::response('OK', 200),
        ]);

        $this->createOwnerWithTelegram('900000004');
        $waiting = $this->createWaitingState();
        $this->createFailedExecutionsForWaiting($waiting, 2, 'Channel timeout');

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertSame(1, IraNotification::query()->where('status', IraNotificationStatus::Sent->value)->count());

        // Ledger stays immutable in production; here we simulate resolution by
        // reclassifying outcomes as terminal so open/critical counts clear.
        AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed)
            ->update(['error_message' => 'Service case is already closed.']);
        Cache::flush();

        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertSame(1, IraNotification::query()->where('status', IraNotificationStatus::Sent->value)->count());
        $this->assertSame(
            [],
            array_values(array_filter(
                app(ProductionWatchdogService::class)->collectCriticalAlerts(),
                fn ($alert) => $alert->key === 'automation:failures',
            )),
        );

        $this->createFailedExecutionsForWaiting($waiting, 2, 'Channel timeout');
        Cache::forget(app(\App\Services\Operations\AutomationHealthService::class)::aggregationCacheKey());
        $this->artisan('watchdog:send-critical-alerts')->assertSuccessful();
        $this->assertSame(2, IraNotification::query()->where('status', IraNotificationStatus::Sent->value)->count());
    }

    public function test_automation_health_separates_historical_open_and_critical(): void
    {
        $this->createFailedExecutions(3, 'Service case is already closed.');
        $this->createFailedExecutions(1, 'Channel timeout');

        Cache::flush();
        $overview = app(AutomationHealthService::class)->overviewAggregation();

        $this->assertSame(4, $overview['failures_today']);
        $this->assertSame(3, $overview['historical_failures_today']);
        $this->assertSame(1, $overview['open_failures_today']);
        $this->assertSame(0, $overview['critical_failures_today']);
        $this->assertSame('warning', $overview['health_status']);
    }

    public function test_platform_health_ignores_historical_failures(): void
    {
        config(['ira.watchdog.automation_failure_threshold' => 2]);
        $this->createFailedExecutions(5, 'Service case is already closed.');

        Cache::flush();
        $component = app(AutomationHealthProvider::class)->probe();

        $this->assertSame('healthy', $component->status->value);
        $this->assertStringContainsString('historical', strtolower($component->detail));
    }

    public function test_expand_diagnostics_expose_failure_buckets(): void
    {
        $this->createFailedExecutions(2, 'Service case is already closed.');
        $this->createFailedExecutions(2, 'Interakt API timeout');

        Cache::flush();
        $diagnostics = app(PlatformAutomationOverviewService::class)->diagnostics('automation_health');

        $this->assertSame(2, $diagnostics['historical_failures_today']);
        $this->assertSame(2, $diagnostics['open_failures_today']);
        $this->assertSame(2, $diagnostics['critical_failures_today']);
        $this->assertStringContainsString('Historical 2', $diagnostics['message']);
        $this->assertStringContainsString('Open 2', $diagnostics['message']);
        $this->assertStringContainsString('Critical 2', $diagnostics['message']);
    }

    private function createOwnerWithTelegram(string $chatId): User
    {
        $owner = User::factory()->create([
            'telegram_chat_id' => $chatId,
            'telegram_notifications_enabled' => true,
            'is_active' => true,
        ]);
        $owner->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $owner;
    }

    private function createFailedExecutions(int $count, string $errorMessage): void
    {
        $this->createFailedExecutionsForWaiting($this->createWaitingState(), $count, $errorMessage);
    }

    private function createFailedExecutionsForWaiting(
        IncidentWaitingState $waitingState,
        int $count,
        string $errorMessage,
    ): void {
        for ($index = 0; $index < $count; $index++) {
            AutomationExecution::query()->create([
                'waiting_state_id' => $waitingState->id,
                'policy_key' => 'customer_waiting_default',
                'schedule_step' => $index + 1,
                'action_type' => AutomationPolicyActionType::AutoClose,
                'action_key' => 'customer_not_responding',
                'channel' => null,
                'status' => AutomationExecutionStatus::Failed,
                'idempotency_key' => 'alert.semantics.'.$waitingState->id.'.'.$index.'.'.uniqid(),
                'error_message' => $errorMessage,
                'started_at' => now(),
                'completed_at' => now(),
            ]);
        }
    }

    private function createWaitingState(): IncidentWaitingState
    {
        $actor = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-ALERT-'.uniqid(),
            'customer_name' => 'Alert Customer',
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
            'title' => 'Alert semantics case',
            'description' => 'Test.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
        ]);

        return IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subHour(),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
