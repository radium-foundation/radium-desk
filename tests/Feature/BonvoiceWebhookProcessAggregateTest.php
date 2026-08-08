<?php

namespace Tests\Feature;

use App\Enums\BonvoiceCallAlertType;
use App\Enums\OutboxEventStatus;
use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Notifications\IncomingCallAssistNotification;
use App\Services\Bonvoice\BonvoiceWebhookOutboxWriter;
use App\Services\Bonvoice\BonvoiceWebhookProcessorService;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BonvoiceWebhookProcessAggregateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bonvoice.verify_signature' => false,
            'bonvoice.verify_webhook_auth' => false,
            'bonvoice.require_bearer' => false,
            'bonvoice.account_id' => 'acct-001',
            'bonvoice.missed_call_recovery_enabled' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_incoming_webhook_processes_own_outbox_aggregate_in_request(): void
    {
        User::factory()->create();

        $this->postJson('/api/webhooks/bonvoice', $this->inboundPayload())
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);

        $this->assertDatabaseHas('bonvoice_webhook_logs', [
            'processing_status' => BonvoiceWebhookProcessorService::STATUS_PROCESSED,
        ]);

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => 'call-aggregate-001',
            'direction' => 'Inbound',
            'status' => 'Ringing',
        ]);

        $this->assertSame(
            OutboxEventStatus::Completed,
            OutboxEvent::query()
                ->where('event_type', BonvoiceWebhookOutboxWriter::EVENT_TYPE)
                ->where('aggregate_type', BonvoiceWebhookOutboxWriter::AGGREGATE_TYPE)
                ->value('status'),
        );
    }

    public function test_incoming_webhook_does_not_drain_unrelated_cashfree_outbox(): void
    {
        User::factory()->create();

        OutboxEvent::query()->create([
            'idempotency_key' => 'cashfree.unrelated.bonvoice.aggregate',
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'aggregate_type' => CashfreeWebhookOutboxWriter::AGGREGATE_TYPE,
            'aggregate_id' => 999001,
            'payload' => ['webhook_log_id' => 999001],
            'status' => OutboxEventStatus::Pending,
            'attempts' => 0,
            'available_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundPayload(callId: 'call-isolate-001'))
            ->assertOk();

        $this->assertSame(
            OutboxEventStatus::Pending,
            OutboxEvent::query()
                ->where('idempotency_key', 'cashfree.unrelated.bonvoice.aggregate')
                ->value('status'),
        );

        $this->assertSame(
            OutboxEventStatus::Completed,
            OutboxEvent::query()
                ->where('event_type', BonvoiceWebhookOutboxWriter::EVENT_TYPE)
                ->value('status'),
        );
    }

    public function test_incoming_ringing_webhook_creates_live_assist_alert(): void
    {
        Notification::fake();

        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->postJson('/api/webhooks/bonvoice', $this->inboundPayload(
            callId: 'call-live-assist-001',
            status: 'Ringing',
            destinationNumber: '1800123456',
        ))->assertOk();

        $this->assertDatabaseHas('bonvoice_call_alerts', [
            'call_id' => 'call-live-assist-001',
            'user_id' => $agent->id,
            'alert_type' => BonvoiceCallAlertType::UnknownCaller->value,
        ]);

        Notification::assertSentTo($agent, IncomingCallAssistNotification::class);
        $this->assertSame(1, BonvoiceCallEvent::query()->count());
    }

    public function test_duplicate_webhook_logs_remain_idempotent_for_call_event(): void
    {
        User::factory()->create();

        $payload = $this->inboundPayload(callId: 'call-dupe-001', eventId: 'evt-dupe-001');

        $this->postJson('/api/webhooks/bonvoice', $payload)->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $payload)->assertOk();

        $this->assertSame(2, BonvoiceWebhookLog::query()->count());
        $this->assertSame(2, OutboxEvent::query()->where('event_type', BonvoiceWebhookOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(1, BonvoiceCallEvent::query()->where('call_id', 'call-dupe-001')->count());
    }

    public function test_click_to_call_route_is_removed(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->postJson('/bonvoice/click-to-call', [
                'order_id' => 1,
            ])
            ->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundPayload(
        string $callId = 'call-aggregate-001',
        string $status = 'Ringing',
        string $eventId = 'evt-aggregate-001',
        string $destinationNumber = '1800123456',
    ): array {
        return [
            'SourceNumber' => '9876543210',
            'DestinationNumber' => $destinationNumber,
            'DisplayNumber' => $destinationNumber,
            'StartTime' => Carbon::parse('2026-07-08T10:15:00')->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => 'Support',
            'AccountID' => 'acct-001',
            'callID' => $callId,
            'Direction' => 'Inbound',
            'Leg' => 'A',
            'Status' => $status,
            'AgentStatus' => 'Idle',
            'eventID' => $eventId,
            'callBackParentID' => null,
            'callBackParams' => null,
        ];
    }
}
