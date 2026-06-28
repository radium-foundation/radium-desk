<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CashfreeWebhookProcessorTest extends TestCase
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

    public function test_successful_payment_webhook_creates_service_request(): void
    {
        $response = $this->postJson('/api/webhooks/cashfree', $this->successfulPayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $log = CashfreeWebhookLog::query()->first();
        $this->assertNotNull($log);
        $this->assertSame('processed', $log->processing_status);
        $this->assertNotNull($log->processed_at);
        $this->assertNull($log->processing_error);
        $this->assertSame('1453002795', $log->cf_payment_id);
        $this->assertNotNull($log->incident_id);

        $order = Order::query()->where('cashfree_payment_id', '1453002795')->first();
        $this->assertNotNull($order);
        $this->assertSame('order_OFR_2', $order->order_id);
        $this->assertSame('Jane Doe', $order->customer_name);
        $this->assertSame('test@gmail.com', $order->customer_email);
        $this->assertSame('9908734801', $order->customer_phone);
        $this->assertNull($order->serial_number);
        $this->assertNull($order->product_name);
        $this->assertNull($order->device_model);
        $this->assertSame('1.00', $order->payment_amount);
        $this->assertSame('UPI', $order->payment_method);
        $this->assertSame('234928698581', $order->bank_reference);
        $this->assertSame('1634766330', $order->gateway_order_id);
        $this->assertSame('1504280029', $order->gateway_payment_id);

        $incident = Incident::query()->find($log->incident_id);
        $this->assertNotNull($incident);
        $this->assertSame(IncidentStatus::AwaitingProductDetails, $incident->status);
        $this->assertSame(IncidentSource::Cashfree, $incident->source);
    }

    public function test_duplicate_cf_payment_id_does_not_create_second_service_request(): void
    {
        $payload = $this->successfulPayload();

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Incident::query()->count());
        $this->assertSame(2, CashfreeWebhookLog::query()->count());

        $logs = CashfreeWebhookLog::query()->orderBy('id')->get();
        $this->assertSame('processed', $logs[0]->processing_status);
        $this->assertSame('processed', $logs[1]->processing_status);
        $this->assertSame($logs[0]->incident_id, $logs[1]->incident_id);
    }

    public function test_non_success_webhook_is_logged_without_processing(): void
    {
        $response = $this->postJson('/api/webhooks/cashfree', [
            'type' => 'PAYMENT_FAILED_WEBHOOK',
            'data' => [
                'payment' => [
                    'cf_payment_id' => '999',
                    'payment_status' => 'FAILED',
                ],
            ],
        ]);

        $response->assertOk();

        $log = CashfreeWebhookLog::query()->first();
        $this->assertSame('received', $log->processing_status);
        $this->assertNull($log->incident_id);
        $this->assertNull($log->processed_at);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_success_webhook_missing_cf_payment_id_marks_log_as_failed(): void
    {
        $payload = $this->successfulPayload();
        unset($payload['data']['payment']['cf_payment_id']);

        $response = $this->postJson('/api/webhooks/cashfree', $payload);

        $response->assertOk();

        $log = CashfreeWebhookLog::query()->first();
        $this->assertSame('failed', $log->processing_status);
        $this->assertStringContainsString('cf_payment_id', (string) $log->processing_error);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_system_user_must_exist_for_service_request_creation(): void
    {
        User::query()->where('email', 'superadmin@radium.local')->delete();

        $response = $this->postJson('/api/webhooks/cashfree', $this->successfulPayload());

        $response->assertOk();

        $log = CashfreeWebhookLog::query()->first();
        $this->assertSame('failed', $log->processing_status);
        $this->assertSame(0, Incident::query()->count());
    }

    public function test_successful_payment_webhook_dispatches_radiumbox_enrichment_job(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/webhooks/cashfree', $this->successfulPayload());

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $order = Order::query()->where('cashfree_payment_id', '1453002795')->first();
        $this->assertNotNull($order);

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });
    }

    public function test_duplicate_webhook_does_not_dispatch_second_enrichment_job(): void
    {
        Queue::fake();

        $payload = $this->successfulPayload();

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);
    }
}
