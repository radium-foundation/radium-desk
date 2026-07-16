<?php

namespace Tests\Unit\Customer360;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\WhatsAppTemplateDispatchStatus;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\AuditLogService;
use App\Services\Customer360\Customer360RecentCommunicationService;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Support\AppDateFormatter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Customer360RecentCommunicationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_for_customer_phone_includes_driver_installation_guide_whatsapp(): void
    {
        $agent = User::factory()->create();
        $sharedPhone = '9123456780';
        $sentAt = Carbon::parse('2026-07-05 22:15:00', AppDateFormatter::timezone());

        $order = Order::query()->create([
            'order_id' => 'RD-COMM-DRIVER-WA',
            'product_name' => 'FM 220',
            'device_model' => 'FM 220',
            'customer_phone' => $sharedPhone,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Driver guide case',
            'description' => 'Driver guide case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        WhatsAppTemplateDispatch::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => 'driver_installation_guide',
            'template_name' => 'driver_installation_guide_template',
            'template_display_name' => 'Driver Installation Guide',
            'template_purpose' => 'Driver Installation Guide',
            'trigger_source' => WhatsAppTemplateTriggerSource::Automation,
            'status' => WhatsAppTemplateDispatchStatus::Sent,
            'customer_phone' => $sharedPhone,
            'interakt_message_id' => 'msg-driver-guide',
            'dispatched_at' => $sentAt,
        ]);

        $history = app(Customer360RecentCommunicationService::class)->forCustomerPhone($sharedPhone);

        $this->assertSame('sent', $history['whatsapp']['status']);
        $this->assertSame('SENT', $history['whatsapp']['status_label']);
        $this->assertTrue($sentAt->equalTo($history['whatsapp']['last_sent_at']));
    }

    public function test_for_customer_phone_includes_driver_installation_guide_email(): void
    {
        $agent = User::factory()->create();
        $sharedPhone = '9123456780';
        $sentAt = Carbon::parse('2026-07-05 22:16:00', AppDateFormatter::timezone());

        $order = Order::query()->create([
            'order_id' => 'RD-COMM-DRIVER-EMAIL',
            'product_name' => 'FM 220',
            'device_model' => 'FM 220',
            'customer_phone' => $sharedPhone,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Driver guide email case',
            'description' => 'Driver guide email case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        app(AuditLogService::class)->log(
            userId: $agent->id,
            event: NotificationAuditTrailService::EVENT_DISPATCHED,
            auditable: $incident,
            newValues: [
                'notification_type' => 'driver_installation_guide',
                'communication_action_key' => 'driver_installation_guide',
                'source' => 'automation',
                'trigger_source' => 'automation',
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

        $history = app(Customer360RecentCommunicationService::class)->forCustomerPhone($sharedPhone);

        $this->assertSame('sent', $history['email']['status']);
        $this->assertSame('SENT', $history['email']['status_label']);
        $this->assertTrue($sentAt->equalTo($history['email']['last_sent_at']));
    }

    public function test_for_customer_phone_returns_latest_whatsapp_across_notification_types(): void
    {
        $agent = User::factory()->create();
        $sharedPhone = '9123456780';
        $olderSentAt = Carbon::parse('2026-07-04 09:00:00', AppDateFormatter::timezone());
        $latestSentAt = Carbon::parse('2026-07-05 22:15:00', AppDateFormatter::timezone());

        $order = Order::query()->create([
            'order_id' => 'RD-COMM-LATEST-WA',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => $sharedPhone,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Latest whatsapp case',
            'description' => 'Latest whatsapp case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
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
            'customer_phone' => $sharedPhone,
            'interakt_message_id' => 'msg-older',
            'dispatched_at' => $olderSentAt,
        ]);

        WhatsAppTemplateDispatch::query()->create([
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => 'driver_installation_guide',
            'template_name' => 'driver_installation_guide_template',
            'template_display_name' => 'Driver Installation Guide',
            'template_purpose' => 'Driver Installation Guide',
            'trigger_source' => WhatsAppTemplateTriggerSource::Automation,
            'status' => WhatsAppTemplateDispatchStatus::Sent,
            'customer_phone' => $sharedPhone,
            'interakt_message_id' => 'msg-latest',
            'dispatched_at' => $latestSentAt,
        ]);

        $history = app(Customer360RecentCommunicationService::class)->forCustomerPhone($sharedPhone);

        $this->assertTrue($latestSentAt->equalTo($history['whatsapp']['last_sent_at']));
    }
}
