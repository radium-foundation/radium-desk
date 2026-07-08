<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\TimelineEventType;
use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceWebhookOutboxWriter;
use App\Services\Bonvoice\BonvoiceWebhookProcessorService;
use App\Services\IncidentReferenceService;
use App\Services\Timeline\Customer360TimelineService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BonvoiceWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bonvoice.verify_signature' => false,
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.account_id' => 'acct-001',
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_incoming_webhook_stores_call_event_and_timeline_card(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-BV-1',
            'serial_number' => 'SN-BV-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'IVR Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $response = $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            status: 'Ringing',
            agentStatus: 'Idle',
        ));

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertDatabaseHas('bonvoice_webhook_logs', [
            'processing_status' => BonvoiceWebhookProcessorService::STATUS_PROCESSED,
        ]);

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => 'call-001',
            'leg' => 'A',
            'customer_phone' => '9876543210',
            'source_number' => '9876543210',
            'destination_number' => '1800123456',
            'direction' => 'Inbound',
            'status' => 'Ringing',
            'agent_status' => 'Idle',
        ]);

        $callEvent = BonvoiceCallEvent::query()->first();
        $this->assertNotNull($callEvent);
        $this->assertIsArray($callEvent->payload);
        $this->assertSame('evt-001', $callEvent->event_id);

        $timeline = app(Customer360TimelineService::class)->forOrder($order);
        $ivrEvents = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->filter(fn ($event) => $event->type === TimelineEventType::IvrCall);

        $this->assertCount(1, $ivrEvents);
        $event = $ivrEvents->first();
        $this->assertSame('Inbound Call', $event->title);
        $this->assertSame('bonvoice:call:call-001', $event->dedupeKey);
        $this->assertSame('Ringing', $event->statusLabel);
        $this->assertSame('Ringing', collect($event->summaryFields)->firstWhere('label', 'Status')['value']);
    }

    public function test_lifecycle_updates_upsert_same_call_without_duplicate_timeline_cards(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-BV-2',
            'serial_number' => 'SN-BV-2',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'IVR Update Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            status: 'Ringing',
            eventId: 'evt-001',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            status: 'Answered',
            agentStatus: 'On Call',
            eventId: 'evt-002',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            status: 'Completed',
            agentStatus: 'Available',
            eventId: 'evt-003',
        ))->assertOk();

        $this->assertSame(3, BonvoiceWebhookLog::query()->count());
        $this->assertSame(3, OutboxEvent::query()->where('event_type', BonvoiceWebhookOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(1, BonvoiceCallEvent::query()->count());

        $callEvent = BonvoiceCallEvent::query()->first();
        $this->assertNotNull($callEvent);
        $this->assertSame('Completed', $callEvent->status);
        $this->assertSame('Available', $callEvent->agent_status);
        $this->assertSame('evt-003', $callEvent->event_id);

        $timeline = app(Customer360TimelineService::class)->forOrder($order);
        $ivrEvents = $timeline->groups
            ->flatMap(fn ($group) => $group->events)
            ->filter(fn ($event) => $event->type === TimelineEventType::IvrCall);

        $this->assertCount(1, $ivrEvents);
        $this->assertSame('Completed', $ivrEvents->first()->statusLabel);
    }

    public function test_outbound_call_resolves_customer_from_destination_number(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-BV-3',
            'serial_number' => 'SN-BV-3',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Outbound Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->outboundCallPayload())->assertOk();

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => 'call-out-001',
            'leg' => 'A',
            'customer_phone' => '9876543210',
            'destination_number' => '919876543210',
            'direction' => 'Outbound',
            'status' => 'Answered',
        ]);
    }

    public function test_real_bonvoice_production_payload_without_leg_creates_call_event(): void
    {
        User::factory()->create();

        $payload = [
            'SourceNumber' => '9770763522',
            'DestinationNumber' => '08448423017',
            'StartTime' => '2026-07-08 14:32:23',
            'EndTime' => '2026-07-08 14:37:06',
            'CallDuration' => '195',
            'Status' => 'ANSWERED',
            'Direction' => 'Inbound',
            'ResourceURL' => 'https://recordings.bonvoice.example/call-1783501343.mp3',
            'callID' => '1783501343999999',
            'callType' => '2',
        ];

        $this->postJson('/api/webhooks/bonvoice', $payload)->assertOk();

        $this->assertDatabaseHas('bonvoice_webhook_logs', [
            'processing_status' => BonvoiceWebhookProcessorService::STATUS_PROCESSED,
        ]);

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => '1783501343999999',
            'leg' => 'call',
            'source_number' => '9770763522',
            'destination_number' => '08448423017',
            'direction' => 'Inbound',
            'status' => 'ANSWERED',
            'call_type' => '2',
            'recording_url' => 'https://recordings.bonvoice.example/call-1783501343.mp3',
        ]);

        $callEvent = BonvoiceCallEvent::query()->first();
        $this->assertNotNull($callEvent);
        $this->assertSame('2026-07-08 14:37:06', $callEvent->payload['EndTime']);
        $this->assertSame('195', $callEvent->payload['CallDuration']);
        $this->assertSame(
            '2026-07-08 14:32:23',
            $callEvent->started_at->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
    }

    public function test_completed_call_webhook_with_recording_url_updates_existing_call(): void
    {
        User::factory()->create();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-rec-001',
            leg: 'a',
            status: 'ringing',
            direction: 'inbound',
            callType: 'Inbound',
            eventId: 'evt-rec-001',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-rec-001',
            leg: 'a',
            status: 'completed',
            agentStatus: 'completed',
            direction: 'inbound',
            callType: 'Inbound',
            eventId: 'evt-rec-002',
            recordingUrl: 'https://recordings.bonvoice.example/call-rec-001.mp3',
        ))->assertOk();

        $this->assertSame(2, BonvoiceWebhookLog::query()->count());
        $this->assertSame(1, BonvoiceCallEvent::query()->count());

        $callEvent = BonvoiceCallEvent::query()->first();
        $this->assertNotNull($callEvent);
        $this->assertSame('call-rec-001', $callEvent->call_id);
        $this->assertSame('a', $callEvent->leg);
        $this->assertSame('completed', $callEvent->status);
        $this->assertSame('completed', $callEvent->agent_status);
        $this->assertSame('https://recordings.bonvoice.example/call-rec-001.mp3', $callEvent->recording_url);
        $this->assertSame('evt-rec-002', $callEvent->event_id);
    }

    public function test_ist_start_time_parses_correctly(): void
    {
        User::factory()->create();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-ist-001',
            startTime: '2026-07-08 10:15:00',
            direction: 'inbound',
            callType: 'Inbound',
            status: 'dial',
            eventId: 'evt-ist-001',
        ))->assertOk();

        $callEvent = BonvoiceCallEvent::query()->where('call_id', 'call-ist-001')->first();
        $this->assertNotNull($callEvent);
        $this->assertNotNull($callEvent->started_at);
        $this->assertSame(
            '2026-07-08 10:15:00',
            $callEvent->started_at->timezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
        );
    }

    public function test_customer_360_timeline_tab_renders_ivr_call_event(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-BV-4',
            'serial_number' => 'SN-BV-4',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Timeline Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'IVR timeline test',
            'description' => 'BonVoice timeline visibility test.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-timeline-001',
            status: 'Answered',
        ))->assertOk();

        $response = $this->actingAs($agent)->getJson(
            route('dashboard.service-cases.customer-360.timeline', $incident).'?tab=1&offset=0',
        );

        $response->assertOk();
        $html = (string) $response->json('html');
        $this->assertStringContainsString('Inbound Call', $html);
        $this->assertStringContainsString('Answered', $html);
        $this->assertStringContainsString('bi-telephone', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundCallPayload(
        string $callId = 'call-001',
        string $leg = 'A',
        string $status = 'Ringing',
        ?string $agentStatus = null,
        string $eventId = 'evt-001',
        ?string $startTime = null,
        ?string $recordingUrl = null,
        string $direction = 'Inbound',
        string $callType = 'Support',
    ): array {
        $payload = [
            'SourceNumber' => '9876543210',
            'DestinationNumber' => '1800123456',
            'DisplayNumber' => '1800123456',
            'StartTime' => $startTime ?? Carbon::parse('2026-07-08T10:15:00')->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => $callType,
            'AccountID' => 'acct-001',
            'callID' => $callId,
            'Direction' => $direction,
            'Leg' => $leg,
            'Status' => $status,
            'AgentStatus' => $agentStatus,
            'eventID' => $eventId,
            'callBackParentID' => null,
            'callBackParams' => null,
        ];

        if ($recordingUrl !== null) {
            $payload['recording_url'] = $recordingUrl;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function outboundCallPayload(): array
    {
        return [
            'SourceNumber' => '1800123456',
            'DestinationNumber' => '919876543210',
            'DisplayNumber' => '1800123456',
            'StartTime' => Carbon::parse('2026-07-08T11:00:00')->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => 'Follow Up',
            'AccountID' => 'acct-001',
            'callID' => 'call-out-001',
            'Direction' => 'Outbound',
            'Leg' => 'A',
            'Status' => 'Answered',
            'AgentStatus' => 'On Call',
            'eventID' => 'evt-out-001',
            'callBackParentID' => null,
            'callBackParams' => null,
        ];
    }
}
