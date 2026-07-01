<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TimelineEventType;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\InteraktMessage;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Remark;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\InteraktOutboundOutboxWriter;
use App\Services\Interakt\WhatsAppAutomationDispatcher;
use App\Services\Interakt\WhatsAppTemplateConfigurationResolver;
use App\Services\Interakt\WhatsAppTemplateDispatcher;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTemplateDispatcherTest extends TestCase
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
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_manual_request_serial_dispatches_template_via_outbox(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WS-TPL',
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
            'title' => 'Missing serial case',
            'description' => 'Missing serial case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-template-001'], 200),
        ]);

        $response = $this->actingAs($agent)->postJson(
            route('incidents.workspace.request-serial', $incident),
            ['workspace_context' => 'customer'],
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('extensions.refresh_customer360', true);

        $this->assertDatabaseHas('outbox_events', [
            'event_type' => InteraktOutboundOutboxWriter::EVENT_TYPE,
            'status' => 'completed',
        ]);

        $dispatch = WhatsAppTemplateDispatch::query()->first();
        $this->assertNotNull($dispatch);
        $this->assertSame(WhatsAppTemplateDispatchStatus::Sent, $dispatch->status);
        $this->assertSame('order_update_request_serial', $dispatch->template_name);
        $this->assertSame('Order Update', $dispatch->template_display_name);
        $this->assertSame('Request Serial Number', $dispatch->template_purpose);
        $this->assertSame(WhatsAppTemplateTriggerSource::Manual, $dispatch->trigger_source);
        $this->assertSame('msg-template-001', $dispatch->interakt_message_id);

        $this->assertDatabaseHas('interakt_messages', [
            'message_id' => 'msg-template-001',
            'customer_phone' => '9876543210',
            'template_name' => 'order_update_request_serial',
        ]);

        $this->assertDatabaseHas('remarks', [
            'remarkable_type' => $incident->getMorphClass(),
            'remarkable_id' => $incident->id,
            'body' => 'Requested serial number from customer via approved WhatsApp template.',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'event' => 'whatsapp.template_sent',
        ]);
    }

    public function test_timeline_shows_whatsapp_template_sent_event_without_message_body(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WS-TL',
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
            'title' => 'Timeline template case',
            'description' => 'Timeline template case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        WhatsAppTemplateDispatch::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => 'request_serial_number',
            'template_name' => 'order_update_request_serial',
            'template_display_name' => 'Order Update',
            'template_purpose' => 'Request Serial Number',
            'trigger_source' => WhatsAppTemplateTriggerSource::Manual,
            'status' => WhatsAppTemplateDispatchStatus::Sent,
            'customer_phone' => '9876543210',
            'interakt_message_id' => 'msg-template-tl',
            'dispatched_at' => now(),
        ]);

        InteraktMessage::query()->create([
            'message_id' => 'msg-template-tl',
            'customer_phone' => '9876543210',
            'direction' => 'outgoing',
            'message_type' => 'template',
            'template_name' => 'order_update_request_serial',
            'text' => 'Secret template body should not appear',
            'sent_at' => now(),
        ]);

        $timeline = app(Customer360TimelineService::class)->forOrder($order);
        $templateEvent = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->first(fn ($event) => $event->type === TimelineEventType::WhatsAppTemplateSent);

        $this->assertNotNull($templateEvent);
        $this->assertSame('Sent', $templateEvent->statusLabel);
        $this->assertSame('Order Update', collect($templateEvent->summaryFields)->firstWhere('label', 'Template')['value']);
        $this->assertSame('Request Serial Number', collect($templateEvent->summaryFields)->firstWhere('label', 'Purpose')['value']);
        $this->assertSame('Manual', collect($templateEvent->summaryFields)->firstWhere('label', 'Trigger')['value']);
        $this->assertNull($templateEvent->summary);
        $this->assertNull($templateEvent->detail);
    }

    public function test_dispatcher_failure_records_failed_dispatch_and_audit_log(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WS-FAIL',
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
            'title' => 'Failed template case',
            'description' => 'Failed template case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['message' => 'Template not approved'], 400),
        ]);

        $result = app(WhatsAppTemplateDispatcher::class)->dispatch(
            template: \App\Enums\WhatsAppTemplate::RequestSerialNumber,
            incident: $incident,
            actor: $agent,
            triggerSource: WhatsAppTemplateTriggerSource::Manual,
        );

        $this->assertFalse($result->success);
        $this->assertSame(WhatsAppTemplateDispatchStatus::Failed, $result->dispatch?->status);
        $this->assertSame(0, InteraktMessage::query()->count());
        $this->assertSame(0, Remark::query()->count());

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
            'event' => 'whatsapp.template_failed',
        ]);
    }

    public function test_configuration_resolver_reads_template_from_config(): void
    {
        $configuration = app(WhatsAppTemplateConfigurationResolver::class)
            ->resolve(\App\Enums\WhatsAppTemplate::RequestSerialNumber);

        $this->assertSame('order_update_request_serial', $configuration->name);
        $this->assertSame('Order Update', $configuration->displayName);
        $this->assertSame('Request Serial Number', $configuration->purpose);
    }

    public function test_automation_dispatcher_accepts_non_manual_trigger_sources(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WS-AUTO',
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
            'title' => 'Automation template case',
            'description' => 'Automation template case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-auto-001'], 200),
        ]);

        $result = app(WhatsAppAutomationDispatcher::class)->dispatch(
            template: \App\Enums\WhatsAppTemplate::RequestSerialNumber,
            incident: $incident,
            triggerSource: WhatsAppTemplateTriggerSource::Scheduler,
            actor: null,
            context: ['rule' => 'daily_serial_reminder'],
        );

        $this->assertTrue($result->success);
        $this->assertSame(WhatsAppTemplateTriggerSource::Scheduler, $result->dispatch?->trigger_source);
        $this->assertSame(['rule' => 'daily_serial_reminder'], $result->dispatch?->context);
    }

    public function test_request_serial_action_hidden_when_serial_is_valid(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WS-HIDE',
            'serial_number' => '7881953',
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
            'title' => 'Valid serial case',
            'description' => 'Valid serial case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertDontSee('Request Serial Number', false);

        $this->actingAs($agent)
            ->postJson(route('incidents.workspace.request-serial', $incident), [
                'workspace_context' => 'customer',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_drawer_shows_confirmation_dialog_fragment(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WS-DIALOG',
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
            'title' => 'Dialog case',
            'description' => 'Dialog case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [$incident, 'request-serial']).'?workspace_context=customer')
            ->assertOk()
            ->assertSee('Request Serial Number', false)
            ->assertSee('Send Request', false)
            ->assertSee('approved WhatsApp template', false);
    }
}
