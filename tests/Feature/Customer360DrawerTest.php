<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\AuditLogService;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\RequestSerialCommunicationHistoryService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Support\AppDateFormatter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Customer360DrawerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_customer_360_endpoint_returns_html_fragment_for_authorized_user(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-360-HTML',
            'serial_number' => 'SN-360-HTML',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-HTML',
            'customer_name' => 'Drawer Customer',
            'customer_email' => 'drawer@example.com',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Drawer case',
            'description' => 'Drawer case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $response = $this->actingAs($agent)->get(route('dashboard.service-cases.customer-360', $incident));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $response->assertSee('Drawer Customer', false);
        $response->assertSee('9123456780', false);
        $response->assertSee('data-customer-360-section="health-card"', false);
        $response->assertSee('data-customer-360-timeline-tab', false);
        $response->assertSee('data-customer-360-ai-tab', false);
        $response->assertSee('data-customer-360-section="quick-actions"', false);
        $response->assertDontSee('Copy Phone', false);
        $response->assertDontSee('Copy Email', false);
        $response->assertSee('data-customer-360-copy="phone"', false);
        $response->assertSee('data-customer-360-copy="email"', false);
        $response->assertSee('data-customer-360-copy="serial"', false);
        $response->assertSee('data-customer-360-copy="order-id"', false);
        $response->assertSee('aria-label="Copy Customer Phone"', false);
        $response->assertSee('Customer snapshot', false);
        $response->assertSee('Assigned agent', false);
        $response->assertSee('Current device', false);
        $response->assertSee('Unavailable', false);
        $response->assertDontSee('data-c360-ops-status-bar', false);
    }

    public function test_customer_360_overflow_menu_renders_grouped_case_actions(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-360-OVERFLOW',
            'serial_number' => 'SN-360-OVERFLOW',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-OVERFLOW',
            'customer_name' => 'Overflow Customer',
            'customer_email' => 'overflow@example.com',
            'customer_phone' => '9123456781',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Overflow menu case',
            'description' => 'Overflow menu case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('c360-quick-toolbar-more-group-label', $html);
        $this->assertStringContainsString('>Communication<', $html);
        $this->assertStringContainsString('>Case<', $html);
        $this->assertStringContainsString('>Appointments<', $html);
        $this->assertStringContainsString('>Related<', $html);
        $this->assertStringContainsString('Assign Engineer', $html);
        $this->assertStringContainsString('Close Case', $html);
        $this->assertStringContainsString('Schedule Appointment', $html);
        $this->assertStringContainsString('c360-quick-toolbar-more-item--destructive', $html);
        $this->assertStringContainsString('data-workspace-trigger="action"', $html);
        $this->assertStringContainsString('data-workspace-action-type="assign"', $html);
        $this->assertStringContainsString('data-workspace-action-type="close"', $html);
        $this->assertStringContainsString('Open Case', $html);
        $this->assertStringNotContainsString('data-customer-360-section="communication-actions"', $html);
    }

    public function test_customer_360_health_card_shows_whatsapp_communication_timestamp(): void
    {
        [$agent, $incident] = $this->createHealthCardIncident();
        $sentAt = Carbon::parse('2026-07-05 22:15:00', AppDateFormatter::timezone());

        WhatsAppTemplateDispatch::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $incident->order_id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => 'request_serial_number',
            'template_name' => 'order_update_request_serial',
            'template_display_name' => 'Order Update',
            'template_purpose' => 'Request Serial Number',
            'trigger_source' => WhatsAppTemplateTriggerSource::Manual,
            'status' => WhatsAppTemplateDispatchStatus::Sent,
            'customer_phone' => '9123456780',
            'interakt_message_id' => 'msg-health-card-whatsapp',
            'dispatched_at' => $sentAt,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('WhatsApp', false)
            ->assertSee('SENT', false)
            ->assertSee(AppDateFormatter::format(
                $sentAt,
                RequestSerialCommunicationHistoryService::LAST_SENT_DISPLAY_FORMAT,
            ), false);
    }

    public function test_customer_360_health_card_shows_email_communication_timestamp(): void
    {
        [$agent, $incident] = $this->createHealthCardIncident();
        $sentAt = Carbon::parse('2026-07-05 22:16:00', AppDateFormatter::timezone());

        app(AuditLogService::class)->log(
            userId: $agent->id,
            event: NotificationAuditTrailService::EVENT_DISPATCHED,
            auditable: $incident,
            newValues: [
                'notification_type' => 'request_serial_number',
                'source' => 'customer360',
                'trigger_source' => 'manual',
                'aggregate_success' => true,
                'aggregate_message' => 'Notification sent',
                'channel_results' => [
                    [
                        'channel' => 'email',
                        'status' => 'sent',
                        'success' => true,
                        'retryable' => false,
                        'message' => 'Email notification sent successfully.',
                        'timestamp' => $sentAt->toIso8601String(),
                        'duration_ms' => 45,
                    ],
                ],
            ],
        );

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('drawer@example.com', false);
    }

    public function test_customer_360_health_card_shows_failed_whatsapp_status(): void
    {
        [$agent, $incident] = $this->createHealthCardIncident();

        WhatsAppTemplateDispatch::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $incident->order_id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => 'request_serial_number',
            'template_name' => 'order_update_request_serial',
            'template_display_name' => 'Order Update',
            'template_purpose' => 'Request Serial Number',
            'trigger_source' => WhatsAppTemplateTriggerSource::Manual,
            'status' => WhatsAppTemplateDispatchStatus::Failed,
            'customer_phone' => '9123456780',
            'error_message' => 'Interakt rejected the template.',
        ]);

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('WhatsApp', $html);
        $this->assertSame(1, substr_count($html, 'FAILED'));
    }

    public function test_customer_360_health_card_uses_customer_scope_communication_history(): void
    {
        [$agent, $incident] = $this->createHealthCardIncident();
        $sharedPhone = '9123456780';
        $sentAt = Carbon::parse('2026-07-05 22:15:00', AppDateFormatter::timezone());

        $otherOrder = Order::query()->create([
            'order_id' => 'RD-360-OTHER',
            'serial_number' => 'SN-360-OTHER',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Drawer Customer',
            'customer_email' => 'drawer@example.com',
            'customer_phone' => $sharedPhone,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        WhatsAppTemplateDispatch::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $otherOrder->id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => 'request_serial_number',
            'template_name' => 'order_update_request_serial',
            'template_display_name' => 'Order Update',
            'template_purpose' => 'Request Serial Number',
            'trigger_source' => WhatsAppTemplateTriggerSource::Manual,
            'status' => WhatsAppTemplateDispatchStatus::Sent,
            'customer_phone' => $sharedPhone,
            'interakt_message_id' => 'msg-health-card-other-order',
            'dispatched_at' => $sentAt,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee(AppDateFormatter::format(
                $sentAt,
                RequestSerialCommunicationHistoryService::LAST_SENT_DISPLAY_FORMAT,
            ), false);
    }

    public function test_customer_360_endpoint_requires_authentication(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-360-DENY',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Private case',
            'description' => 'Private case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertRedirect(route('login'));
    }

    public function test_dashboard_includes_customer_360_drawer_host(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-customer-360-drawer', false)
            ->assertSee('data-customer-360-url', false);
    }

    public function test_customer_360_shows_disabled_serial_requested_action_when_already_sent(): void
    {
        config(['interakt.templates.request_serial_number.name' => 'order_update_request_serial']);

        [$agent, $incident] = $this->createHealthCardIncident();
        $sentAt = Carbon::parse('2026-07-05 21:45:00', AppDateFormatter::timezone());

        $order = $incident->order;
        $order->update(['serial_number' => null]);

        WhatsAppTemplateDispatch::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $incident->order_id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => 'request_serial_number',
            'template_name' => 'order_update_request_serial',
            'template_display_name' => 'Order Update',
            'template_purpose' => 'Request Serial Number',
            'trigger_source' => WhatsAppTemplateTriggerSource::Manual,
            'status' => WhatsAppTemplateDispatchStatus::Sent,
            'customer_phone' => '9123456780',
            'interakt_message_id' => 'msg-serial-requested-action',
            'dispatched_at' => $sentAt,
        ]);

        $html = $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Serial requested', $html);
        $this->assertStringContainsString(
            AppDateFormatter::format($sentAt, RequestSerialCommunicationHistoryService::LAST_SENT_DISPLAY_FORMAT),
            $html,
        );
        $this->assertStringNotContainsString('data-workspace-trigger="request-serial"', $html);
    }

    public function test_customer_360_shows_active_request_serial_action_when_not_yet_sent(): void
    {
        config(['interakt.templates.request_serial_number.name' => 'order_update_request_serial']);

        [$agent, $incident] = $this->createHealthCardIncident();

        $incident->order->update(['serial_number' => null]);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-workspace-trigger="request-serial"', false)
            ->assertSee('Request Serial', false)
            ->assertDontSee('Serial requested', false);
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createHealthCardIncident(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-360-HEALTH',
            'serial_number' => 'SN-360-HEALTH',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'transaction_id' => 'TXN-HEALTH',
            'customer_name' => 'Drawer Customer',
            'customer_email' => 'drawer@example.com',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Health card case',
            'description' => 'Health card case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }
}
