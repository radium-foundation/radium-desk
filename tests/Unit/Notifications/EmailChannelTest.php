<?php

namespace Tests\Unit\Notifications;

use App\Data\NotificationMessage;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Notifications\Channels\EmailChannel;
use App\Services\Notifications\NotificationCustomerContactResolver;
use App\Services\Notifications\NotificationMailSender;
use App\Services\Notifications\NotificationMailTemplateRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EmailChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'mail.enabled' => true,
            'mail.default' => 'array',
        ]);
    }

    public function test_supports_request_serial_number_notification_type(): void
    {
        $channel = app(EmailChannel::class);

        $this->assertTrue($channel->supports(NotificationType::RequestSerialNumber));
    }

    public function test_send_delivers_email_successfully(): void
    {
        [$message] = $this->makeMessage([
            'customer_email' => 'customer@example.com',
            'customer_name' => 'Jane Doe',
        ]);

        $result = app(EmailChannel::class)->send($message);

        $this->assertTrue($result->success);
        $this->assertSame(NotificationChannelType::Email, $result->channel);
        $this->assertSame('Email notification sent successfully.', $result->message);
        $this->assertFalse($result->retryable);
        $this->assertSame('sent', $result->metadata['status']);
        $this->assertSame('customer@example.com', $result->metadata['recipient_email']);
        $this->assertSame(NotificationType::RequestSerialNumber->value, $result->metadata['notification_type']);
        $this->assertSame('emails.notifications.request-serial-number', $result->metadata['template_view']);
    }

    public function test_send_returns_failure_when_mail_is_disabled(): void
    {
        config(['mail.enabled' => false]);

        [$message] = $this->makeMessage([
            'customer_email' => 'customer@example.com',
        ]);

        $result = app(EmailChannel::class)->send($message);

        $this->assertFalse($result->success);
        $this->assertSame(NotificationChannelType::Email, $result->channel);
        $this->assertNull($result->external_id);
        $this->assertSame('Email delivery is disabled. Enable MAIL_ENABLED and notifications.email.enabled.', $result->message);
        $this->assertFalse($result->retryable);
        $this->assertSame('mail_disabled', $result->metadata['status']);
    }

    public function test_send_returns_failure_when_customer_email_is_missing(): void
    {
        [$message] = $this->makeMessage([
            'customer_email' => null,
        ]);

        $result = app(EmailChannel::class)->send($message);

        $this->assertFalse($result->success);
        $this->assertSame(NotificationChannelType::Email, $result->channel);
        $this->assertNull($result->external_id);
        $this->assertSame('Customer email address is not available.', $result->message);
        $this->assertFalse($result->retryable);
        $this->assertSame('missing_customer_email', $result->metadata['status']);
    }

    public function test_send_returns_retryable_failure_on_transport_exception(): void
    {
        [$message] = $this->makeMessage([
            'customer_email' => 'customer@example.com',
        ]);

        $mailSender = Mockery::mock(NotificationMailSender::class);
        $mailSender->shouldReceive('isEnabled')->once()->andReturn(true);
        $mailSender->shouldReceive('send')
            ->once()
            ->andReturn([
                'success' => false,
                'message_id' => null,
                'error' => 'Connection could not be established with host "smtp.example.com".',
            ]);

        $channel = new EmailChannel(
            app(NotificationMailTemplateRegistry::class),
            app(NotificationCustomerContactResolver::class),
            $mailSender,
            app(\App\Services\Operations\TeamMemberActivityService::class),
        );

        $result = $channel->send($message);

        $this->assertFalse($result->success);
        $this->assertSame(NotificationChannelType::Email, $result->channel);
        $this->assertNull($result->external_id);
        $this->assertSame(
            'Unable to send email notification: Connection could not be established with host "smtp.example.com".',
            $result->message,
        );
        $this->assertTrue($result->retryable);
        $this->assertSame('transport_failure', $result->metadata['status']);
        $this->assertSame('Connection could not be established with host "smtp.example.com".', $result->metadata['error']);
    }

    public function test_send_uses_array_mailer_without_throwing_on_transport_errors(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new TransportException('SMTP transport unavailable.'));

        [$message] = $this->makeMessage([
            'customer_email' => 'customer@example.com',
        ]);

        $result = app(EmailChannel::class)->send($message);

        $this->assertFalse($result->success);
        $this->assertTrue($result->retryable);
        $this->assertSame('transport_failure', $result->metadata['status']);
        $this->assertSame('SMTP transport unavailable.', $result->metadata['error']);
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @return array{0: NotificationMessage}
     */
    private function makeMessage(array $orderOverrides = []): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create(array_merge([
            'order_id' => 'RD-EMAIL-CH-'.uniqid(),
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ], $orderOverrides));

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Email channel case',
            'description' => 'Email channel case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $message = new NotificationMessage(
            type: NotificationType::RequestSerialNumber,
            customer: $order,
            incident: $incident,
            actor: $agent,
        );

        return [$message];
    }
}
