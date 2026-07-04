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
use App\Services\Interakt\WhatsAppTemplateConfigurationResolver;
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

        config([
            'interakt.templates.request_serial_number.name' => 'order_update_request_serial',
            'interakt.templates.request_serial_number.language_code' => 'en',
            'interakt.templates.support_appointment_booked.name' => 'support_appointment_booked',
            'interakt.templates.support_appointment_booked.language_code' => 'en',
        ]);

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
        $order = $message->customer;

        $automationDispatcher = Mockery::mock(WhatsAppAutomationDispatcher::class);
        $automationDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(
                WhatsAppTemplate::RequestSerialNumber,
                $message->incident,
                WhatsAppTemplateTriggerSource::Manual,
                $message->actor,
                Mockery::on(function (array $context) use ($order): bool {
                    return ($context['source'] ?? null) === 'customer360'
                        && ($context['header_values'] ?? null) === [(string) $order->order_id]
                        && ($context['body_values'] ?? null) === ['Customer', (string) $order->order_id];
                }),
                $message->httpRequest,
            )
            ->andReturn(WhatsAppTemplateDispatchResult::success(
                $dispatch,
                'WhatsApp template sent successfully.',
            ));

        $channel = new WhatsAppChannel(
            $automationDispatcher,
            app(WhatsAppTemplateConfigurationResolver::class),
        );
        $result = $channel->send($message);

        $this->assertTrue($result->success);
        $this->assertSame(NotificationChannelType::WhatsApp, $result->channel);
        $this->assertSame('msg-delegated-001', $result->external_id);
        $this->assertSame('WhatsApp template sent successfully.', $result->message);
        $this->assertFalse($result->retryable);
        $this->assertSame($dispatch->id, $result->metadata['dispatch_id']);
    }

    public function test_send_skips_whatsapp_when_template_is_not_configured(): void
    {
        config(['interakt.templates.request_serial_number.name' => '']);

        [$message] = $this->makeMessage();

        $automationDispatcher = Mockery::mock(WhatsAppAutomationDispatcher::class);
        $automationDispatcher->shouldNotReceive('dispatch');

        $channel = new WhatsAppChannel(
            $automationDispatcher,
            app(WhatsAppTemplateConfigurationResolver::class),
        );
        $result = $channel->send($message);

        $this->assertTrue($result->success);
        $this->assertTrue($result->isSkipped());
        $this->assertSame('Skipped - Template not configured', $result->message);
        $this->assertSame('not_yet_configured', $result->metadata['status']);
        $this->assertSame('request_serial_number', $result->metadata['template_key']);
    }

    public function test_send_skips_support_appointment_booked_when_template_is_not_configured(): void
    {
        config(['interakt.templates.support_appointment_booked.name' => '']);

        [$message] = $this->makeSupportAppointmentMessage();

        $automationDispatcher = Mockery::mock(WhatsAppAutomationDispatcher::class);
        $automationDispatcher->shouldNotReceive('dispatch');

        $channel = new WhatsAppChannel(
            $automationDispatcher,
            app(WhatsAppTemplateConfigurationResolver::class),
        );
        $result = $channel->send($message);

        $this->assertTrue($result->success);
        $this->assertTrue($result->isSkipped());
        $this->assertSame('Skipped - Template not configured', $result->message);
        $this->assertSame('support_appointment_booked', $result->metadata['template_key']);
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

        $channel = new WhatsAppChannel(
            $automationDispatcher,
            app(WhatsAppTemplateConfigurationResolver::class),
        );
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

    /**
     * @return array{0: NotificationMessage}
     */
    private function makeSupportAppointmentMessage(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WA-SAB-'.uniqid(),
            'serial_number' => 'SN-WA-SAB',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => 'support-booked@example.com',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Support appointment WhatsApp channel case',
            'description' => 'Support appointment WhatsApp channel case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $message = new NotificationMessage(
            type: NotificationType::SupportAppointmentBooked,
            customer: $order,
            incident: $incident,
            metadata: [
                'source' => 'support_appointment_web',
                'trigger_source' => WhatsAppTemplateTriggerSource::Manual->value,
            ],
            actor: $agent,
        );

        return [$message];
    }
}
