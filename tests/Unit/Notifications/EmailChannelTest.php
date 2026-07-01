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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_supports_request_serial_number_notification_type(): void
    {
        $channel = app(EmailChannel::class);

        $this->assertTrue($channel->supports(NotificationType::RequestSerialNumber));
    }

    public function test_send_returns_structured_not_implemented_result(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-EMAIL-CH-'.uniqid(),
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

        $result = app(EmailChannel::class)->send($message);

        $this->assertFalse($result->success);
        $this->assertSame(NotificationChannelType::Email, $result->channel);
        $this->assertNull($result->external_id);
        $this->assertSame('Email notifications are not implemented yet.', $result->message);
        $this->assertFalse($result->retryable);
        $this->assertSame('not_implemented', $result->metadata['status']);
        $this->assertSame(NotificationType::RequestSerialNumber->value, $result->metadata['notification_type']);
    }
}
