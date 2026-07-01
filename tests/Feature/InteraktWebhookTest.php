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
use Illuminate\Support\Carbon;
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
            'interakt.webhook_secret' => 'test-interakt-webhook-secret',
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

        $response = $this->postJson('/api/webhooks/interakt', $this->officialIncomingMessagePayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertDatabaseHas('interakt_webhook_logs', [
            'event_type' => 'message_received',
            'processing_status' => InteraktWebhookProcessorService::STATUS_PROCESSED,
        ]);

        $this->assertDatabaseHas('interakt_messages', [
            'message_id' => '60076f05-da52-4dd1-b813-36223c1eded7',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Incoming->value,
            'text' => 'When will my device be ready?',
        ]);

        $message = InteraktMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame(
            Carbon::parse('2022-06-03T05:57:57.359000')->toIso8601String(),
            $message->sent_at?->toIso8601String(),
        );

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
        $payload = $this->officialIncomingMessagePayload();

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
            callbackData: 'service-case:RD-WA-2',
        );

        $this->assertTrue($result->success);
        $this->assertSame('msg-out-001', $result->messageId);

        $this->postJson('/api/webhooks/interakt', $this->officialApiDeliveredPayload(messageId: 'msg-out-001'))->assertOk();
        $this->postJson('/api/webhooks/interakt', $this->officialApiReadPayload(messageId: 'msg-out-001'))->assertOk();

        $message = InteraktMessage::query()->where('message_id', 'msg-out-001')->first();
        $this->assertNotNull($message);
        $this->assertSame(InteraktMessageDirection::Outgoing, $message->direction);
        $this->assertSame('Repair Started', $message->template_name);
        $this->assertSame('en', $message->template_language);
        $this->assertSame('service-case:RD-WA-1', $message->callback_data);
        $this->assertSame(InteraktDeliveryStatus::Read, $message->delivery_status);
        $this->assertSame(
            Carbon::parse('2022-06-03T05:43:33.133000')->toIso8601String(),
            $message->sent_at?->toIso8601String(),
        );
        $this->assertSame(
            Carbon::parse('2022-06-03T05:43:33.848000')->toIso8601String(),
            $message->delivered_at?->toIso8601String(),
        );
        $this->assertSame(
            Carbon::parse('2022-06-03T05:43:34.257000')->toIso8601String(),
            $message->read_at?->toIso8601String(),
        );

        $timeline = app(Customer360TimelineService::class)->forOrder($order);
        $whatsappEvent = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->first(fn ($event) => $event->type === TimelineEventType::WhatsApp);

        $this->assertNotNull($whatsappEvent);
        $this->assertSame('Template', $whatsappEvent->actor->displayName);
        $this->assertSame('Repair Started', $whatsappEvent->actor->subtitle);
        $this->assertSame('Read', $whatsappEvent->statusLabel);
    }

    public function test_official_api_status_webhooks_persist_metadata(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-WA-API',
            'serial_number' => 'SN-WA-API',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'API Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $messageId = 'api-status-msg-001';

        $this->postJson('/api/webhooks/interakt', $this->officialApiSentPayload($messageId))->assertOk();
        $this->postJson('/api/webhooks/interakt', $this->officialApiDeliveredPayload($messageId))->assertOk();
        $this->postJson('/api/webhooks/interakt', $this->officialApiReadPayload($messageId))->assertOk();

        $message = InteraktMessage::query()->where('message_id', $messageId)->first();
        $this->assertNotNull($message);
        $this->assertSame('9876543210', $message->customer_phone);
        $this->assertSame(InteraktDeliveryStatus::Read, $message->delivery_status);
        $this->assertSame('Repair Started', $message->template_name);
        $this->assertSame('service-case:RD-WA-1', $message->callback_data);
    }

    public function test_official_api_failed_webhook_persists_failure_metadata(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WA-FAIL',
            'serial_number' => 'SN-WA-FAIL',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Failed Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/interakt', $this->officialApiFailedPayload())->assertOk();

        $message = InteraktMessage::query()->first();
        $this->assertNotNull($message);
        $this->assertSame(InteraktDeliveryStatus::Failed, $message->delivery_status);
        $this->assertSame('Recipient is not a valid WhatsApp user', $message->channel_failure_reason);
        $this->assertSame('1013', $message->channel_error_code);

        $timeline = app(Customer360TimelineService::class)->forOrder($order);
        $whatsappEvent = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->first(fn ($event) => $event->type === TimelineEventType::WhatsApp);

        $this->assertNotNull($whatsappEvent);
        $this->assertSame('Failed', $whatsappEvent->statusLabel);
        $this->assertStringContainsString('Recipient is not a valid WhatsApp user', (string) $whatsappEvent->detail);
        $this->assertStringContainsString('Error 1013', (string) $whatsappEvent->detail);
    }

    public function test_official_campaign_status_webhooks_reuse_delivery_status_logic(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-WA-CAMP',
            'serial_number' => 'SN-WA-CAMP',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Campaign Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $messageId = 'campaign-msg-001';

        $this->postJson('/api/webhooks/interakt', $this->officialCampaignSentPayload($messageId))->assertOk();
        $this->postJson('/api/webhooks/interakt', $this->officialCampaignDeliveredPayload($messageId))->assertOk();
        $this->postJson('/api/webhooks/interakt', $this->officialCampaignReadPayload($messageId))->assertOk();

        $message = InteraktMessage::query()->where('message_id', $messageId)->first();
        $this->assertNotNull($message);
        $this->assertSame(InteraktDeliveryStatus::Read, $message->delivery_status);
        $this->assertSame('9876543210', $message->customer_phone);
        $this->assertSame(
            Carbon::parse('2022-06-03T06:00:10.000000')->toIso8601String(),
            $message->read_at?->toIso8601String(),
        );
    }

    public function test_official_campaign_failed_webhook_persists_failure_metadata(): void
    {
        Order::query()->create([
            'order_id' => 'RD-WA-CAMP-FAIL',
            'serial_number' => 'SN-WA-CAMP-FAIL',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Campaign Failed Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => User::factory()->create()->id,
        ]);

        $this->postJson('/api/webhooks/interakt', $this->officialCampaignFailedPayload())->assertOk();

        $this->assertDatabaseHas('interakt_messages', [
            'message_id' => 'campaign-msg-failed-001',
            'delivery_status' => InteraktDeliveryStatus::Failed->value,
            'channel_failure_reason' => 'Campaign delivery failed',
            'channel_error_code' => '2001',
        ]);
    }

    public function test_legacy_country_code_phone_payload_remains_supported(): void
    {
        Order::query()->create([
            'order_id' => 'RD-WA-LEGACY',
            'serial_number' => 'SN-WA-LEGACY',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Legacy Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => User::factory()->create()->id,
        ]);

        $this->postJson('/api/webhooks/interakt', $this->legacyIncomingMessagePayload())->assertOk();

        $this->assertDatabaseHas('interakt_messages', [
            'message_id' => 'msg-legacy-in-001',
            'customer_phone' => '9876543210',
            'text' => 'Legacy payload message',
        ]);
    }

    public function test_customer_matching_uses_channel_phone_number(): void
    {
        Order::query()->create([
            'order_id' => 'RD-WA-3',
            'serial_number' => 'SN-WA-3',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Match Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => User::factory()->create()->id,
        ]);

        $this->postJson('/api/webhooks/interakt', $this->officialIncomingMessagePayload(
            messageId: 'msg-in-match',
            channelPhoneNumber: '919876543210',
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

        $this->postJson('/api/webhooks/interakt', $this->officialIncomingMessagePayload(messageId: 'msg-retry-001'))
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
        $this->postJson('/api/webhooks/interakt', $this->officialIncomingMessagePayload(messageId: 'msg-retry-002'))
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
