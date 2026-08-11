<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\OrderStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CashfreeExistingOrderPaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);

        $this->seed(RolePermissionSeeder::class);

        $admin = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->seed(SettingsSeeder::class);

        config(['radiumbox.enabled' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(string $cfPaymentId = '6206001295', string $orderId = 'RD3483568'): array
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
                    'customer_name' => 'Webhook Customer',
                    'customer_email' => 'webhook@example.com',
                    'customer_phone' => '9999999999',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => '1634766330',
                    'gateway_payment_id' => '1504280029',
                ],
            ],
        ];
    }

    public function test_webhook_links_payment_to_existing_lowercase_order_without_duplicate_insert(): void
    {
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $existingOrder = Order::query()->create([
            'order_id' => 'rd3483568',
            'customer_name' => 'Legacy Customer',
            'customer_email' => 'legacy@example.com',
            'customer_phone' => '9900000001',
            'serial_number' => 'SN-LEGACY-001',
            'product_name' => 'Legacy Product',
            'device_model' => 'Legacy Model',
            'status' => OrderStatus::Active,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $existingIncident = Incident::query()->create([
            'order_id' => $existingOrder->id,
            'reference_no' => 'SC34409',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Legacy import case',
            'description' => 'Pre-existing service case.',
            'status' => IncidentStatus::Closed,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $response = $this->postJson('/api/webhooks/cashfree', $this->successfulPayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->count());

        $order = $existingOrder->fresh();
        $this->assertSame('rd3483568', $order->order_id);
        $this->assertSame('Legacy Customer', $order->customer_name);
        $this->assertSame('legacy@example.com', $order->customer_email);
        $this->assertSame('9900000001', $order->customer_phone);
        $this->assertSame('SN-LEGACY-001', $order->serial_number);
        $this->assertSame('Legacy Product', $order->product_name);
        $this->assertSame('Legacy Model', $order->device_model);
        $this->assertSame('6206001295', $order->cashfree_payment_id);
        $this->assertSame('1.00', $order->payment_amount);
        $this->assertSame('UPI', $order->payment_method);
        $this->assertSame('234928698581', $order->bank_reference);
        $this->assertSame('1634766330', $order->gateway_order_id);
        $this->assertSame('1504280029', $order->gateway_payment_id);
        $this->assertNotNull($order->payment_date);

        $log = CashfreeWebhookLog::query()->firstOrFail();
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);
        $this->assertNull($log->processing_error);
        $this->assertSame($existingIncident->id, $log->incident_id);
    }

    public function test_repeated_webhook_link_is_idempotent(): void
    {
        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $existingOrder = Order::query()->create([
            'order_id' => 'rd3483568',
            'customer_name' => 'Legacy Customer',
            'status' => OrderStatus::Active,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        Incident::query()->create([
            'order_id' => $existingOrder->id,
            'reference_no' => 'SC34409',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Legacy import case',
            'description' => 'Pre-existing service case.',
            'status' => IncidentStatus::Closed,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $payload = $this->successfulPayload();

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(2, CashfreeWebhookLog::query()->count());
        $this->assertSame(2, CashfreeWebhookLog::query()->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)->count());
    }

    public function test_existing_order_link_does_not_dispatch_enrichment_job(): void
    {
        Queue::fake();

        $systemUser = User::query()->where('email', 'superadmin@radium.local')->firstOrFail();

        $existingOrder = Order::query()->create([
            'order_id' => 'rd3483568',
            'status' => OrderStatus::Active,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        Incident::query()->create([
            'order_id' => $existingOrder->id,
            'reference_no' => 'SC34409',
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Legacy import case',
            'description' => 'Pre-existing service case.',
            'status' => IncidentStatus::Closed,
            'created_by' => $systemUser->id,
            'updated_by' => $systemUser->id,
        ]);

        $this->postJson('/api/webhooks/cashfree', $this->successfulPayload())->assertOk();

        Queue::assertNotPushed(RadiumBoxOrderEnrichmentJob::class);
    }

    public function test_unrelated_query_exception_still_marks_webhook_failed(): void
    {
        $payload = $this->successfulPayload();
        unset($payload['data']['order']['order_id']);

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $log = CashfreeWebhookLog::query()->firstOrFail();
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_FAILED, $log->processing_status);
        $this->assertStringContainsString('order_id', (string) $log->processing_error);
        $this->assertSame(0, Order::query()->count());
    }
}
