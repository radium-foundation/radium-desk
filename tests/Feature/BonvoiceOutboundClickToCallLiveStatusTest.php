<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Events\Dashboard\OutboundClickToCallStatusUpdated;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BonvoiceOutboundClickToCallLiveStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'bonvoice.verify_signature' => false,
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.account_id' => 'acct-001',
            'bonvoice.click_to_call.enabled' => true,
            'bonvoice.click_to_call.base_url' => 'https://backend.pbx.bonvoice.com',
            'bonvoice.click_to_call.username' => 'api-user',
            'bonvoice.click_to_call.password' => 'api-pass',
            'bonvoice.click_to_call.did' => '8040837125',
        ]);
    }

    public function test_click_to_call_success_broadcasts_calling_lifecycle_status(): void
    {
        Event::fake([OutboundClickToCallStatusUpdated::class]);

        Http::fake([
            'backend.pbx.bonvoice.com/usermanagement/external-auth/*' => Http::response([
                'status' => '1',
                'data' => ['token' => 'auth-token'],
            ], 200),
            'backend.pbx.bonvoice.com/autoDialManagement/autoCallBridging/*' => Http::response([
                'responseCode' => 200,
                'responseDescription' => 'Success',
                'responseType' => 'Success',
            ], 200),
        ]);

        [$agent, $incident] = $this->createAssignedIncident();

        $response = $this->actingAs($agent)
            ->postJson(route('bonvoice.click-to-call'), [
                'incident_id' => $incident->id,
            ])
            ->assertOk();

        $eventId = (string) $response->json('event_id');

        Event::assertDispatched(OutboundClickToCallStatusUpdated::class, function (OutboundClickToCallStatusUpdated $event) use ($agent, $incident, $eventId): bool {
            return $event->recipient->is($agent)
                && ($event->call['event_id'] ?? null) === $eventId
                && ($event->call['lifecycle_status'] ?? null) === 'calling'
                && ($event->call['incident_id'] ?? null) === $incident->id
                && ($event->call['terminal'] ?? null) === false;
        });
    }

    public function test_outbound_click_to_call_webhook_broadcasts_normalized_lifecycle_status(): void
    {
        Event::fake([OutboundClickToCallStatusUpdated::class]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->postJson('/api/webhooks/bonvoice', [
            'SourceNumber' => '1800123456',
            'DestinationNumber' => '919876543210',
            'callID' => 'call-c2c-live-001',
            'Direction' => 'Outbound',
            'Leg' => 'B',
            'Status' => 'Ringing',
            'eventID' => 'EVT1234567890ABCD',
            'callBackParams' => [
                'source' => 'radium_desk',
                'user_id' => $agent->id,
                'event_id' => 'EVT1234567890ABCD',
                'order_id' => 1,
                'incident_id' => 2,
            ],
        ])->assertOk();

        Event::assertDispatched(OutboundClickToCallStatusUpdated::class, function (OutboundClickToCallStatusUpdated $event) use ($agent): bool {
            return $event->recipient->is($agent)
                && ($event->call['event_id'] ?? null) === 'EVT1234567890ABCD'
                && ($event->call['lifecycle_status'] ?? null) === 'ringing'
                && ($event->call['call_id'] ?? null) === 'call-c2c-live-001'
                && ($event->call['terminal'] ?? null) === false;
        });
    }

    public function test_non_click_to_call_outbound_webhook_does_not_broadcast_lifecycle_status(): void
    {
        Event::fake([OutboundClickToCallStatusUpdated::class]);

        $this->postJson('/api/webhooks/bonvoice', [
            'SourceNumber' => '1800123456',
            'DestinationNumber' => '919876543210',
            'callID' => 'call-out-plain-001',
            'Direction' => 'Outbound',
            'Leg' => 'A',
            'Status' => 'Ringing',
            'eventID' => 'evt-plain-001',
            'callBackParams' => null,
        ])->assertOk();

        Event::assertNotDispatched(OutboundClickToCallStatusUpdated::class);

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => 'call-out-plain-001',
            'status' => 'Ringing',
        ]);
    }

    public function test_terminal_outbound_webhook_marks_payload_as_terminal(): void
    {
        Event::fake([OutboundClickToCallStatusUpdated::class]);

        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        BonvoiceCallEvent::query()->create([
            'call_id' => 'call-c2c-live-002',
            'leg' => 'B',
            'direction' => 'Outbound',
            'status' => 'Ringing',
            'event_id' => 'EVTABCDEF012345678',
            'callback_params' => [
                'source' => 'radium_desk',
                'user_id' => $agent->id,
                'event_id' => 'EVTABCDEF012345678',
            ],
            'payload' => [],
        ]);

        $this->postJson('/api/webhooks/bonvoice', [
            'callID' => 'call-c2c-live-002',
            'Direction' => 'Outbound',
            'Leg' => 'B',
            'Status' => 'NOANSWER',
            'eventID' => 'EVTABCDEF012345678',
            'callBackParams' => [
                'source' => 'radium_desk',
                'user_id' => $agent->id,
                'event_id' => 'EVTABCDEF012345678',
            ],
        ])->assertOk();

        Event::assertDispatched(OutboundClickToCallStatusUpdated::class, function (OutboundClickToCallStatusUpdated $event): bool {
            return ($event->call['lifecycle_status'] ?? null) === 'no_answer'
                && ($event->call['terminal'] ?? null) === true;
        });
    }

    /**
     * @return array{0: User, 1: Incident}
     */
    private function createAssignedIncident(): array
    {
        $agent = User::factory()->create([
            'bonvoice_extension' => '9846098460',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-C2C-LIVE-'.uniqid(),
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Click To Call Customer',
            'customer_email' => 'c2c@example.com',
            'customer_phone' => '9123456782',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->markSynced($order->id);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Click-to-call case',
            'description' => 'Click-to-call case.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return [$agent, $incident];
    }
}
