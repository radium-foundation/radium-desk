<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationType;
use App\Enums\ServiceCaseCloseExceptionReason;
use App\Enums\WaitingReason;
use App\Models\AutomationExecution;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AI\IRAExecutiveSummaryService;
use App\Services\Automation\AutomationSchedulerService;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\Interakt\InteraktOutboundOutboxWriter;
use App\Services\ServiceCaseActivityTimelineService;
use App\Services\ServiceCaseStatusService;
use App\Services\SystemSettingsService;
use Tests\Support\AIContextFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerWaitingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_serial_number.display_name' => 'Order Update',
            'interakt.templates.request_serial_number.language_code' => 'en',
            'interakt.templates.request_serial_number.internal_note' => 'Requested serial number from customer via approved WhatsApp template.',
            'interakt.templates.customer_waiting_followup.name' => 'support_schedule_followup',
            'interakt.templates.customer_waiting_followup.display_name' => 'Support Reminder',
            'interakt.templates.customer_waiting_followup.language_code' => 'en',
            'interakt.templates.customer_waiting_followup.internal_note' => 'Reminder that support is paused until the customer shares requested details.',
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
            'first_name' => 'Ira',
            'last_name' => 'Automation',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        foreach ([
            'automation.scheduler.enabled' => true,
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ] as $key => $enabled) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $enabled ? '1' : '0'],
            );

            app(SystemSettingsService::class)->forget($key);
        }

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-customer-waiting-001'], 200),
        ]);
    }

    public function test_waiting_customer_under_24_hours_sends_no_reminder(): void
    {
        Carbon::setTestNow('2026-07-06 10:00:00');

        $waitingState = $this->createWaitingState(startedAt: Carbon::parse('2026-07-06 09:30:00'));

        app(AutomationSchedulerService::class)->run(Carbon::parse('2026-07-06 10:00:00'));

        $this->assertDatabaseCount('automation_executions', 0);
        $this->assertNull($waitingState->fresh()->customer_followup_sent_at);
    }

    public function test_waiting_customer_over_24_hours_sends_followup_once(): void
    {
        Carbon::setTestNow('2026-07-07 10:00:00');

        $waitingState = $this->createWaitingState(startedAt: Carbon::parse('2026-07-06 09:00:00'));

        app(AutomationSchedulerService::class)->run(Carbon::parse('2026-07-07 10:00:00'));

        $this->assertDatabaseHas('automation_executions', [
            'waiting_state_id' => $waitingState->id,
            'policy_key' => 'customer_waiting_default',
            'schedule_step' => 1,
            'action_type' => AutomationPolicyActionType::WhatsAppTemplate->value,
            'action_key' => 'customer_waiting_followup',
            'status' => AutomationExecutionStatus::Success->value,
        ]);

        $this->assertNotNull($waitingState->fresh()->customer_followup_sent_at);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'notification.dispatched',
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => InteraktOutboundOutboxWriter::EVENT_TYPE,
            'status' => 'completed',
        ]);
    }

    public function test_followup_already_sent_is_not_duplicated(): void
    {
        Carbon::setTestNow('2026-07-07 12:00:00');

        $waitingState = $this->createWaitingState(startedAt: Carbon::parse('2026-07-06 09:00:00'));

        app(AutomationSchedulerService::class)->run(Carbon::parse('2026-07-07 10:00:00'));
        app(AutomationSchedulerService::class)->run(Carbon::parse('2026-07-07 12:00:00'));

        $this->assertSame(
            1,
            AutomationExecution::query()
                ->where('waiting_state_id', $waitingState->id)
                ->where('action_key', 'customer_waiting_followup')
                ->where('status', AutomationExecutionStatus::Success)
                ->count(),
        );
    }

    public function test_24_hours_after_followup_auto_closes_case_with_remark(): void
    {
        [$agent, $incident, $waitingState] = $this->createWaitingScenario(
            startedAt: Carbon::parse('2026-07-06 09:00:00'),
        );

        Carbon::setTestNow('2026-07-07 10:00:00');
        app(AutomationSchedulerService::class)->run(Carbon::parse('2026-07-07 10:00:00'));

        $waitingState = $waitingState->fresh();
        $this->assertNotNull($waitingState->customer_followup_sent_at);

        Carbon::setTestNow('2026-07-08 10:00:00');
        app(AutomationSchedulerService::class)->run(Carbon::parse('2026-07-08 10:00:00'));

        $incident = $incident->fresh();
        $waitingState = $waitingState->fresh();

        $this->assertSame(IncidentStatus::Closed, $incident->status);
        $this->assertNotNull($waitingState->cleared_at);

        $this->assertDatabaseHas('automation_executions', [
            'waiting_state_id' => $waitingState->id,
            'action_key' => 'customer_not_responding',
            'status' => AutomationExecutionStatus::Success->value,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => CustomerWaitingLifecycleService::EVENT_AUTO_CLOSED,
        ]);

        $this->assertDatabaseHas('remarks', [
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => CustomerWaitingLifecycleService::AUTO_CLOSE_REMARK,
        ]);

        $autoClosedAudit = $incident->fresh()->remarks()->first();
        $this->assertNotNull($autoClosedAudit);
    }

    public function test_customer_return_after_auto_close_shows_history_to_ira(): void
    {
        [$agent, $incident] = array_slice(
            $this->createWaitingScenario(startedAt: Carbon::parse('2026-07-06 09:00:00')),
            0,
            2,
        );

        Carbon::setTestNow('2026-07-07 10:00:00');
        app(AutomationSchedulerService::class)->run(Carbon::parse('2026-07-07 10:00:00'));

        Carbon::setTestNow('2026-07-08 10:00:00');
        app(AutomationSchedulerService::class)->run(Carbon::parse('2026-07-08 10:00:00'));

        app(ServiceCaseStatusService::class)->reopen($incident->fresh(), $agent);

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($incident->fresh());
        $titles = $timeline->pluck('title')->all();

        $this->assertContains('Waiting for customer input', $titles);
        $this->assertContains('Closed automatically — customer not responding', $titles);
        $this->assertContains('Service case reopened.', $titles);

        $context = AIContextFactory::make([
            'waitingState' => app(IncidentWaitingStateService::class)->lifecycleOnlyCard($incident->fresh()),
        ]);

        $response = app(\App\Services\AI\AIService::class)->buildBundle($incident->fresh())->response;
        $summary = app(IRAExecutiveSummaryService::class)->build(
            incident: $incident->fresh(),
            response: $response,
            context: $context,
            customerSummary: ['open_cases' => 1],
        );

        $this->assertStringContainsString('auto-closed after a follow-up reminder', implode(' ', $summary->executiveSummary));
        $this->assertStringContainsString('Customer not responding', $summary->opinion);
    }

    /**
     * @return array{0: IncidentWaitingState}
     */
    private function createWaitingState(Carbon $startedAt): IncidentWaitingState
    {
        [, , $waitingState] = $this->createWaitingScenario($startedAt);

        return $waitingState;
    }

    /**
     * @return array{0: User, 1: Incident, 2: IncidentWaitingState}
     */
    private function createWaitingScenario(Carbon $startedAt): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CW-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Waiting Customer',
            'customer_phone' => '9876543210',
            'customer_email' => 'waiting.customer@example.com',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Customer waiting lifecycle',
            'description' => 'Waiting for customer input.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $waitingState = app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::Photos,
            actor: $agent,
            startedAt: $startedAt,
        );

        $this->assertSame('customer_waiting_default', $waitingState->reminder_policy_key);

        $this->assertDatabaseHas('audit_logs', [
            'event' => CustomerWaitingLifecycleService::EVENT_WAITING_STARTED,
        ]);

        return [$agent, $incident, $waitingState];
    }
}
