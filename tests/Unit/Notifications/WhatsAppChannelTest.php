<?php

namespace Tests\Unit\Notifications;

use App\Data\NotificationMessage;
use App\Data\WhatsAppTemplateDispatchResult;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\WhatsAppAutomationDispatcher;
use App\Services\Notifications\Channels\WhatsAppChannel;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WhatsAppChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_supports_request_serial_number_notification_type(): void
    {
        $channel = app(WhatsAppChannel::class);

        $this->assertTrue($channel->supports(NotificationType::RequestSerialNumber));
    }

    public function test_send_delegates_to_whatsapp_automation_dispatcher(): void
    {
        [$message, $dispatch] = $this->makeMessage();

        $automationDispatcher = Mockery::mock(WhatsAppAutomationDispatcher::class);
        $automationDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(
                WhatsAppTemplate::RequestSerialNumber,
                $message->incident,
                WhatsAppTemplateTriggerSource::Manual,
                $message->actor,
                $message->metadata,
                $message->httpRequest,
            )
            ->andReturn(WhatsAppTemplateDispatchResult::success(
                $dispatch,
                'WhatsApp template sent successfully.',
            ));

        $channel = new WhatsAppChannel($automationDispatcher);
        $result = $channel->send($message);

        $this->assertTrue($result->success);
        $this->assertSame(NotificationChannelType::WhatsApp, $result->channel);
        $this->assertSame('msg-delegated-001', $result->external_id);
        $this->assertSame('WhatsApp template sent successfully.', $result->message);
        $this->assertFalse($result->retryable);
        $this->assertSame($dispatch->id, $result->metadata['dispatch_id']);
    }

    public function test_send_maps_failure_from_automation_dispatcher(): void
    {
        [$message, $dispatch] = $this->makeMessage();

        $automationDispatcher = Mockery::mock(WhatsAppAutomationDispatcher::class);
        $automationDispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturn(WhatsAppTemplateDispatchResult::failure(
                $dispatch,
                'Template not approved.',
            ));

        $channel = new WhatsAppChannel($automationDispatcher);
        $result = $channel->send($message);

        $this->assertFalse($result->success);
        $this->assertSame(NotificationChannelType::WhatsApp, $result->channel);
        $this->assertSame('Template not approved.', $result->message);
        $this->assertTrue($result->retryable);
    }

    /**
     * @return array{0: NotificationMessage, 1: WhatsAppTemplateDispatch}
     */
    private function makeMessage(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WA-CH-'.uniqid(),
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
            'title' => 'WhatsApp channel case',
            'description' => 'WhatsApp channel case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $dispatch = WhatsAppTemplateDispatch::query()->make([
            'incident_id' => $incident->id,
            'order_id' => $order->id,
            'triggered_by_user_id' => $agent->id,
            'template_key' => WhatsAppTemplate::RequestSerialNumber->value,
            'template_name' => 'order_update_request_serial',
            'template_display_name' => 'Order Update',
            'template_purpose' => 'Request Serial Number',
            'trigger_source' => WhatsAppTemplateTriggerSource::Manual,
            'customer_phone' => '9876543210',
            'interakt_message_id' => 'msg-delegated-001',
        ]);
        $dispatch->id = 101;

        $message = new NotificationMessage(
            type: NotificationType::RequestSerialNumber,
            customer: $order,
            incident: $incident,
            metadata: [
                'source' => 'customer360',
                'trigger_source' => WhatsAppTemplateTriggerSource::Manual->value,
            ],
            actor: $agent,
        );

        return [$message, $dispatch];
    }
}
