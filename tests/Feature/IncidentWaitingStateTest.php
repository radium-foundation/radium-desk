<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Enums\WaitingReason;
use App\Exceptions\ActiveWaitingStateExistsException;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\IncidentWaitingStateService;
use App\Services\Interakt\InteraktOutboundOutboxWriter;
use App\Services\SystemSettingsService;
use App\Support\AppDateFormatter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IncidentWaitingStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_serial_number.display_name' => 'Order Update',
            'interakt.templates.request_serial_number.language_code' => 'en',
            'interakt.templates.request_serial_number.internal_note' => 'Requested serial number from customer via approved WhatsApp template.',
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);

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
    }

    public function test_request_serial_action_creates_waiting_state_with_sla_paused(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-waiting-001'], 200),
        ]);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-serial', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('extensions.refresh_customer360', true);

        $this->assertDatabaseHas('incident_waiting_states', [
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber->value,
            'sla_paused' => true,
            'reminder_policy_key' => 'serial_number_default',
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $waitingState = IncidentWaitingState::query()->first();
        $this->assertNotNull($waitingState);
        $this->assertNull($waitingState->cleared_at);
        $this->assertNotNull($waitingState->started_at);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => InteraktOutboundOutboxWriter::EVENT_TYPE,
            'status' => 'completed',
        ]);
    }

    public function test_request_serial_action_is_idempotent_when_waiting_state_already_active(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-waiting-repeat'], 200),
        ]);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-serial', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk()
            ->assertJsonPath('success', true);

        $firstWaitingStateId = IncidentWaitingState::query()->value('id');

        $response = $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-serial', $incident),
            ['workspace_context' => 'customer'],
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $toastMessage = $response->json('toast.message');
        $this->assertStringContainsString('Notification sent', $toastMessage);
        $this->assertStringContainsString('Waiting state already active.', $toastMessage);

        $this->assertSame(1, IncidentWaitingState::query()->count());
        $this->assertSame($firstWaitingStateId, IncidentWaitingState::query()->value('id'));

        $this->assertDatabaseCount('outbox_events', 2);
    }

    public function test_only_one_active_waiting_state_is_allowed_per_incident(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();
        $service = app(IncidentWaitingStateService::class);

        $service->start(
            incident: $incident,
            reason: WaitingReason::Payment,
            actor: $agent,
        );

        $this->expectException(ActiveWaitingStateExistsException::class);

        $service->start(
            incident: $incident,
            reason: WaitingReason::Invoice,
            actor: $agent,
        );
    }

    public function test_clearing_waiting_state_sets_cleared_at(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();
        $service = app(IncidentWaitingStateService::class);

        $service->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
        );

        $cleared = $service->clear($incident, $agent);

        $this->assertNotNull($cleared->cleared_at);
        $this->assertSame($agent->id, $cleared->updated_by);
        $this->assertNull($service->activeFor($incident));
    }

    public function test_new_waiting_state_can_be_created_after_previous_is_cleared(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();
        $service = app(IncidentWaitingStateService::class);

        $service->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
        );
        $service->clear($incident, $agent);

        $second = $service->start(
            incident: $incident,
            reason: WaitingReason::Payment,
            actor: $agent,
        );

        $this->assertSame(WaitingReason::Payment, $second->waiting_reason);
        $this->assertNull($second->cleared_at);
    }

    public function test_customer360_renders_waiting_state_card_when_active(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-05 21:45:00', AppDateFormatter::timezone()));

        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-waiting-360'], 200),
        ]);

        $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-serial', $incident),
            ['workspace_context' => 'customer'],
        )->assertOk();

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk()
            ->assertSee('data-customer-360-section="waiting-state"', false)
            ->assertSee('Waiting for Customer', false)
            ->assertSee('Serial Number', false)
            ->assertSee('Waiting', false)
            ->assertSee('Requested', false)
            ->assertSee('05 Jul, 09:45 PM', false)
            ->assertDontSee('Waiting Since', false)
            ->assertSee('Paused', false)
            ->assertSee('Serial Number Default', false);

        Carbon::setTestNow();
    }

    public function test_customer360_hides_waiting_state_card_when_none_active(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertDontSee('data-customer-360-section="waiting-state"', false);
    }

    public function test_sla_status_returns_paused_when_active_waiting_state_pauses_sla(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
        );

        $incident->refresh()->load('activeWaitingState');

        $this->assertTrue($incident->hasSlaPaused());
        $this->assertSame(ServiceCaseSlaStatus::Paused, $incident->slaStatus());
    }

    public function test_sla_status_resumes_after_waiting_state_is_cleared(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();
        $service = app(IncidentWaitingStateService::class);

        $service->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
        );
        $service->clear($incident, $agent);

        $incident->refresh()->load('activeWaitingState');

        $this->assertFalse($incident->hasSlaPaused());
        $this->assertNotSame(ServiceCaseSlaStatus::Paused, $incident->slaStatus());
    }

    public function test_start_persists_metadata_and_casts_to_array(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();
        $service = app(IncidentWaitingStateService::class);

        $metadata = [
            'template' => 'request_serial_number',
            'attempt' => 1,
            'source' => 'manual',
        ];

        $waitingState = $service->start(
            incident: $incident,
            reason: WaitingReason::SerialNumber,
            actor: $agent,
            metadata: $metadata,
        );

        $this->assertDatabaseHas('incident_waiting_states', [
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::SerialNumber->value,
        ]);

        $rawMetadata = DB::table('incident_waiting_states')
            ->where('id', $waitingState->id)
            ->value('metadata');
        $this->assertIsString($rawMetadata);
        $this->assertSame($metadata, json_decode($rawMetadata, true));

        $waitingState->refresh();
        $this->assertIsArray($waitingState->metadata);
        $this->assertSame($metadata, $waitingState->metadata);
    }

    public function test_start_persists_next_action_at(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();
        $service = app(IncidentWaitingStateService::class);
        $nextActionAt = Carbon::parse('2026-07-05 14:30:00');

        $waitingState = $service->start(
            incident: $incident,
            reason: WaitingReason::Payment,
            actor: $agent,
            nextActionAt: $nextActionAt,
        );

        $this->assertDatabaseHas('incident_waiting_states', [
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::Payment->value,
        ]);

        $waitingState->refresh();
        $this->assertNotNull($waitingState->next_action_at);
        $this->assertTrue($waitingState->next_action_at->equalTo($nextActionAt));
    }

    public function test_start_without_metadata_and_next_action_at_remains_backward_compatible(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();
        $service = app(IncidentWaitingStateService::class);

        $waitingState = $service->start(
            incident: $incident,
            reason: WaitingReason::CustomerApproval,
            actor: $agent,
        );

        $this->assertDatabaseHas('incident_waiting_states', [
            'incident_id' => $incident->id,
            'waiting_reason' => WaitingReason::CustomerApproval->value,
            'metadata' => null,
            'next_action_at' => null,
        ]);

        $waitingState->refresh();
        $this->assertNull($waitingState->metadata);
        $this->assertNull($waitingState->next_action_at);
    }

    public function test_customer360_renders_next_action_when_set(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();
        $nextActionAt = Carbon::parse('2026-07-05 14:30:00');

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::Payment,
            actor: $agent,
            nextActionAt: $nextActionAt,
        );

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk()
            ->assertSee('Next Action', false)
            ->assertSee(AppDateFormatter::datetime($nextActionAt), false);
    }

    public function test_customer360_hides_next_action_when_not_set(): void
    {
        [$agent, $incident] = $this->createOpenIncidentWithoutSerial();

        app(IncidentWaitingStateService::class)->start(
            incident: $incident,
            reason: WaitingReason::Payment,
            actor: $agent,
        );

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk()
            ->assertSee('data-customer-360-section="waiting-state"', false)
            ->assertDontSee('<dt>Next Action</dt>', false);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createOpenIncidentWithoutSerial(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WAIT-'.uniqid(),
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
            'title' => 'Waiting state case',
            'description' => 'Waiting state case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }
}
