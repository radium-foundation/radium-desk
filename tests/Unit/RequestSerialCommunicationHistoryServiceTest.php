<?php

namespace Tests\Unit;

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

class RequestSerialCommunicationHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_for_customer_phone_returns_latest_sent_whatsapp_across_orders(): void
    {
        $agent = User::factory()->create();
        $sharedPhone = '9123456780';
        $olderSentAt = Carbon::parse('2026-07-04 09:00:00', AppDateFormatter::timezone());
        $latestSentAt = Carbon::parse('2026-07-05 22:15:00', AppDateFormatter::timezone());

        $orderOne = Order::query()->create([
            'order_id' => 'RD-COMM-ONE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => $sharedPhone,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $orderTwo = Order::query()->create([
            'order_id' => 'RD-COMM-TWO',
            'product_name' => 'MFS 110 E3',
            'device_model' => 'MFS 110 E3',
            'customer_phone' => $sharedPhone,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incidentOne = Incident::query()->create([
            'order_id' => $orderOne->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Order one case',
            'description' => 'Order one case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $incidentTwo = Incident::query()->create([
            'order_id' => $orderTwo->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Order two case',
            'description' => 'Order two case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        WhatsAppTemplateDispatch::query()->create([
            'incident_id' => $incidentOne->id,
            'order_id' => $orderOne->id,
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
            'incident_id' => $incidentTwo->id,
            'order_id' => $orderTwo->id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => 'request_serial_number',
            'template_name' => 'order_update_request_serial',
            'template_display_name' => 'Order Update',
            'template_purpose' => 'Request Serial Number',
            'trigger_source' => WhatsAppTemplateTriggerSource::Manual,
            'status' => WhatsAppTemplateDispatchStatus::Sent,
            'customer_phone' => $sharedPhone,
            'interakt_message_id' => 'msg-latest',
            'dispatched_at' => $latestSentAt,
        ]);

        $history = app(RequestSerialCommunicationHistoryService::class)->forCustomerPhone($sharedPhone);

        $this->assertSame('sent', $history['whatsapp']['status']);
        $this->assertSame('SENT', $history['whatsapp']['status_label']);
        $this->assertTrue($latestSentAt->equalTo($history['whatsapp']['last_sent_at']));
    }

    public function test_for_customer_phone_returns_latest_email_from_incident_audit_logs(): void
    {
        $agent = User::factory()->create();
        $sharedPhone = '9123456780';
        $sentAt = Carbon::parse('2026-07-05 22:16:00', AppDateFormatter::timezone());

        $order = Order::query()->create([
            'order_id' => 'RD-COMM-EMAIL',
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
            'title' => 'Email history case',
            'description' => 'Email history case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

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

        $history = app(RequestSerialCommunicationHistoryService::class)->forCustomerPhone($sharedPhone);

        $this->assertSame('sent', $history['email']['status']);
        $this->assertSame('SENT', $history['email']['status_label']);
        $this->assertTrue($sentAt->equalTo($history['email']['last_sent_at']));
    }
}
