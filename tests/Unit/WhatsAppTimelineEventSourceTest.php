<?php

namespace Tests\Unit;

use App\Enums\InteraktMessageDirection;
use App\Enums\TimelineEventType;
use App\Models\InteraktMessage;
use App\Models\Order;
use App\Models\User;
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

    public function test_maps_incoming_and_outgoing_messages(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'Asia/Kolkata'));

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

        $events = (new WhatsAppTimelineEventSource($order))->collect();

        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn ($event) => $event->type === TimelineEventType::WhatsApp));

        $incoming = $events->first(fn ($event) => $event->dedupeKey === 'whatsapp:msg-in-tl');
        $outgoing = $events->first(fn ($event) => $event->dedupeKey === 'whatsapp:msg-out-tl');

        $this->assertSame('Customer', $incoming->actor->displayName);
        $this->assertSame('Need an update', $incoming->summary);
        $this->assertSame('Template', $outgoing->actor->displayName);
        $this->assertSame('Repair Started', $outgoing->actor->subtitle);
        $this->assertSame('Read', $outgoing->statusLabel);
        $this->assertSame('read', $outgoing->statusVariant);
        $this->assertNull($outgoing->detail);
    }
}
