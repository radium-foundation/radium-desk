<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookSignatureVerifier;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCashfreeWebhooks;
use Tests\TestCase;

class CashfreeWebhookSignatureTest extends TestCase
{
    use InteractsWithCashfreeWebhooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.client_secret' => 'test-client-secret']);

        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->seed(SettingsSeeder::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(string $cfPaymentId = '1453002795', string $orderId = 'order_OFR_2'): array
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

    public function test_valid_signature_allows_webhook_processing(): void
    {
        $response = $this->postSignedCashfreeWebhook($this->successfulPayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('processed', $log->processing_status);
    }

    public function test_invalid_signature_is_rejected_and_does_not_create_order(): void
    {
        $payload = $this->successfulPayload();
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = '1617695238078';

        $response = $this->call(
            'POST',
            '/api/webhooks/cashfree',
            [],
            [],
            [],
            [
                'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
                'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid-signature',
                'HTTP_CONTENT_TYPE' => 'application/json',
            ],
            $rawBody,
        );

        $response->assertUnauthorized();

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(CashfreeWebhookSignatureVerifier::ERROR_INVALID_SIGNATURE, $log->processing_error);
        $this->assertNotNull($log->processed_at);
        $this->assertNull($log->incident_id);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_valid_signature_creates_order_with_financial_reference_fields(): void
    {
        $this->postSignedCashfreeWebhook($this->successfulPayload())->assertOk();

        $order = Order::query()->where('cashfree_payment_id', '1453002795')->first();
        $this->assertNotNull($order);
        $this->assertSame('234928698581', $order->bank_reference);
        $this->assertSame('1634766330', $order->gateway_order_id);
        $this->assertSame('1504280029', $order->gateway_payment_id);
        $this->assertSame('order_OFR_2', $order->order_id);

        $incident = Incident::query()->first();
        $this->assertNotNull($incident);
        $this->assertSame(IncidentStatus::AwaitingProductDetails, $incident->status);
        $this->assertSame(IncidentSource::Cashfree, $incident->source);
    }

    public function test_duplicate_webhook_retry_with_valid_signature_is_idempotent(): void
    {
        $payload = $this->successfulPayload();

        $this->postSignedCashfreeWebhook($payload)->assertOk();
        $this->postSignedCashfreeWebhook($payload)->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(2, CashfreeWebhookLog::query()->count());

        $logs = CashfreeWebhookLog::query()->orderBy('id')->get();
        $this->assertSame('processed', $logs[0]->processing_status);
        $this->assertSame('processed', $logs[1]->processing_status);
        $this->assertSame($logs[0]->incident_id, $logs[1]->incident_id);
    }

    public function test_missing_signature_headers_return_bad_request(): void
    {
        $response = $this->postJson('/api/webhooks/cashfree', $this->successfulPayload());

        $response->assertBadRequest();

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(CashfreeWebhookSignatureVerifier::ERROR_INVALID_SIGNATURE, $log->processing_error);
        $this->assertSame(0, Order::query()->count());
    }
}
