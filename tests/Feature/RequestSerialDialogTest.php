<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\AuditLogService;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\RequestSerialCommunicationHistoryService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\SystemSettingsService;
use App\Support\AppDateFormatter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RequestSerialDialogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.api_key' => 'test-interakt-key',
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_serial_number.language_code' => 'en_US',
            'interakt.templates.request_serial_number.language_code_is_default' => false,
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);

        $this->enableNotificationChannels([
            'notifications.whatsapp.enabled' => true,
            'notifications.email.enabled' => true,
            'whatsapp.api_enabled' => true,
            'email.api_enabled' => true,
        ]);
    }

    public function test_request_serial_dialog_shows_customer_channels_and_message(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [$incident, 'request-serial']).'?workspace_context=customer')
            ->assertOk()
            ->assertSee('Request Serial Number', false)
            ->assertSee('Executive Summary Customer', false)
            ->assertSee('9123456780', false)
            ->assertSee('Channels', false)
            ->assertSee('Message', false)
            ->assertSee('Serial Number', false)
            ->assertSee('Clear photo of device back label', false)
            ->assertSee('Waiting State', false)
            ->assertSee('Send Request', false)
            ->assertSee('Cancel', false)
            ->assertSee('Interakt Template', false)
            ->assertSee('order_update_request_serial', false)
            ->assertSee('Language', false)
            ->assertSee('en_US', false);
    }

    public function test_request_serial_dialog_shows_fallback_language_warning(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        config([
            'interakt.templates.request_serial_number.language_code' => 'en',
            'interakt.templates.request_serial_number.language_code_is_default' => true,
        ]);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [$incident, 'request-serial']).'?workspace_context=customer')
            ->assertOk()
            ->assertSee('Using fallback language "en"', false);
    }

    public function test_customer360_operations_health_shows_interakt_template_diagnostics(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();
        $order = $incident->order;

        config([
            'interakt.templates.request_serial_number.name' => 'order_confirm_manual_schedule',
            'interakt.templates.request_serial_number.language_code' => 'en_US',
            'interakt.templates.request_serial_number.language_code_is_default' => false,
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Request Serial Template', false)
            ->assertSee('order_confirm_manual_schedule', false)
            ->assertSee('en_US', false);
    }

    public function test_request_serial_dialog_shows_whatsapp_unavailable_reason(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        config(['interakt.api_key' => null]);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [$incident, 'request-serial']).'?workspace_context=customer')
            ->assertOk()
            ->assertSee('WhatsApp unavailable', false)
            ->assertSee('Invalid Interakt token.', false)
            ->assertSee('Email will still be used.', false);
    }

    public function test_request_serial_dialog_shows_email_unavailable_reason(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        app(\App\Services\SystemSettingsService::class)->set('notifications.email.enabled', false);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [$incident, 'request-serial']).'?workspace_context=customer')
            ->assertOk()
            ->assertSee('Email unavailable', false)
            ->assertSee('Email notifications disabled.', false)
            ->assertSee('WhatsApp will still be used.', false);
    }

    public function test_request_serial_dialog_shows_whatsapp_communication_timestamp(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();
        $sentAt = Carbon::parse('2026-07-05 14:30:00', AppDateFormatter::timezone());

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
            'interakt_message_id' => 'msg-request-serial-dialog',
            'dispatched_at' => $sentAt,
        ]);

        $this->actingAs($agent)
            ->get(route('incidents.components.show', [$incident, 'request-serial']).'?workspace_context=customer')
            ->assertOk()
            ->assertSee('SENT', false)
            ->assertSee('Last sent: '.AppDateFormatter::format(
                $sentAt,
                RequestSerialCommunicationHistoryService::LAST_SENT_DISPLAY_FORMAT,
            ), false);
    }

    public function test_request_serial_dialog_shows_email_communication_timestamp(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();
        $sentAt = Carbon::parse('2026-07-04 09:15:00', AppDateFormatter::timezone());

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
            ->get(route('incidents.components.show', [$incident, 'request-serial']).'?workspace_context=customer')
            ->assertOk()
            ->assertSee('SENT', false)
            ->assertSee('Last sent: '.AppDateFormatter::format(
                $sentAt,
                RequestSerialCommunicationHistoryService::LAST_SENT_DISPLAY_FORMAT,
            ), false);
    }

    public function test_request_serial_dialog_shows_not_sent_when_no_communication_history(): void
    {
        [$agent, $incident] = $this->createIncidentWithoutSerial();

        $response = $this->actingAs($agent)
            ->get(route('incidents.components.show', [$incident, 'request-serial']).'?workspace_context=customer');

        $response->assertOk();

        $this->assertSame(2, substr_count($response->getContent(), 'NOT SENT'));
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createIncidentWithoutSerial(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-REQ-SERIAL',
            'serial_number' => null,
            'product_name' => 'FM220',
            'device_model' => 'FM220',
            'customer_name' => 'Executive Summary Customer',
            'customer_email' => 'exec@example.com',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Request serial dialog case',
            'description' => 'Missing serial.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }

    /**
     * @param  array<string, bool>  $channels
     */
    private function enableNotificationChannels(array $channels): void
    {
        foreach ($channels as $key => $enabled) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $enabled ? '1' : '0'],
            );

            app(SystemSettingsService::class)->forget($key);
        }
    }
}
