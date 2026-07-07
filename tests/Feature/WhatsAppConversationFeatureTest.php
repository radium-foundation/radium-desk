<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\InteraktMessageDirection;
use App\Enums\TimelineEventType;
use App\Models\Incident;
use App\Models\InteraktMessage;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\Timeline\Customer360TimelineService;
use App\Services\UniversalSearchService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithInteraktWebhooks;
use Tests\TestCase;

class WhatsAppConversationFeatureTest extends TestCase
{
    use InteractsWithInteraktWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'interakt.verify_signature' => false,
            'interakt.conversation_url_template' => 'https://app.interakt.ai/inbox/{customer_id}',
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_webhook_messages_produce_single_runtime_timeline_card(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WA-SUM',
            'serial_number' => 'SN-WA-SUM',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/interakt', $this->officialIncomingMessagePayload())->assertOk();
        $this->postJson('/api/webhooks/interakt', $this->officialIncomingMessagePayload(
            messageId: 'msg-in-second',
            channelPhoneNumber: '919876543210',
        ))->assertOk();

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('whatsapp_communication_summaries'));
        $this->assertSame(2, InteraktMessage::query()->where('customer_phone', '9876543210')->count());

        $timeline = app(Customer360TimelineService::class)->forOrder($order);
        $whatsappEvents = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->filter(fn ($event) => $event->type === TimelineEventType::WhatsApp);

        $this->assertCount(1, $whatsappEvents);
        $event = $whatsappEvents->first();
        $this->assertSame('whatsapp:summary:9876543210', $event->dedupeKey);
        $this->assertSame('2 exchanged', collect($event->summaryFields)->firstWhere('label', 'Messages')['value']);
        $this->assertSame('Waiting for Agent', $event->statusLabel);
        $this->assertSame('Open in Interakt', $event->actionLabel);
        $this->assertStringContainsString('52918eb3-bd00-4331-a51d-c4dcffee48d6', (string) $event->actionUrl);
    }

    public function test_drawer_renders_open_in_interakt_link_without_message_text(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WA-UI',
            'serial_number' => 'SN-WA-UI',
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
            'title' => 'WhatsApp summary case',
            'description' => 'WhatsApp summary case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        InteraktMessage::query()->create([
            'message_id' => 'msg-ui-001',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Outgoing,
            'message_type' => 'template',
            'template_name' => 'Repair Started',
            'interakt_customer_id' => 'cust-ui-001',
            'sent_at' => now(),
        ]);

        for ($index = 2; $index <= 6; $index++) {
            InteraktMessage::query()->create([
                'message_id' => 'msg-ui-00'.$index,
                'customer_phone' => '9876543210',
                'direction' => InteraktMessageDirection::Incoming,
                'message_type' => 'text',
                'text' => 'Casual greeting '.$index,
                'sent_at' => now()->subMinutes($index),
            ]);
        }

        $timelineHtml = (string) $this->actingAs($agent)
            ->getJson(route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1&offset=0')
            ->assertOk()
            ->json('html');

        $this->assertStringContainsString('unified-timeline-summary-fields', $timelineHtml);
        $this->assertStringContainsString('6 exchanged', $timelineHtml);
        $this->assertStringContainsString('Repair Started', $timelineHtml);
        $this->assertStringContainsString('Open in Interakt', $timelineHtml);
        $this->assertStringContainsString('https://app.interakt.ai/inbox/cust-ui-001', $timelineHtml);
        $this->assertStringNotContainsString('Casual greeting', $timelineHtml);
        $this->assertStringNotContainsString('When will my device be ready?', $timelineHtml);
    }

    public function test_search_finds_incident_by_whatsapp_template_name(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-WA-SEARCH',
            'serial_number' => 'SN-WA-SEARCH',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9000000099',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Searchable WhatsApp case',
            'description' => 'Searchable WhatsApp case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        InteraktMessage::query()->create([
            'message_id' => 'msg-search-001',
            'customer_phone' => '9000000099',
            'direction' => InteraktMessageDirection::Outgoing,
            'message_type' => 'template',
            'template_name' => 'Repair Started',
            'text' => 'Casual hello should not be indexed',
            'sent_at' => now(),
        ]);

        $results = app(UniversalSearchService::class)->search($agent, 'Repair Started');

        $this->assertTrue($results->contains(fn ($row) => $row->is($incident)));
    }

    public function test_aggregator_uses_count_and_limit_queries(): void
    {
        InteraktMessage::query()->create([
            'message_id' => 'msg-perf-1',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Incoming,
            'message_type' => 'text',
            'text' => 'Hello',
            'sent_at' => now()->subMinutes(10),
        ]);

        InteraktMessage::query()->create([
            'message_id' => 'msg-perf-2',
            'customer_phone' => '9876543210',
            'direction' => InteraktMessageDirection::Outgoing,
            'message_type' => 'template',
            'template_name' => 'Repair Started',
            'sent_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::enableQueryLog();

        app(\App\Services\Interakt\WhatsAppConversationAggregator::class)->forPhone('9876543210');

        $queries = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query) => str_contains($query, 'interakt_messages'));

        $this->assertSame(2, $queries->count());
        $this->assertTrue($queries->contains(fn (string $query) => str_contains($query, 'count')));
        $this->assertTrue($queries->contains(fn (string $query) => str_contains($query, 'limit 1') || str_contains($query, 'limit 1 offset 0')));

        \Illuminate\Support\Facades\DB::disableQueryLog();
    }
}
