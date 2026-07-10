<?php

namespace Tests\Unit\Notifications;

use App\Contracts\Notifications\NotificationChannel;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\Channels\DesktopChannel;
use App\Services\Notifications\Channels\TelegramChannel;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Services\Notifications\NotificationDeliverySummaryFormatter;
use App\Services\ServiceCaseActivityTimelineService;
use App\Services\SystemSettingsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class NotificationDispatchHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dispatcher_logs_start_channel_and_aggregate_events(): void
    {
        Log::spy();

        $dispatcher = $this->makeDispatcher([
            $this->successfulChannel(NotificationChannelType::WhatsApp, 'WhatsApp sent.'),
            app(DesktopChannel::class),
        ]);

        $dispatcher->send(NotificationType::RequestSerialNumber, $this->makeMessage());

        Log::shouldHaveReceived('info')
            ->with('notification.dispatch.started', Mockery::on(
                fn (array $context): bool => $context['channel_count'] === 2
            ))
            ->once();

        Log::shouldHaveReceived('info')
            ->with('notification.dispatch.channel.started', Mockery::type('array'))
            ->twice();

        Log::shouldHaveReceived('info')
            ->with('notification.dispatch.channel.completed', Mockery::on(
                fn (array $context): bool => isset($context['duration_ms'])
            ))
            ->twice();

        Log::shouldHaveReceived('info')
            ->with('notification.dispatch.completed', Mockery::on(
                fn (array $context): bool => $context['success'] === true
                    && $context['delivered_count'] === 1
            ))
            ->once();
    }

    public function test_dispatcher_persists_audit_trail_with_per_channel_records(): void
    {
        $dispatcher = $this->makeDispatcher([
            $this->successfulChannel(NotificationChannelType::WhatsApp, 'WhatsApp sent.'),
            $this->failedChannel(NotificationChannelType::Email, 'Missing email.'),
        ]);

        $message = $this->makeMessage();
        $dispatcher->send(NotificationType::RequestSerialNumber, $message);

        $auditLog = AuditLog::query()->where('event', NotificationAuditTrailService::EVENT_DISPATCHED)->first();

        $this->assertNotNull($auditLog);
        $this->assertSame($message->incident->id, $auditLog->auditable_id);
        $this->assertCount(2, $auditLog->new_values['channel_results']);

        $whatsapp = collect($auditLog->new_values['channel_results'])
            ->firstWhere('channel', NotificationChannelType::WhatsApp->value);

        $this->assertTrue($whatsapp['success']);
        $this->assertSame('sent', $whatsapp['status']);
        $this->assertFalse($whatsapp['retryable']);
        $this->assertSame('WhatsApp sent.', $whatsapp['message']);
        $this->assertNotEmpty($whatsapp['timestamp']);
        $this->assertIsInt($whatsapp['duration_ms']);
    }

    public function test_dispatcher_continues_after_channel_exception(): void
    {
        Log::spy();

        $dispatcher = $this->makeDispatcher([
            new StubNotificationChannel(
                NotificationChannelType::WhatsApp,
                exception: new \RuntimeException('Channel exploded.'),
            ),
            $this->successfulChannel(NotificationChannelType::Email, 'Email sent.'),
        ]);

        $result = $dispatcher->send(NotificationType::RequestSerialNumber, $this->makeMessage());

        $this->assertTrue($result->success);
        $this->assertCount(2, $result->results);
        $this->assertFalse($result->results[0]->success);
        $this->assertSame('exception', $result->results[0]->status());
        $this->assertTrue($result->results[0]->retryable);
        $this->assertTrue($result->results[1]->success);

        Log::shouldHaveReceived('error')
            ->with('notification.dispatch.channel.exception', Mockery::type('array'))
            ->once();
    }

    public function test_complete_failure_excludes_skipped_channels_from_aggregate_success(): void
    {
        $dispatcher = $this->makeDispatcher([
            $this->failedChannel(NotificationChannelType::WhatsApp, 'WhatsApp dispatch failed.'),
            app(DesktopChannel::class),
            app(TelegramChannel::class),
        ]);

        $result = $dispatcher->send(NotificationType::RequestSerialNumber, $this->makeMessage());

        $this->assertFalse($result->success);

        $summary = app(NotificationDeliverySummaryFormatter::class)->failureMessage($result);

        $this->assertStringContainsString('Notification failed', $summary);
        $this->assertStringContainsString('✗ WhatsApp: WhatsApp dispatch failed.', $summary);
        $this->assertStringContainsString('⏭ Desktop (Not configured)', $summary);
    }

    public function test_notification_audit_trail_appears_on_incident_timeline(): void
    {
        $dispatcher = $this->makeDispatcher([
            $this->successfulChannel(NotificationChannelType::WhatsApp, 'WhatsApp sent.'),
            app(TelegramChannel::class),
        ]);

        $message = $this->makeMessage();
        $dispatcher->send(NotificationType::RequestSerialNumber, $message);

        $timeline = app(ServiceCaseActivityTimelineService::class)->forIncident($message->incident->fresh());

        $entry = $timeline->first(
            fn ($item) => $item->title === 'Notification sent'
                && str_contains((string) $item->body, '✓ WhatsApp')
        );

        $this->assertNotNull($entry);
        $this->assertStringContainsString('⏭ Telegram (Not configured)', (string) $entry->body);
    }

    /**
     * @param  array<int, NotificationChannel>  $channels
     */
    private function makeDispatcher(array $channels): TestableNotificationDispatcher
    {
        return new TestableNotificationDispatcher(
            app(SystemSettingsService::class),
            $channels,
            app(NotificationAuditTrailService::class),
        );
    }

    private function successfulChannel(NotificationChannelType $type, string $message): StubNotificationChannel
    {
        return new StubNotificationChannel(
            $type,
            NotificationResult::success(
                channel: $type,
                message: $message,
                metadata: ['status' => 'sent'],
            ),
        );
    }

    private function failedChannel(NotificationChannelType $type, string $message): StubNotificationChannel
    {
        return new StubNotificationChannel(
            $type,
            NotificationResult::failure(
                channel: $type,
                message: $message,
                retryable: true,
                metadata: ['status' => 'failed'],
            ),
        );
    }

    private function makeMessage(): NotificationMessage
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-HARD-'.uniqid(),
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
            'title' => 'Notification hardening case',
            'description' => 'Notification hardening case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return new NotificationMessage(
            type: NotificationType::RequestSerialNumber,
            customer: $order,
            incident: $incident,
            actor: $agent,
            metadata: [
                'source' => 'customer360',
            ],
        );
    }
}

class TestableNotificationDispatcher extends \App\Services\Notifications\NotificationDispatcher
{
    /**
     * @param  array<int, NotificationChannelType>|null  $allowedChannels
     * @return array<int, NotificationChannel>
     */
    public function resolveEnabledChannels(NotificationType $type, ?array $allowedChannels = null): array
    {
        $channels = array_values(array_filter(
            $this->channels(),
            fn (NotificationChannel $channel): bool => $channel->supports($type),
        ));

        if ($allowedChannels === null) {
            return $channels;
        }

        $allowed = array_map(
            fn (NotificationChannelType $channel): string => $channel->value,
            $allowedChannels,
        );

        return array_values(array_filter(
            $channels,
            fn (NotificationChannel $channel): bool => in_array(
                $this->channelTypeFor($channel)?->value,
                $allowed,
                true,
            ),
        ));
    }

    public function channelTypeFor(NotificationChannel $channel): ?NotificationChannelType
    {
        if ($channel instanceof StubNotificationChannel) {
            return $channel->type;
        }

        return parent::channelTypeFor($channel);
    }
}

final class StubNotificationChannel implements NotificationChannel
{
    public function __construct(
        public readonly NotificationChannelType $type,
        private readonly ?NotificationResult $result = null,
        private readonly ?\Throwable $exception = null,
    ) {}

    public function supports(NotificationType $type): bool
    {
        return match ($type) {
            NotificationType::RequestSerialNumber => true,
        };
    }

    public function send(NotificationMessage $message): NotificationResult
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->result ?? NotificationResult::failure(
            channel: $this->type,
            message: 'Stub channel result missing.',
            metadata: ['status' => 'failed'],
        );
    }
}
