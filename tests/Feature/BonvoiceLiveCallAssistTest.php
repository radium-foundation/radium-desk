<?php

namespace Tests\Feature;

use App\Enums\BonvoiceCallAlertType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Events\Dashboard\NotificationCreated;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\BonvoiceCallAlert;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Notifications\IncomingCallAssistNotification;
use App\Services\Bonvoice\BonvoiceAgentResolver;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BonvoiceLiveCallAssistTest extends TestCase
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

    public function test_existing_customer_creates_agent_alert_and_notification(): void
    {
        Notification::fake();
        Queue::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3444319',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Known Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open case',
            'description' => 'Live call assist test.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-live-known-001',
            status: 'Ringing',
        ))->assertOk();

        $this->assertDatabaseHas('bonvoice_call_alerts', [
            'call_id' => 'call-live-known-001',
            'user_id' => $agent->id,
            'alert_type' => BonvoiceCallAlertType::CustomerFound->value,
            'customer_phone' => '9876543210',
            'order_id' => $order->id,
            'incident_id' => $incident->id,
        ]);

        Notification::assertSentTo($agent, IncomingCallAssistNotification::class, function (IncomingCallAssistNotification $notification) use ($agent, $incident): bool {
            $payload = $notification->toArray($agent);

            return $payload['title'] === '📞 Incoming Call'
                && $payload['message'] === 'Customer Found: RD3444319'
                && $payload['url'] === route('incidents.show', $incident);
        });

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });
    }

    public function test_unknown_caller_creates_alert_and_notification(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-live-unknown-001',
            sourceNumber: '9111222333',
            status: 'Ringing',
        ))->assertOk();

        $this->assertDatabaseHas('bonvoice_call_alerts', [
            'call_id' => 'call-live-unknown-001',
            'user_id' => $agent->id,
            'alert_type' => BonvoiceCallAlertType::UnknownCaller->value,
            'customer_phone' => '9111222333',
            'order_id' => null,
            'incident_id' => null,
        ]);

        Notification::assertSentTo($agent, IncomingCallAssistNotification::class, function (IncomingCallAssistNotification $notification) use ($agent): bool {
            $payload = $notification->toArray($agent);

            return $payload['title'] === '📞 New Caller'
                && $payload['message'] === "Mobile: 9111222333\nNo existing record"
                && $payload['url'] === route('dashboard');
        });
    }

    public function test_duplicate_lifecycle_event_does_not_create_second_alert(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-LIVE-DUP',
            'serial_number' => 'SN-LIVE-DUP',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-live-dup-001',
            status: 'Ringing',
            eventId: 'evt-dup-1',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-live-dup-001',
            status: 'COMPLETED',
            eventId: 'evt-dup-2',
        ))->assertOk();

        $this->assertSame(1, BonvoiceCallAlert::query()->where('call_id', 'call-live-dup-001')->count());
        Notification::assertSentToTimes($agent, IncomingCallAssistNotification::class, 1);
    }

    public function test_no_agent_match_creates_no_alert(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '9999999999',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-LIVE-NO-AGENT',
            'serial_number' => 'SN-LIVE-NO-AGENT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-live-no-agent-001',
            destinationNumber: '1800123456',
            status: 'Ringing',
        ))->assertOk();

        $this->assertDatabaseMissing('bonvoice_call_alerts', [
            'call_id' => 'call-live-no-agent-001',
        ]);

        Notification::assertNothingSent();
    }

    public function test_outbound_call_creates_no_alert(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->postJson('/api/webhooks/bonvoice', [
            'SourceNumber' => '1800123456',
            'DestinationNumber' => '919876543210',
            'DisplayNumber' => '1800123456',
            'StartTime' => Carbon::parse('2026-07-08T11:00:00')->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => 'Follow Up',
            'AccountID' => 'acct-001',
            'callID' => 'call-live-outbound-001',
            'Direction' => 'Outbound',
            'Leg' => 'A',
            'Status' => 'ANSWERED',
            'eventID' => 'evt-out-live-001',
            'callBackParentID' => null,
            'callBackParams' => null,
        ])->assertOk();

        $this->assertDatabaseMissing('bonvoice_call_alerts', [
            'call_id' => 'call-live-outbound-001',
        ]);

        Notification::assertNothingSent();
    }

    public function test_notification_failure_does_not_fail_webhook_processing(): void
    {
        Log::spy();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-NOTIFY-FAIL',
            'serial_number' => 'SN-NOTIFY-FAIL',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $throwingAgent = Mockery::mock($agent)->makePartial();
        $throwingAgent->shouldReceive('notify')
            ->once()
            ->andThrow(new RuntimeException('Notification channel failed'));

        $this->mock(BonvoiceAgentResolver::class, function ($mock) use ($throwingAgent): void {
            $mock->shouldReceive('resolveUserForCall')
                ->once()
                ->andReturn($throwingAgent);
        });

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-live-notify-fail-001',
            status: 'Ringing',
        ))->assertOk();

        $this->assertDatabaseHas('bonvoice_webhook_logs', [
            'processing_status' => 'processed',
        ]);

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => 'call-live-notify-fail-001',
        ]);

        $this->assertDatabaseHas('bonvoice_call_alerts', [
            'call_id' => 'call-live-notify-fail-001',
            'user_id' => $agent->id,
        ]);

        Log::shouldHaveReceived('error')
            ->with('[BonVoice Live Call Assist] Notification failed', Mockery::on(function (array $context): bool {
                return ($context['call_id'] ?? null) === 'call-live-notify-fail-001';
            }))
            ->once();
    }

    public function test_customer_resolver_prefers_active_incident_over_latest_order(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $olderOrder = Order::query()->create([
            'order_id' => 'RD-OLDER',
            'serial_number' => 'SN-OLDER',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $newerOrder = Order::query()->create([
            'order_id' => 'RD-NEWER',
            'serial_number' => 'SN-NEWER',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $activeIncident = Incident::query()->create([
            'order_id' => $olderOrder->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Older active case',
            'description' => 'Prefer this incident.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $newerOrder->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Closed newer case',
            'description' => 'Closed case on newer order.',
            'status' => IncidentStatus::Closed,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-live-prefer-active-001',
            status: 'Ringing',
        ))->assertOk();

        $this->assertDatabaseHas('bonvoice_call_alerts', [
            'call_id' => 'call-live-prefer-active-001',
            'order_id' => $olderOrder->id,
            'incident_id' => $activeIncident->id,
        ]);

        Notification::assertSentTo($agent, IncomingCallAssistNotification::class, function (IncomingCallAssistNotification $notification) use ($agent): bool {
            return $notification->toArray($agent)['message'] === 'Customer Found: RD-OLDER';
        });
    }

    public function test_answered_inbound_webhook_creates_one_live_assist_alert(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-LIVE-ANSWERED',
            'serial_number' => 'SN-LIVE-ANSWERED',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->productionInboundCallPayload(
            callId: 'call-live-answered-001',
            status: 'ANSWERED',
        ))->assertOk();

        $this->assertSame(1, BonvoiceCallAlert::query()->where('call_id', 'call-live-answered-001')->count());
        Notification::assertSentToTimes($agent, IncomingCallAssistNotification::class, 1);
    }

    public function test_duplicate_answered_same_call_id_does_not_duplicate_alert(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-LIVE-ANSWERED-DUP',
            'serial_number' => 'SN-LIVE-ANSWERED-DUP',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->productionInboundCallPayload(
            callId: 'call-live-answered-dup-001',
            status: 'ANSWERED',
            eventId: 'evt-answered-dup-1',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->productionInboundCallPayload(
            callId: 'call-live-answered-dup-001',
            status: 'ANSWERED',
            eventId: 'evt-answered-dup-2',
        ))->assertOk();

        $this->assertSame(1, BonvoiceCallAlert::query()->where('call_id', 'call-live-answered-dup-001')->count());
        Notification::assertSentToTimes($agent, IncomingCallAssistNotification::class, 1);
    }

    public function test_noanswer_does_not_create_live_assist_alert(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-LIVE-NOANSWER',
            'serial_number' => 'SN-LIVE-NOANSWER',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->productionInboundCallPayload(
            callId: 'call-live-noanswer-001',
            status: 'NOANSWER',
        ))->assertOk();

        $this->assertDatabaseMissing('bonvoice_call_alerts', [
            'call_id' => 'call-live-noanswer-001',
        ]);

        Notification::assertNothingSent();
    }

    public function test_feature_flag_disabled_notification_has_no_interaction_payload(): void
    {
        Notification::fake();
        config(['bonvoice.auto_open_customer360' => false]);

        $agent = $this->createAgentWithKnownCustomer();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-flag-off-001',
            status: 'Ringing',
        ))->assertOk();

        Notification::assertSentTo($agent, IncomingCallAssistNotification::class, function (IncomingCallAssistNotification $notification) use ($agent): bool {
            $payload = $notification->toArray($agent);

            return ! array_key_exists('interaction', $payload);
        });
    }

    public function test_feature_flag_enabled_ringing_notification_includes_interaction_payload(): void
    {
        Notification::fake();
        config(['bonvoice.auto_open_customer360' => true]);

        $agent = $this->createAgentWithKnownCustomer();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-flag-on-ring-001',
            status: 'Ringing',
        ))->assertOk();

        Notification::assertSentTo($agent, IncomingCallAssistNotification::class, function (IncomingCallAssistNotification $notification) use ($agent): bool {
            $payload = $notification->toArray($agent);

            return ($payload['interaction']['channel'] ?? null) === 'phone'
                && ($payload['interaction']['direction'] ?? null) === 'inbound'
                && ($payload['interaction']['status'] ?? null) === 'ringing'
                && ($payload['interaction']['call_id'] ?? null) === 'call-flag-on-ring-001'
                && ($payload['interaction']['customer_phone'] ?? null) === '9876543210'
                && ($payload['interaction']['customer_name'] ?? null) === 'Known Customer'
                && ($payload['interaction']['incident_id'] ?? null) !== null;
        });
    }

    public function test_feature_flag_enabled_answered_call_broadcasts_auto_open_interaction(): void
    {
        Notification::fake();
        Event::fake([NotificationCreated::class]);
        config(['bonvoice.auto_open_customer360' => true]);

        $agent = $this->createAgentWithKnownCustomer();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-flag-on-answer-001',
            status: 'Ringing',
            eventId: 'evt-flag-on-answer-ring',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-flag-on-answer-001',
            status: 'ANSWERED',
            eventId: 'evt-flag-on-answer-answered',
        ))->assertOk();

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $event) use ($agent): bool {
            return $event->recipient->is($agent)
                && ($event->interaction['status'] ?? null) === 'answered'
                && ($event->interaction['channel'] ?? null) === 'phone'
                && ($event->interaction['direction'] ?? null) === 'inbound'
                && ($event->interaction['call_id'] ?? null) === 'call-flag-on-answer-001'
                && ($event->interaction['incident_id'] ?? null) !== null
                && $event->bellHtml === '';
        });
    }

    public function test_feature_flag_disabled_answered_call_does_not_broadcast_auto_open_interaction(): void
    {
        Notification::fake();
        Event::fake([NotificationCreated::class]);
        config(['bonvoice.auto_open_customer360' => false]);

        $agent = $this->createAgentWithKnownCustomer();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-flag-off-answer-001',
            status: 'Ringing',
            eventId: 'evt-flag-off-answer-ring',
        ))->assertOk();

        Event::fake([NotificationCreated::class]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-flag-off-answer-001',
            status: 'ANSWERED',
            eventId: 'evt-flag-off-answer-answered',
        ))->assertOk();

        Event::assertNotDispatched(NotificationCreated::class, function (NotificationCreated $event): bool {
            return ($event->interaction['status'] ?? null) === 'answered'
                && $event->bellHtml === '';
        });
    }

    public function test_duplicate_answered_webhook_broadcasts_auto_open_only_once(): void
    {
        Notification::fake();
        Event::fake([NotificationCreated::class]);
        config(['bonvoice.auto_open_customer360' => true]);

        $this->createAgentWithKnownCustomer();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-dup-answered-001',
            status: 'Ringing',
            eventId: 'evt-dup-answered-ring',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-dup-answered-001',
            status: 'ANSWERED',
            eventId: 'evt-dup-answered-answered-1',
        ))->assertOk();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-dup-answered-001',
            status: 'ANSWERED',
            eventId: 'evt-dup-answered-answered-2',
        ))->assertOk();

        $answeredAutoOpenBroadcasts = collect(Event::dispatched(NotificationCreated::class))
            ->filter(function (array $payload): bool {
                $event = $payload[0] ?? null;

                return $event instanceof NotificationCreated
                    && ($event->interaction['status'] ?? null) === 'answered'
                    && $event->bellHtml === '';
            });

        $this->assertCount(1, $answeredAutoOpenBroadcasts);
    }

    public function test_unknown_customer_answered_call_does_not_broadcast_auto_open_interaction(): void
    {
        Notification::fake();
        Event::fake([NotificationCreated::class]);
        config(['bonvoice.auto_open_customer360' => true]);

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-unknown-answer-001',
            sourceNumber: '9111222333',
            status: 'Ringing',
            eventId: 'evt-unknown-answer-ring',
        ))->assertOk();

        Event::fake([NotificationCreated::class]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-unknown-answer-001',
            sourceNumber: '9111222333',
            status: 'ANSWERED',
            eventId: 'evt-unknown-answer-answered',
        ))->assertOk();

        Event::assertNotDispatched(NotificationCreated::class, function (NotificationCreated $event): bool {
            return ($event->interaction['status'] ?? null) === 'answered'
                && $event->bellHtml === '';
        });
    }

    public function test_known_customer_without_incident_does_not_broadcast_auto_open_interaction(): void
    {
        Notification::fake();
        Event::fake([NotificationCreated::class]);
        config(['bonvoice.auto_open_customer360' => true]);

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-NO-INCIDENT',
            'serial_number' => 'SN-NO-INCIDENT',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'No Incident Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-no-incident-001',
            status: 'Ringing',
            eventId: 'evt-no-incident-ring',
        ))->assertOk();

        $this->assertDatabaseHas('bonvoice_call_alerts', [
            'call_id' => 'call-no-incident-001',
            'incident_id' => null,
        ]);

        Event::fake([NotificationCreated::class]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-no-incident-001',
            status: 'ANSWERED',
            eventId: 'evt-no-incident-answered',
        ))->assertOk();

        Event::assertNotDispatched(NotificationCreated::class, function (NotificationCreated $event): bool {
            return ($event->interaction['status'] ?? null) === 'answered'
                && $event->bellHtml === '';
        });
    }

    private function createAgentWithKnownCustomer(): User
    {
        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3444319',
            'serial_number' => null,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Known Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open case',
            'description' => 'Live call assist test.',
            'status' => IncidentStatus::Open,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
            'assigned_to_user_id' => $agent->id,
        ]);

        return $agent;
    }

    /**
     * @return array<string, mixed>
     */
    private function productionInboundCallPayload(
        string $callId,
        string $status,
        string $eventId = 'evt-production-001',
        string $sourceNumber = '9876543210',
        string $destinationNumber = '1800123456',
    ): array {
        return [
            'SourceNumber' => $sourceNumber,
            'DestinationNumber' => $destinationNumber,
            'DisplayNumber' => '1204404276',
            'StartTime' => Carbon::parse('2026-07-10 10:49:42')->toDateTimeString(),
            'EndTime' => Carbon::parse('2026-07-10 10:52:39')->toDateTimeString(),
            'CallDuration' => '131',
            'Status' => $status,
            'Direction' => 'Inbound',
            'ResourceURL' => 'https://backend.pbx.bonvoice.com/example/recording.mp3',
            'DTMF' => null,
            'callBackParentID' => null,
            'Network' => 'gsm',
            'DataSource' => 'Bonvoice',
            'AccountID' => 'acct-001',
            'callType' => '2',
            'callID' => $callId,
            'callerCountryCode' => '91',
            'eventID' => $eventId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundCallPayload(
        string $callId = 'call-live-001',
        string $leg = 'A',
        string $status = 'Ringing',
        string $eventId = 'evt-live-001',
        string $sourceNumber = '9876543210',
        string $destinationNumber = '1800123456',
    ): array {
        return [
            'SourceNumber' => $sourceNumber,
            'DestinationNumber' => $destinationNumber,
            'DisplayNumber' => $destinationNumber,
            'StartTime' => Carbon::parse('2026-07-08T14:32:23')->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => 'Support',
            'AccountID' => 'acct-001',
            'callID' => $callId,
            'Direction' => 'Inbound',
            'Leg' => $leg,
            'Status' => $status,
            'eventID' => $eventId,
            'callBackParentID' => null,
            'callBackParams' => null,
        ];
    }
}
