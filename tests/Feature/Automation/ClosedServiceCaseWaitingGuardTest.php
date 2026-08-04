<?php

namespace Tests\Feature\Automation;

use App\Data\Automation\PlannedAutomationAction;
use App\Enums\AutomationPolicyActionType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Enums\WaitingReason;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\Automation\CustomerWaitingLifecycleService;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\MissingSerial\MissingSerialAutomationAuditService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClosedServiceCaseWaitingGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-04 18:00:00');

        config([
            'missing_serial.enabled' => true,
            'missing_serial.first_delay_minutes' => 15,
            'missing_serial.reminder_delay_hours' => 24,
            'missing_serial.escalation_delay_hours' => 72,
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_serial_number.display_name' => 'Order Update',
            'interakt.templates.request_serial_number.language_code' => 'en',
            'interakt.templates.customer_waiting_followup.name' => 'support_schedule_followup',
            'interakt.templates.customer_waiting_followup.display_name' => 'Support Reminder',
            'interakt.templates.customer_waiting_followup.language_code' => 'en',
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);

        User::factory()->create([
            'email' => 'superadmin@radium.local',
        ])->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        foreach ([
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
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-closed-guard'], 200),
        ]);

        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_closed_service_case_never_starts_waiting(): void
    {
        [$agent, $incident] = $this->createClosedIncident();

        $started = app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
        );

        $this->assertNull($started);
        $this->assertSame(0, IncidentWaitingState::query()->where('incident_id', $incident->id)->count());
        $this->assertDatabaseMissing('audit_logs', [
            'event' => CustomerWaitingLifecycleService::EVENT_WAITING_STARTED,
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_ensure_serial_waiting_skips_closed_case(): void
    {
        [$agent, $incident] = $this->createClosedIncident();

        $ensured = app(IncidentWaitingStateService::class)->ensureSerialWaitingState($incident, $agent);

        $this->assertNull($ensured);
        $this->assertSame(0, IncidentWaitingState::query()->where('incident_id', $incident->id)->count());
    }

    public function test_missing_serial_automation_skips_closed_cases_without_contact(): void
    {
        $order = $this->createEligibleOrderWithClosedCase(paymentMinutesAgo: 30);

        Artisan::call('missing-serial:process');

        $order->refresh();

        $this->assertNull($order->missing_serial_automation_status);
        $this->assertSame(0, WhatsAppTemplateDispatch::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, IncidentWaitingState::query()->where('incident_id', $order->latestIncident()->id)->count());
        $this->assertDatabaseMissing('audit_logs', [
            'auditable_id' => $order->id,
            'event' => MissingSerialAutomationAuditService::EVENT_REQUEST_SENT,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'notification.dispatched',
            'auditable_id' => $order->latestIncident()->id,
        ]);
    }

    public function test_missing_serial_candidate_query_excludes_orders_without_open_case(): void
    {
        $closedOnly = $this->createEligibleOrderWithClosedCase(paymentMinutesAgo: 30);
        $open = $this->createEligibleOrderWithOpenCase(paymentMinutesAgo: 30);

        $ids = app(\App\Services\MissingSerial\MissingSerialAutomationService::class)
            ->prioritizedCandidateOrdersQuery()
            ->pluck('id')
            ->all();

        $this->assertContains($open->id, $ids);
        $this->assertNotContains($closedOnly->id, $ids);
    }

    public function test_auto_close_on_already_closed_case_is_idempotent_success_and_clears_orphan(): void
    {
        [$agent, $incident] = $this->createClosedIncident();

        $waitingState = IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subDay(),
            'customer_followup_sent_at' => now()->subDay(),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $action = new PlannedAutomationAction(
            waitingState: $waitingState->fresh(['incident.order', 'incident.supportAppointments']),
            policyKey: 'customer_waiting_default',
            scheduleStep: 1,
            actionType: AutomationPolicyActionType::AutoClose,
            actionKey: 'customer_not_responding',
            channel: null,
            scheduledAt: now(),
        );

        $result = app(CustomerWaitingLifecycleService::class)->autoCloseForNoResponse($action);

        $this->assertTrue($result->success);
        $this->assertSame('customer-waiting-already-closed', $result->externalId);
        $this->assertSame('Already closed - waiting cleared.', $result->metadata['message'] ?? null);
        $this->assertNotNull($waitingState->fresh()->cleared_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => CustomerWaitingLifecycleService::EVENT_ALREADY_CLOSED_WAITING_CLEARED,
            'auditable_id' => $incident->id,
        ]);
    }

    public function test_open_service_cases_still_start_waiting_normally(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-OPEN-WAIT',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open',
            'description' => 'Open.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $started = app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
        );

        $this->assertNotNull($started);
        $this->assertNull($started->cleared_at);
        $this->assertSame('customer_waiting_default', $started->reminder_policy_key);
    }

    public function test_clear_orphans_command_dry_run_and_apply(): void
    {
        [$agent, $incident] = $this->createClosedIncident();

        IncidentWaitingState::query()->create([
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber,
            'started_at' => now()->subDay(),
            'sla_paused' => true,
            'reminder_policy_key' => 'customer_waiting_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $this->artisan('customer-waiting:clear-orphans-on-closed', ['--dry-run' => true])
            ->expectsOutputToContain('Total found: 1')
            ->expectsOutputToContain('Total repaired: 0')
            ->assertSuccessful();

        $this->assertNull(
            IncidentWaitingState::query()->where('incident_id', $incident->id)->value('cleared_at'),
        );

        $this->artisan('customer-waiting:clear-orphans-on-closed')
            ->expectsOutputToContain('Total found: 1')
            ->expectsOutputToContain('Total repaired: 1')
            ->assertSuccessful();

        $this->assertNotNull(
            IncidentWaitingState::query()->where('incident_id', $incident->id)->value('cleared_at'),
        );
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createClosedIncident(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CLOSED-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => 'closed@example.com',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed',
            'description' => 'Closed.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    private function createEligibleOrderWithClosedCase(int $paymentMinutesAgo): Order
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-MS-CLOSED-'.uniqid(),
            'cashfree_payment_id' => 'cf_pay_'.uniqid(),
            'payment_date' => now()->subMinutes($paymentMinutesAgo),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => null,
            'customer_name' => 'Closed Case Customer',
            'customer_email' => 'closed.case@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced->value,
            'radiumbox_sync_attempts' => 1,
            'radiumbox_last_sync_at' => now()->subHour(),
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Closed cashfree case',
            'description' => 'Closed.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        return $order->fresh();
    }

    private function createEligibleOrderWithOpenCase(int $paymentMinutesAgo): Order
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-MS-OPEN-'.uniqid(),
            'cashfree_payment_id' => 'cf_pay_'.uniqid(),
            'payment_date' => now()->subMinutes($paymentMinutesAgo),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => null,
            'customer_name' => 'Open Case Customer',
            'customer_email' => 'open.case@example.com',
            'customer_phone' => '9876543211',
            'status' => 'active',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Synced->value,
            'radiumbox_sync_attempts' => 1,
            'radiumbox_last_sync_at' => now()->subHour(),
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Cashfree,
            'title' => 'Open cashfree case',
            'description' => 'Awaiting serial.',
            'status' => IncidentStatus::AwaitingProductDetails,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        return $order->fresh();
    }
}
