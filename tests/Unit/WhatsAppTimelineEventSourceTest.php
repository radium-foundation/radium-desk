<?php

namespace Tests\Unit;

use App\Enums\InteraktMessageDirection;
use App\Enums\TimelineEventType;
use App\Enums\WhatsAppConversationStatus;
use App\Models\InteraktMessage;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppCommunicationSummary;
use App\Services\Timeline\Sources\WhatsAppTimelineEventSource;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WhatsAppTimelineEventSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_maps_one_summary_card_for_multiple_messages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 14:45:00', 'Asia/Kolkata'));

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
            'delivery_status' => 'read',
            'sent_at' => now()->subMinutes(30),
            'read_at' => now()->subMinutes(20),
        ]);

        WhatsAppCommunicationSummary::query()->create([
            'customer_phone' => '9876543210',
            'interakt_customer_id' => 'cust-tl-001',
            'conversation_status' => WhatsAppConversationStatus::WaitingForCustomer,
            'messages_exchanged_count' => 2,
            'last_sender' => 'template',
            'last_template_name' => 'Repair Started',
            'last_message_id' => 'msg-out-tl',
            'last_activity_at' => now()->subMinutes(30),
            'last_communication_at' => now()->subMinutes(30),
        ]);

        $events = app()->makeWith(WhatsAppTimelineEventSource::class, ['order' => $order])->collect();

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame(TimelineEventType::WhatsApp, $event->type);
        $this->assertSame('whatsapp:summary:9876543210', $event->dedupeKey);
        $this->assertSame('Waiting for Customer', $event->statusLabel);
        $this->assertSame('Open in Interakt', $event->actionLabel);
        $this->assertStringContainsString('9876543210', (string) $event->actionUrl);
        $this->assertSame('2 exchanged', collect($event->summaryFields)->firstWhere('label', 'Messages')['value']);
        $this->assertSame('Repair Started', collect($event->summaryFields)->firstWhere('label', 'Template')['value']);
    }

    public function test_lazy_backfills_summary_when_messages_exist_without_summary_row(): void
    {
        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD-WA-BACKFILL',
            'serial_number' => 'SN-WA-BACKFILL',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        InteraktMessage::query()->create([
            'message_id' => 'msg-backfill',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Incoming,
            'message_type' => 'text',
            'text' => 'Hello',
            'sent_at' => now()->subMinutes(5),
        ]);

        $events = app()->makeWith(WhatsAppTimelineEventSource::class, ['order' => $order])->collect();

        $this->assertCount(1, $events);
        $this->assertDatabaseHas('whatsapp_communication_summaries', [
            'customer_phone' => '9876543210',
            'messages_exchanged_count' => 1,
            'conversation_status' => WhatsAppConversationStatus::WaitingForAgent->value,
        ]);
    }
}
