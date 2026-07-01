<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\InteraktMessageDirection;
use App\Enums\TimelineEventType;
use App\Enums\WhatsAppConversationStatus;
use App\Models\Incident;
use App\Models\InteraktMessage;
use App\Models\Order;
use App\Models\User;
use App\Models\WhatsAppCommunicationSummary;
use App\Services\IncidentReferenceService;
use App\Services\Timeline\Customer360TimelineService;
use App\Services\UniversalSearchService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithInteraktWebhooks;
use Tests\TestCase;

class WhatsAppCommunicationSummaryFeatureTest extends TestCase
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

    public function test_webhook_updates_summary_and_timeline_shows_single_card(): void
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

        $this->assertDatabaseHas('whatsapp_communication_summaries', [
            'customer_phone' => '9876543210',
            'messages_exchanged_count' => 2,
            'conversation_status' => WhatsAppConversationStatus::WaitingForAgent->value,
        ]);

        $timeline = app(Customer360TimelineService::class)->forOrder($order);
        $whatsappEvents = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->filter(fn ($event) => $event->type === TimelineEventType::WhatsApp);

        $this->assertCount(1, $whatsappEvents);
        $event = $whatsappEvents->first();
        $this->assertSame('whatsapp:summary:9876543210', $event->dedupeKey);
        $this->assertSame('2 exchanged', collect($event->summaryFields)->firstWhere('label', 'Messages')['value']);
        $this->assertSame('Open in Interakt', $event->actionLabel);
        $this->assertStringContainsString('52918eb3-bd00-4331-a51d-c4dcffee48d6', (string) $event->actionUrl);
    }

    public function test_drawer_renders_open_in_interakt_link(): void
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

        WhatsAppCommunicationSummary::query()->create([
            'customer_phone' => '9876543210',
            'interakt_customer_id' => 'cust-ui-001',
            'conversation_status' => WhatsAppConversationStatus::WaitingForCustomer,
            'messages_exchanged_count' => 6,
            'last_template_name' => 'Repair Started',
            'last_message_id' => 'msg-ui-001',
            'last_sender' => 'template',
            'last_activity_at' => now(),
            'last_communication_at' => now(),
        ]);

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('unified-timeline-summary-fields', false)
            ->assertSee('6 exchanged', false)
            ->assertSee('Repair Started', false)
            ->assertSee('Open in Interakt', false)
            ->assertSee('https://app.interakt.ai/inbox/cust-ui-001', false)
            ->assertDontSee('When will my device be ready?', false);
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

        WhatsAppCommunicationSummary::query()->create([
            'customer_phone' => '9000000099',
            'conversation_status' => WhatsAppConversationStatus::WaitingForCustomer,
            'messages_exchanged_count' => 3,
            'last_template_name' => 'Repair Started',
            'last_message_id' => 'msg-search-001',
            'last_activity_at' => now(),
            'last_communication_at' => now(),
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
}
