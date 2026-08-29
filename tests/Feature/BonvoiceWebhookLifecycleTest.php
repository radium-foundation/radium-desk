<?php

namespace Tests\Feature;

use App\Models\BonvoiceCallAlert;
use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Bonvoice\BonvoiceWebhookProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BonvoiceWebhookLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bonvoice.verify_signature' => false,
            'bonvoice.verify_webhook_auth' => false,
            'bonvoice.require_bearer' => false,
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.account_id' => 'acct-001',
            'bonvoice.missed_call_recovery_enabled' => false,
            'bonvoice.auto_open_customer360' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_lifecycle_zero_to_hangup_noanswer_creates_ringing_alert_and_terminal_event(): void
    {
        Notification::fake();
        $this->seedInboundAgent();

        $callId = 'call-lifecycle-noanswer-001';

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '0', eventId: 'e0'))->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '0.5', agentStatus: 'DIALLING', eventId: 'e05'))->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '1', eventId: 'e1'))->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '2', status: 'NOANSWER', eventId: 'e2'))->assertOk();

        $this->assertSame(4, BonvoiceWebhookLog::query()->count());
        $this->assertSame(1, BonvoiceCallEvent::query()->count());
        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => $callId,
            'leg' => 'call',
            'call_type' => '2',
            'status' => 'NOANSWER',
        ]);
        $this->assertSame(1, BonvoiceCallAlert::query()->where('call_id', $callId)->count());
        $this->assertSame(BonvoiceWebhookProcessorService::STATUS_PROCESSED, BonvoiceWebhookLog::query()->latest('id')->value('processing_status'));
    }

    public function test_lifecycle_zero_to_hangup_answered_keeps_one_alert(): void
    {
        Notification::fake();
        $this->seedInboundAgent();

        $callId = 'call-lifecycle-answered-001';

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '0', eventId: 'e0'))->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '0.5', eventId: 'e05'))->assertOk();
        $this->assertSame(1, BonvoiceCallAlert::query()->where('call_id', $callId)->count());
        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => $callId,
            'call_type' => '0.5',
        ]);

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '1', eventId: 'e1'))->assertOk();
        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => $callId,
            'call_type' => '1',
            'status' => null,
        ]);
        $this->assertSame(1, BonvoiceCallAlert::query()->where('call_id', $callId)->count());

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '2', status: 'ANSWERED', eventId: 'e2'))->assertOk();

        $this->assertSame(1, BonvoiceCallEvent::query()->count());
        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => $callId,
            'call_type' => '2',
            'status' => 'ANSWERED',
        ]);
        $this->assertSame(1, BonvoiceCallAlert::query()->where('call_id', $callId)->count());
    }

    public function test_call_type_zero_point_five_without_agent_status_triggers_incoming_alert(): void
    {
        Notification::fake();
        $this->seedInboundAgent();

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload(
            'call-ringing-no-agent-status',
            '0.5',
        ))->assertOk();

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => 'call-ringing-no-agent-status',
            'call_type' => '0.5',
            'status' => null,
            'agent_status' => null,
        ]);
        $this->assertSame(1, BonvoiceCallAlert::query()->where('call_id', 'call-ringing-no-agent-status')->count());
    }

    public function test_late_ringing_after_noanswer_does_not_regress_terminal_state(): void
    {
        Notification::fake();
        $this->seedInboundAgent();

        $callId = 'call-late-05-after-noanswer';

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '2', status: 'NOANSWER', eventId: 'hangup'))->assertOk();
        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '0.5', agentStatus: 'DIALLING', eventId: 'late'))->assertOk();

        $event = BonvoiceCallEvent::query()->where('call_id', $callId)->first();
        $this->assertNotNull($event);
        $this->assertSame('2', $event->call_type);
        $this->assertSame('NOANSWER', $event->status);
        $this->assertNotSame('DIALLING', $event->agent_status);
        $this->assertSame(2, BonvoiceWebhookLog::query()->count());
        $this->assertSame(0, BonvoiceCallAlert::query()->where('call_id', $callId)->count());
        $this->assertTrue(BonvoiceWebhookLog::query()->where('event_type', '0.5')->where('processing_status', 'processed')->exists());
    }

    public function test_late_ringing_after_answered_hangup_does_not_regress_terminal_state(): void
    {
        Notification::fake();
        $this->seedInboundAgent();

        $callId = 'call-late-05-after-answered';

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '2', status: 'ANSWERED', eventId: 'hangup'))->assertOk();
        $this->assertSame(1, BonvoiceCallAlert::query()->where('call_id', $callId)->count());

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '0.5', agentStatus: 'DIALLING', eventId: 'late'))->assertOk();

        $event = BonvoiceCallEvent::query()->where('call_id', $callId)->first();
        $this->assertNotNull($event);
        $this->assertSame('2', $event->call_type);
        $this->assertSame('ANSWERED', $event->status);
        $this->assertSame(1, BonvoiceCallAlert::query()->where('call_id', $callId)->count());
    }

    #[DataProvider('hangupStatusProvider')]
    public function test_hangup_status_is_persisted_and_processed(string $status, bool $expectAlert): void
    {
        Notification::fake();
        $this->seedInboundAgent();

        $callId = 'call-hangup-'.strtolower($status);

        $this->postJson('/api/webhooks/bonvoice', $this->lifecyclePayload($callId, '2', status: $status))->assertOk();

        $this->assertDatabaseHas('bonvoice_call_events', [
            'call_id' => $callId,
            'call_type' => '2',
            'status' => $status,
        ]);
        $this->assertSame(
            BonvoiceWebhookProcessorService::STATUS_PROCESSED,
            BonvoiceWebhookLog::query()->where('event_type', '2:'.$status)->value('processing_status'),
        );
        $this->assertSame(
            $expectAlert ? 1 : 0,
            BonvoiceCallAlert::query()->where('call_id', $callId)->count(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function hangupStatusProvider(): array
    {
        return [
            'NOANSWER' => ['NOANSWER', false],
            'BUSY' => ['BUSY', false],
            'ANSWERED' => ['ANSWERED', true],
            'CANCEL' => ['CANCEL', false],
            'CHANUNAVAIL' => ['CHANUNAVAIL', false],
            'CONGESTION' => ['CONGESTION', false],
        ];
    }

    private function seedInboundAgent(): User
    {
        $agent = User::factory()->create([
            'bonvoice_extension' => '1800123456',
        ]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-LIFECYCLE',
            'serial_number' => 'SN-LIFECYCLE',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Lifecycle Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        return $agent;
    }

    /**
     * @return array<string, mixed>
     */
    private function lifecyclePayload(
        string $callId,
        string $callType,
        ?string $status = null,
        ?string $agentStatus = null,
        string $eventId = 'evt-lifecycle',
    ): array {
        $payload = [
            'SourceNumber' => '9876543210',
            'DestinationNumber' => '1800123456',
            'DisplayNumber' => '1204404276',
            'StartTime' => Carbon::parse('2026-08-29 14:32:20')->toDateTimeString(),
            'Direction' => 'Inbound',
            'Network' => 'gsm',
            'DataSource' => 'Bonvoice',
            'AccountID' => 'acct-001',
            'callType' => $callType,
            'callID' => $callId,
            'callerCountryCode' => '91',
            'eventID' => $eventId,
        ];

        if ($status !== null) {
            $payload['Status'] = $status;
        }

        if ($agentStatus !== null) {
            $payload['AgentStatus'] = $agentStatus;
        }

        if ($callType === '2') {
            $payload['EndTime'] = Carbon::parse('2026-08-29 14:33:10')->toDateTimeString();
            $payload['CallDuration'] = $status === 'ANSWERED' ? '50' : '0';
        }

        return $payload;
    }
}
