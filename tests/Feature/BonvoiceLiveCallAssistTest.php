<?php

namespace Tests\Feature;

use App\Enums\BonvoiceCallAlertType;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\BonvoiceCallAlert;
use App\Models\BonvoiceCallEvent;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Notifications\IncomingCallAssistNotification;
use App\Services\Bonvoice\BonvoiceAgentResolver;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3444319',
            'serial_number' => 'SN-LIVE-1',
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

    public function test_terminal_only_webhook_does_not_create_alert(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-LIVE-TERMINAL',
            'serial_number' => 'SN-LIVE-TERMINAL',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundCallPayload(
            callId: 'call-live-terminal-only-001',
            status: 'ANSWERED',
        ))->assertOk();

        $this->assertDatabaseMissing('bonvoice_call_alerts', [
            'call_id' => 'call-live-terminal-only-001',
        ]);

        Notification::assertNothingSent();
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
