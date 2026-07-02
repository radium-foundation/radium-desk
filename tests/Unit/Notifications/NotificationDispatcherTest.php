<?php

namespace Tests\Unit\Notifications;

use App\Contracts\Notifications\NotificationChannel;
use App\Data\NotificationDispatchResult;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Data\WhatsAppTemplateDispatchResult;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Enums\WhatsAppTemplate;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\Incident;
use App\Models\Order;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsAppTemplateDispatch;
use App\Services\IncidentReferenceService;
use App\Services\Interakt\WhatsAppAutomationDispatcher;
use App\Services\Notifications\Channels\DesktopChannel;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\Channels\WhatsAppChannel;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class NotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dispatcher_registers_four_channels(): void
    {
        $dispatcher = app(NotificationDispatcher::class);

        $this->assertCount(4, $dispatcher->channels());
        $this->assertContainsOnlyInstancesOf(
            NotificationChannel::class,
            $dispatcher->channels(),
        );
    }

    public function test_dispatcher_resolves_enabled_channels_that_support_type(): void
    {
        $dispatcher = app(NotificationDispatcher::class);

        $channels = $dispatcher->resolveEnabledChannels(NotificationType::RequestSerialNumber);

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(WhatsAppChannel::class, $channels[0]);
    }

    public function test_disabled_channels_are_skipped(): void
    {
        $this->setNotificationChannelEnabled('notifications.whatsapp.enabled', false);

        $dispatcher = app(NotificationDispatcher::class);

        $this->assertSame([], $dispatcher->resolveEnabledChannels(NotificationType::RequestSerialNumber));
    }

    public function test_send_returns_failure_when_no_enabled_channels_are_available(): void
    {
        $this->setNotificationChannelEnabled('notifications.whatsapp.enabled', false);

        $result = app(NotificationDispatcher::class)->send(
            NotificationType::RequestSerialNumber,
            $this->makeMessage()[0],
        );

        $this->assertFalse($result->success);
        $this->assertSame([], $result->results);
        $this->assertSame('No notification channels are available.', $result->message);
    }

    public function test_send_fans_out_to_all_enabled_channels_without_prioritization(): void
    {
        $this->setNotificationChannelEnabled('notifications.whatsapp.enabled', true);
        $this->setNotificationChannelEnabled('notifications.email.enabled', true);
        $this->setNotificationChannelEnabled('notifications.desktop.enabled', true);
        $this->setNotificationChannelEnabled('notifications.telegram.enabled', true);

        [$message, $dispatch] = $this->makeMessage(withDispatch: true);

        $automationDispatcher = Mockery::mock(WhatsAppAutomationDispatcher::class);
        $automationDispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturn(WhatsAppTemplateDispatchResult::success(
                $dispatch,
                'WhatsApp template sent successfully.',
            ));

        $dispatcher = new NotificationDispatcher(
            app(SystemSettingsService::class),
            [
                new WhatsAppChannel($automationDispatcher),
                app(EmailChannel::class),
                app(DesktopChannel::class),
                app(TelegramChannel::class),
            ],
        );

        $result = $dispatcher->send(NotificationType::RequestSerialNumber, $message);

        $this->assertTrue($result->success);
        $this->assertCount(4, $result->results);
        $this->assertSame(NotificationChannelType::WhatsApp, $result->results[0]->channel);
        $this->assertSame(NotificationChannelType::Email, $result->results[1]->channel);
        $this->assertSame(NotificationChannelType::Desktop, $result->results[2]->channel);
        $this->assertSame(NotificationChannelType::Telegram, $result->results[3]->channel);
        $this->assertSame('Not Yet Configured', $result->results[2]->message);
        $this->assertSame('Not Yet Configured', $result->results[3]->message);
    }

    public function test_send_aggregates_results_and_succeeds_when_any_channel_succeeds(): void
    {
        $this->setNotificationChannelEnabled('notifications.whatsapp.enabled', true);
        $this->setNotificationChannelEnabled('notifications.email.enabled', true);

        [$message, $dispatch] = $this->makeMessage(withDispatch: true);

        $automationDispatcher = Mockery::mock(WhatsAppAutomationDispatcher::class);
        $automationDispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturn(WhatsAppTemplateDispatchResult::success(
                $dispatch,
                'WhatsApp template sent successfully.',
            ));

        $dispatcher = new NotificationDispatcher(
            app(SystemSettingsService::class),
            [
                new WhatsAppChannel($automationDispatcher),
                app(EmailChannel::class),
            ],
        );

        $result = $dispatcher->send(NotificationType::RequestSerialNumber, $message);

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->results);
        $this->assertSame('WhatsApp template sent successfully.', $result->message);
        $this->assertTrue($result->results[0]->success);
        $this->assertFalse($result->results[1]->success);
        $this->assertSame(NotificationChannelType::Email, $result->results[1]->channel);
        $this->assertSame('missing_customer_email', $result->results[1]->metadata['status']);
    }

    public function test_send_includes_email_channel_when_enabled_and_customer_has_email(): void
    {
        $this->setNotificationChannelEnabled('notifications.whatsapp.enabled', false);
        $this->setNotificationChannelEnabled('notifications.email.enabled', true);

        config([
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);

        [$message] = $this->makeMessage(withDispatch: false, customerEmail: 'customer@example.com');

        $result = app(NotificationDispatcher::class)->send(NotificationType::RequestSerialNumber, $message);

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->results);
        $this->assertTrue($result->results[0]->success);
        $this->assertSame(NotificationChannelType::Email, $result->results[0]->channel);
        $this->assertSame('Email notification sent successfully.', $result->message);
    }

    public function test_disabled_email_channel_is_skipped_by_dispatcher(): void
    {
        $this->setNotificationChannelEnabled('notifications.whatsapp.enabled', false);
        $this->setNotificationChannelEnabled('notifications.email.enabled', false);

        [$message] = $this->makeMessage(withDispatch: false, customerEmail: 'customer@example.com');

        $result = app(NotificationDispatcher::class)->send(NotificationType::RequestSerialNumber, $message);

        $this->assertFalse($result->success);
        $this->assertSame([], $result->results);
        $this->assertSame('No notification channels are available.', $result->message);
    }

    public function test_send_aggregates_failure_when_all_channels_fail(): void
    {
        $this->setNotificationChannelEnabled('notifications.whatsapp.enabled', true);

        [$message, $dispatch] = $this->makeMessage(withDispatch: true);

        $automationDispatcher = Mockery::mock(WhatsAppAutomationDispatcher::class);
        $automationDispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturn(WhatsAppTemplateDispatchResult::failure(
                $dispatch,
                'WhatsApp dispatch failed.',
            ));

        $dispatcher = new NotificationDispatcher(
            app(SystemSettingsService::class),
            [new WhatsAppChannel($automationDispatcher)],
        );

        $result = $dispatcher->send(NotificationType::RequestSerialNumber, $message);

        $this->assertFalse($result->success);
        $this->assertSame('WhatsApp dispatch failed.', $result->message);
        $this->assertCount(1, $result->results);
        $this->assertTrue($result->results[0]->retryable);
    }

    public function test_dispatch_result_aggregation_prefers_successful_channel_message(): void
    {
        $results = [
            NotificationResult::failure(
                channel: NotificationChannelType::Email,
                message: 'Customer email address is not available.',
                metadata: ['status' => 'missing_customer_email'],
            ),
            NotificationResult::success(
                channel: NotificationChannelType::WhatsApp,
                externalId: 'msg-001',
                message: 'WhatsApp template sent successfully.',
            ),
        ];

        $aggregate = NotificationDispatchResult::fromResults($results);

        $this->assertTrue($aggregate->success);
        $this->assertSame('WhatsApp template sent successfully.', $aggregate->message);
        $this->assertCount(2, $aggregate->results);
    }

    private function setNotificationChannelEnabled(string $key, bool $enabled): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $enabled ? '1' : '0'],
        );

        app(SystemSettingsService::class)->forget($key);
    }

    /**
     * @return array{0: NotificationMessage, 1: WhatsAppTemplateDispatch|null}
     */
    private function makeMessage(bool $withDispatch = false, ?string $customerEmail = null): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-NOTIF-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'customer_email' => $customerEmail,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Notification dispatcher case',
            'description' => 'Notification dispatcher case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $dispatch = null;

        if ($withDispatch) {
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
            ]);
            $dispatch->id = 55;
        }

        $message = new NotificationMessage(
            type: NotificationType::RequestSerialNumber,
            customer: $order,
            incident: $incident,
            actor: $agent,
        );

        return [$message, $dispatch];
    }
}
