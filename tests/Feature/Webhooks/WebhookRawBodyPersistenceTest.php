<?php

namespace Tests\Feature\Webhooks;

use App\Enums\CashfreeMissedBatchHealDisposition;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\SupportAppointmentTimeSlot;
use App\Models\BonvoiceWebhookLog;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\InteraktWebhookLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeMissedWebhookHealService;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\Cashfree\CashfreeWebhookSignatureVerifier;
use App\Services\Interakt\InteraktFlowWebhookProcessorService;
use App\Services\Interakt\InteraktWebhookProcessorService;
use App\Services\Interakt\InteraktWebhookSignatureVerifier;
use App\Services\Interakt\WhatsAppFlowService;
use App\Services\SupportScheduleAvailabilityService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\Support\InteractsWithCashfreeWebhooks;
use Tests\Support\InteractsWithInteraktWebhooks;
use Tests\TestCase;

class WebhookRawBodyPersistenceTest extends TestCase
{
    use EnsuresCashfreeSystemUser;
    use InteractsWithCashfreeWebhooks;
    use InteractsWithInteraktWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.verify_signature' => true,
            'cashfree.client_secret' => 'test-client-secret',
            'interakt.verify_signature' => true,
            'interakt.webhook_secret' => 'test-interakt-webhook-secret',
            'interakt.flow_id' => '2559716037790863',
            'interakt.flow_token_ttl_hours' => 24,
            'bonvoice.verify_signature' => false,
            'bonvoice.webhook_token' => 'test-bonvoice-token',
            'bonvoice.account_id' => 'acct-001',
            'radiumbox.enabled' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $admin = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_cashfree_webhook_persists_request_payload_without_raw_body_and_signature_uses_live_body(): void
    {
        $payload = $this->cashfreeSuccessfulPayload();

        $this->postSignedCashfreeWebhook($payload)->assertOk()->assertExactJson(['status' => 'ok']);

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertNull($log->raw_body);
        $this->assertSame($payload, $log->request_payload);
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);
        $this->assertNotNull(Order::query()->where('cashfree_payment_id', '1453002795')->first());
    }

    public function test_cashfree_invalid_signature_still_logs_without_raw_body(): void
    {
        $payload = $this->cashfreeSuccessfulPayload();
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/webhooks/cashfree',
            [],
            [],
            [],
            [
                'HTTP_X_WEBHOOK_TIMESTAMP' => '1617695238078',
                'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid-signature',
                'HTTP_CONTENT_TYPE' => 'application/json',
            ],
            $rawBody,
        )->assertUnauthorized();

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertNull($log->raw_body);
        $this->assertSame($payload, $log->request_payload);
        $this->assertSame(CashfreeWebhookSignatureVerifier::ERROR_INVALID_SIGNATURE, $log->processing_error);
    }

    public function test_interakt_webhook_persists_payload_without_raw_body(): void
    {
        $payload = $this->officialIncomingMessagePayload();

        $this->postSignedInteraktWebhookAndDrain($payload)->assertOk();

        $log = InteraktWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertNull($log->raw_body);
        $this->assertSame($payload, $log->payload);
        $this->assertSame(InteraktWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);
    }

    public function test_interakt_signed_webhook_verifies_live_body_without_persisting_raw_body(): void
    {
        $payload = $this->officialIncomingMessagePayload();

        $this->postSignedInteraktWebhookAndDrain($payload)->assertOk();

        $log = InteraktWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertNull($log->raw_body);
        $this->assertSame(InteraktWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);
    }

    public function test_interakt_invalid_signature_still_logs_without_raw_body(): void
    {
        $payload = $this->officialIncomingMessagePayload();
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/webhooks/interakt',
            [],
            [],
            [],
            [
                'HTTP_Interakt-Signature' => 'sha256=invalid-signature',
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody,
        )->assertUnauthorized();

        $log = InteraktWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertNull($log->raw_body);
        $this->assertSame($payload, $log->payload);
        $this->assertSame(InteraktWebhookSignatureVerifier::ERROR_INVALID_SIGNATURE, $log->processing_error);
    }

    public function test_interakt_flow_webhook_persists_payload_without_raw_body(): void
    {
        [, $flowToken] = $this->createIncidentWithFlowToken();

        $payload = $this->officialFlowResponsePayload([
            'flow_token' => $flowToken,
            'preferred_date' => app(SupportScheduleAvailabilityService::class)->nextBookableDate()->toDateString(),
            'preferred_time_slot' => SupportAppointmentTimeSlot::Morning->value,
            'phone_number' => '9876543210',
            'additional_notes' => 'Booked via WhatsApp Flow.',
        ]);

        $this->postSignedInteraktFlowWebhook($payload)->assertOk();

        $log = InteraktWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertNull($log->raw_body);
        $this->assertSame($payload, $log->payload);
        $this->assertSame(InteraktFlowWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);
    }

    public function test_bonvoice_webhook_persists_payload_without_raw_body(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-BV-RB-1',
            'serial_number' => 'SN-BV-RB-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'IVR Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $payload = $this->bonvoiceInboundCallPayload();

        $this->postJson('/api/webhooks/bonvoice', $payload)->assertOk();

        $log = BonvoiceWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertNull($log->raw_body);
        $this->assertSame($payload, $log->payload);
    }

    public function test_cashfree_processor_replays_from_request_payload_without_raw_body(): void
    {
        $payload = $this->cashfreeSuccessfulPayload('1453002796', 'order_replay_1');

        $log = CashfreeWebhookLog::query()->create([
            'webhook_version' => '2023-08-01',
            'cf_payment_id' => '1453002796',
            'request_headers' => [],
            'request_payload' => $payload,
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'seeded failure',
        ]);

        $processed = app(CashfreeWebhookProcessorService::class)->process($log->fresh());

        $this->assertNull($processed->raw_body);
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $processed->processing_status);
        $this->assertNotNull(Order::query()->where('cashfree_payment_id', '1453002796')->first());
    }

    public function test_cashfree_heal_service_inserts_without_raw_body_and_processes(): void
    {
        $this->ensureCashfreeSystemUser();

        config([
            'cashfree.api.app_id' => 'test-app-id',
            'cashfree.api.secret' => 'test-api-secret',
            'cashfree.api.base_url' => 'https://api.cashfree.test/pg',
            'cashfree.api.version' => '2026-01-01',
            'cashfree.missed_batch_heal.gap_allowlist' => [],
        ]);

        $row = [
            'order_id' => 'RD3478381',
            'cf_payment_id' => '6182736145',
            'amount' => 499,
            'serial' => '2507I005575',
            'product' => 'MSO 1300 E3 RD L1',
            'service' => '1 Year Unlimited',
            'bank_reference' => '920921596836',
            'cf_order_id' => '6595996043',
            'payment_time' => '2026-08-07T11:47:15+05:30',
        ];

        Http::fake([
            "https://api.cashfree.test/pg/orders/{$row['order_id']}" => Http::response([
                'order_id' => $row['order_id'],
                'cf_order_id' => $row['cf_order_id'],
                'order_status' => 'PAID',
                'order_amount' => $row['amount'],
                'order_currency' => 'INR',
                'customer_details' => [
                    'customer_name' => 'Heal Customer',
                    'customer_email' => 'heal@example.com',
                    'customer_phone' => '9876543210',
                ],
                'order_tags' => [
                    'product_name' => $row['product'],
                    'serial_no' => $row['serial'],
                    'rd_service_name' => $row['service'],
                ],
            ], 200),
            "https://api.cashfree.test/pg/orders/{$row['order_id']}/payments" => Http::response([[
                'cf_payment_id' => $row['cf_payment_id'],
                'order_id' => $row['order_id'],
                'payment_status' => 'SUCCESS',
                'payment_amount' => $row['amount'],
                'payment_currency' => 'INR',
                'payment_time' => $row['payment_time'],
                'payment_group' => 'upi',
                'bank_reference' => $row['bank_reference'],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => $row['cf_order_id'],
                    'gateway_payment_id' => $row['cf_payment_id'],
                ],
            ]], 200),
        ]);

        $result = app(CashfreeMissedWebhookHealService::class)->heal([$row['order_id']], dryRun: false);

        $this->assertSame(CashfreeMissedBatchHealDisposition::Healed, $result->orders[0]->disposition);

        $log = CashfreeWebhookLog::query()->where('cf_payment_id', $row['cf_payment_id'])->first();
        $this->assertNotNull($log);
        $this->assertNull($log->raw_body);
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);
        $this->assertNotNull(Order::query()->where('order_id', $row['order_id'])->first());
    }

    public function test_cashfree_explorer_renders_historical_raw_body_and_handles_null_on_new_records(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $historical = CashfreeWebhookLog::query()->create([
            'webhook_version' => '2023-08-01',
            'request_headers' => [],
            'request_payload' => ['type' => 'PAYMENT_SUCCESS'],
            'raw_body' => '{"type":"PAYMENT_SUCCESS"}',
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
        ]);

        $this->actingAs($admin)
            ->get(route('cashfree.webhook-explorer.show', $historical))
            ->assertOk()
            ->assertSee('PAYMENT_SUCCESS')
            ->assertDontSee('No raw body recorded.');

        $payload = $this->cashfreeSuccessfulPayload('1453002797', 'order_explorer_1');
        $this->postSignedCashfreeWebhook($payload)->assertOk();

        $fresh = CashfreeWebhookLog::query()->where('cf_payment_id', '1453002797')->first();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->raw_body);

        $this->actingAs($admin)
            ->get(route('cashfree.webhook-explorer.show', $fresh))
            ->assertOk()
            ->assertSee('No raw body recorded.')
            ->assertSee('order_explorer_1');
    }

    /**
     * @return array<string, mixed>
     */
    private function cashfreeSuccessfulPayload(string $cfPaymentId = '1453002795', string $orderId = 'order_OFR_2'): array
    {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2023-08-01T11:16:10+05:30',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 2,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 1,
                    'payment_currency' => 'INR',
                    'payment_time' => '2022-12-15T12:20:29+05:30',
                    'payment_group' => 'upi',
                    'bank_reference' => '234928698581',
                ],
                'customer_details' => [
                    'customer_name' => 'Jane Doe',
                    'customer_email' => 'test@gmail.com',
                    'customer_phone' => '9908734801',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => '1634766330',
                    'gateway_payment_id' => '1504280029',
                ],
            ],
        ];
    }

    /**
     * @return array{0: Incident, 1: string}
     */
    private function createIncidentWithFlowToken(): array
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-FLOW-RB-1',
            'serial_number' => 'SN-FLOW-RB-1',
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'customer_name' => 'Flow Customer',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-FLOW-RB-1',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Open support case',
            'description' => 'Flow webhook raw body test fixture.',
            'status' => IncidentStatus::Open,
            'high_priority' => false,
            'assigned_to_user_id' => $agent->id,
            'created_by' => $agent->id,
            'updated_by' => $agent->id,
        ]);

        $flowToken = app(WhatsAppFlowService::class)->generateToken($incident);

        return [$incident, $flowToken];
    }

    /**
     * @return array<string, mixed>
     */
    private function bonvoiceInboundCallPayload(): array
    {
        return [
            'SourceNumber' => '9876543210',
            'DestinationNumber' => '1800123456',
            'DisplayNumber' => '1800123456',
            'StartTime' => now()->toIso8601String(),
            'DataSource' => 'IVR',
            'callType' => 'Support',
            'AccountID' => 'acct-001',
            'callID' => 'call-raw-body-1',
            'Direction' => 'Inbound',
            'Leg' => 'A',
            'Status' => 'Ringing',
            'AgentStatus' => 'Idle',
            'eventID' => 'evt-raw-body-1',
            'callBackParentID' => null,
            'callBackParams' => null,
        ];
    }
}
