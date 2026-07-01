<?php

namespace Tests\Unit;

use App\Enums\InteraktDeliveryStatus;
use App\Enums\InteraktMessageDirection;
use App\Enums\TimelineEventType;
use App\Enums\WhatsAppConversationStatus;
use App\Models\InteraktMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\Interakt\InteraktDeepLinkService;
use App\Services\Interakt\WhatsAppConversationAggregator;
use App\Services\Timeline\Sources\WhatsAppTimelineEventSource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsAppConversationAggregatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_aggregates_latest_message_and_count_with_indexed_queries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 14:45:00', 'Asia/Kolkata'));

        InteraktMessage::query()->create([
            'message_id' => 'msg-in-agg',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Incoming,
            'message_type' => 'text',
            'text' => 'Need an update',
            'sent_at' => now()->subHour(),
        ]);

        InteraktMessage::query()->create([
            'message_id' => 'msg-out-agg',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Outgoing,
            'message_type' => 'template',
            'template_name' => 'Repair Started',
            'delivery_status' => InteraktDeliveryStatus::Read,
            'interakt_customer_id' => 'cust-agg-001',
            'sent_at' => now()->subMinutes(30),
            'read_at' => now()->subMinutes(20),
        ]);

        $snapshot = app(WhatsAppConversationAggregator::class)->forPhone('9876543210');

        $this->assertNotNull($snapshot);
        $this->assertSame(2, $snapshot->messagesExchangedCount);
        $this->assertSame(WhatsAppConversationStatus::WaitingForCustomer, $snapshot->conversationStatus);
        $this->assertSame('template', $snapshot->lastSender);
        $this->assertSame('Repair Started', $snapshot->lastTemplateName);
        $this->assertSame('msg-out-agg', $snapshot->lastMessageId);
        $this->assertSame('cust-agg-001', $snapshot->interaktCustomerId);
    }

    public function test_deep_link_prefers_conversation_template_when_configured(): void
    {
        config([
            'interakt.conversation_url_template' => 'https://app.interakt.ai/inbox/{customer_id}',
            'interakt.customer_profile_url_template' => 'https://app.interakt.ai/contacts?search={phone}',
        ]);

        InteraktMessage::query()->create([
            'message_id' => 'msg-deep-link',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Outgoing,
            'message_type' => 'template',
            'template_name' => 'Repair Started',
            'interakt_customer_id' => 'cust-123',
            'sent_at' => now(),
        ]);

        $snapshot = app(WhatsAppConversationAggregator::class)->forPhone('9876543210');
        $url = app(InteraktDeepLinkService::class)->conversationUrl($snapshot);

        $this->assertSame('https://app.interakt.ai/inbox/cust-123', $url);
    }

    public function test_timeline_source_emits_one_card_from_runtime_snapshot(): void
    {
        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-WA-TL',
            'serial_number' => 'SN-WA-TL',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        InteraktMessage::query()->create([
            'message_id' => 'msg-in-tl',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Incoming,
            'message_type' => 'text',
            'text' => 'Need an update',
            'sent_at' => now()->subHour(),
        ]);

        InteraktMessage::query()->create([
            'message_id' => 'msg-out-tl',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Outgoing,
            'message_type' => 'template',
            'template_name' => 'Repair Started',
            'delivery_status' => InteraktDeliveryStatus::Read,
            'interakt_customer_id' => 'cust-tl-001',
            'sent_at' => now()->subMinutes(30),
        ]);

        $events = app()->makeWith(WhatsAppTimelineEventSource::class, ['order' => $order])->collect();

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame(TimelineEventType::WhatsApp, $event->type);
        $this->assertSame('whatsapp:summary:9876543210', $event->dedupeKey);
        $this->assertSame('Waiting for Customer', $event->statusLabel);
        $this->assertSame('Open in Interakt', $event->actionLabel);
        $this->assertSame('2 exchanged', collect($event->summaryFields)->firstWhere('label', 'Messages')['value']);
        $this->assertSame('Repair Started', collect($event->summaryFields)->firstWhere('label', 'Template')['value']);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('whatsapp_communication_summaries'));
    }
}
