<?php

namespace Tests\Feature;

use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use App\Enums\OutboxEventStatus;
use App\Enums\TimelineEventType;
use App\Models\InteraktMessage;
use App\Models\InteraktWebhookLog;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Interakt\InteraktMessageStore;
use App\Services\Interakt\InteraktWebhookOutboxWriter;
use App\Services\Interakt\InteraktWebhookProcessorService;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\InteractsWithInteraktWebhooks;
use Tests\TestCase;

class InteraktWebhookTest extends TestCase
{
    use InteractsWithInteraktWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.verify_signature' => false,
            'interakt.api_key' => 'test-interakt-key',
            'interakt.base_url' => 'https://api.interakt.ai',
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_incoming_webhook_stores_message_and_timeline_event(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WA-1',
            'serial_number' => 'SN-WA-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'WhatsApp Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $response = $this->postJson('/api/webhooks/interakt', $this->incomingMessagePayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertDatabaseHas('interakt_webhook_logs', [
            'event_type' => 'message_received',
            'processing_status' => InteraktWebhookProcessorService::STATUS_PROCESSED,
        ]);

        $this->assertDatabaseHas('interakt_messages', [
            'message_id' => 'msg-in-001',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Incoming->value,
            'text' => 'When will my device be ready?',
        ]);

        $timeline = app(Customer360TimelineService::class)->forOrder($order);
        $whatsappEvents = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->filter(fn ($event) => $event->type === TimelineEventType::WhatsApp);

        $this->assertCount(1, $whatsappEvents);
        $this->assertSame('WhatsApp', $whatsappEvents->first()->title);
        $this->assertSame('Customer', $whatsappEvents->first()->actor->displayName);
        $this->assertSame('When will my device be ready?', $whatsappEvents->first()->summary);
    }

    public function test_duplicate_webhook_does_not_create_duplicate_message(): void
    {
        $payload = $this->incomingMessagePayload();

        $this->postJson('/api/webhooks/interakt', $payload)->assertOk();
        $this->postJson('/api/webhooks/interakt', $payload)->assertOk();

        $this->assertSame(2, InteraktWebhookLog::query()->count());
        $this->assertSame(1, InteraktMessage::query()->count());
        $this->assertSame(2, OutboxEvent::query()->where('event_type', InteraktWebhookOutboxWriter::EVENT_TYPE)->count());
    }

    public function test_outgoing_template_send_and_status_webhooks_update_timeline(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WA-2',
            'serial_number' => 'SN-WA-2',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Template Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['id' => 'msg-out-001'], 200),
        ]);

        $result = app(\App\Services\Interakt\InteraktService::class)->sendTemplateMessage(
            countryCode: '+91',
            phoneNumber: '9876543210',
            template: [
                'name' => 'Repair Started',
                'languageCode' => 'en',
            ],
        );

        $this->assertTrue($result->success);
        $this->assertSame('msg-out-001', $result->messageId);

        $this->postJson('/api/webhooks/interakt', $this->templateDeliveredPayload())->assertOk();
        $this->postJson('/api/webhooks/interakt', $this->templateReadPayload())->assertOk();

        $message = InteraktMessage::query()->where('message_id', 'msg-out-001')->first();
        $this->assertNotNull($message);
        $this->assertSame(InteraktMessageDirection::Outgoing, $message->direction);
        $this->assertSame('Repair Started', $message->template_name);
        $this->assertSame(InteraktDeliveryStatus::Read, $message->delivery_status);

        $timeline = app(Customer360TimelineService::class)->forOrder($order);
        $whatsappEvent = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->first(fn ($event) => $event->type === TimelineEventType::WhatsApp);

        $this->assertNotNull($whatsappEvent);
        $this->assertSame('Template', $whatsappEvent->actor->displayName);
        $this->assertSame('Repair Started', $whatsappEvent->actor->subtitle);
        $this->assertSame('Read', $whatsappEvent->detail);
    }

    public function test_customer_matching_uses_existing_order_phone_format(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-WA-3',
            'serial_number' => 'SN-WA-3',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Match Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/interakt', $this->incomingMessagePayload(
            messageId: 'msg-in-match',
            phoneNumber: '9876543210',
            countryCode: '+91',
        ))->assertOk();

        $this->assertDatabaseHas('interakt_messages', [
            'message_id' => 'msg-in-match',
            'customer_phone' => '9876543210',
        ]);
    }

    public function test_failed_api_response_does_not_store_message(): void
    {
        Http::fake([
            'api.interakt.ai/v1/public/message/*' => Http::response(['message' => 'Invalid template'], 400),
        ]);

        $result = app(\App\Services\Interakt\InteraktService::class)->sendTemplateMessage(
            countryCode: '+91',
            phoneNumber: '9876543210',
            template: [
                'name' => 'Missing Template',
                'languageCode' => 'en',
            ],
        );

        $this->assertFalse($result->success);
        $this->assertSame('Invalid template', $result->errorMessage);
        $this->assertSame(0, InteraktMessage::query()->count());
    }

    public function test_retry_logic_marks_outbox_event_for_retry_on_processor_failure(): void
    {
        $this->mock(InteraktMessageStore::class, function ($mock): void {
            $mock->shouldReceive('upsertFromWebhook')
                ->once()
                ->andThrow(new RuntimeException('processor failed'));
        });

        $this->app->forgetInstance(\App\Services\Interakt\InteraktWebhookProcessorService::class);
        $this->app->forgetInstance(OutboxProcessorService::class);

        $this->postJson('/api/webhooks/interakt', $this->incomingMessagePayload(messageId: 'msg-retry-001'))
            ->assertOk();

        $event = OutboxEvent::query()->first();
        $this->assertNotNull($event);
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertSame('processor failed', $event->last_error);

        $this->assertDatabaseHas('interakt_webhook_logs', [
            'processing_status' => InteraktWebhookProcessorService::STATUS_FAILED,
            'processing_error' => 'processor failed',
        ]);
    }

    public function test_outbox_retry_reprocesses_failed_webhook(): void
    {
        $this->postJson('/api/webhooks/interakt', $this->incomingMessagePayload(messageId: 'msg-retry-002'))
            ->assertOk();

        InteraktWebhookLog::query()->update([
            'processing_status' => InteraktWebhookLog::STATUS_FAILED,
            'processing_error' => 'simulated failure',
        ]);

        InteraktMessage::query()->where('message_id', 'msg-retry-002')->delete();

        OutboxEvent::query()->update([
            'status' => OutboxEventStatus::Pending->value,
            'attempts' => 1,
            'available_at' => now()->subMinute(),
            'last_error' => 'simulated failure',
        ]);

        app(OutboxProcessorService::class)->process();

        $this->assertDatabaseHas('interakt_messages', [
            'message_id' => 'msg-retry-002',
        ]);

        $this->assertDatabaseHas('interakt_webhook_logs', [
            'processing_status' => InteraktWebhookProcessorService::STATUS_PROCESSED,
        ]);
    }
}
