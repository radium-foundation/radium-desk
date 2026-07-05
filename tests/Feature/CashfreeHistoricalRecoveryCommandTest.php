<?php

namespace Tests\Feature;

use App\Enums\OutboxEventStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookDeferredOperationsService;
use App\Services\Cashfree\CashfreeWebhookOutboxWriter;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CashfreeHistoricalRecoveryCommandTest extends TestCase
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
    private function successfulPayload(string $cfPaymentId = '5890800716', string $orderId = 'RD3433235'): array
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

    private function createFailedLog(
        string $cfPaymentId,
        string $orderId,
        string $error,
    ): CashfreeWebhookLog {
        $payload = $this->successfulPayload($cfPaymentId, $orderId);

        return CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $cfPaymentId,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => $error,
            'processed_at' => now(),
        ]);
    }

    public function test_failed_webhook_creates_missing_order_on_replay(): void
    {
        Queue::fake();

        $log = $this->createFailedLog(
            cfPaymentId: '5907598359',
            orderId: 'RD3436189',
            error: 'App\\Services\\DashboardPersonalizationService::defaultViewFor(): Argument #1 ($user) must be of type App\\Models\\User, null given',
        );

        $this->artisan('cashfree:recover-historical')
            ->assertSuccessful();

        $log->refresh();
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);
        $this->assertNull($log->processing_error);
        $this->assertNotNull($log->incident_id);

        $order = Order::query()->where('cashfree_payment_id', '5907598359')->first();
        $this->assertNotNull($order);
        $this->assertSame('RD3436189', $order->order_id);

        $this->assertSame(1, Incident::query()->count());

        $this->assertSame(3, OutboxEvent::query()->where('status', OutboxEventStatus::Completed)->count());
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => CashfreeWebhookOutboxWriter::EVENT_TYPE,
            'status' => OutboxEventStatus::Completed->value,
            'payload->operation' => CashfreeWebhookDeferredOperationsService::OPERATION_RADIUMBOX_ENRICHMENT,
        ]);

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, function (RadiumBoxOrderEnrichmentJob $job) use ($order): bool {
            return $job->orderId === $order->id;
        });
    }

    public function test_existing_order_is_skipped(): void
    {
        Queue::fake();

        $payload = $this->successfulPayload('5890806787', 'RD3433239');

        Order::query()->create([
            'order_id' => 'RD3433239',
            'cashfree_payment_id' => '5890806787',
            'customer_name' => 'Existing Customer',
            'status' => 'active',
            'created_by' => User::query()->first()->id,
            'updated_by' => User::query()->first()->id,
        ]);

        $this->createFailedLog(
            cfPaymentId: '5890806787',
            orderId: 'RD3433239',
            error: 'Invalid webhook signature',
        );

        $this->artisan('cashfree:recover-historical', ['--dry-run' => true])
            ->expectsOutputToContain('Found: 1')
            ->expectsOutputToContain('Recoverable: 0')
            ->expectsOutputToContain('Already exists: 1')
            ->assertSuccessful();

        $this->artisan('cashfree:recover-historical')
            ->expectsOutputToContain('Recovered: 0')
            ->assertSuccessful();

        $this->assertSame(1, Order::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_invalid_payment_is_counted_as_unsafe(): void
    {
        $payload = $this->successfulPayload('5890999999', 'RD3439999');
        $payload['data']['payment']['payment_status'] = 'FAILED';

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '5890999999',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'Invalid webhook signature',
            'processed_at' => now(),
        ]);

        $this->artisan('cashfree:recover-historical', ['--dry-run' => true])
            ->expectsOutputToContain('Found: 0')
            ->expectsOutputToContain('Unsafe: 0')
            ->assertSuccessful();
    }

    public function test_recovery_is_idempotent(): void
    {
        Queue::fake();

        $this->createFailedLog(
            cfPaymentId: '5910748391',
            orderId: 'RD3436304',
            error: 'App\\Services\\DashboardPersonalizationService::defaultViewFor(): Argument #1 ($user) must be of type App\\Models\\User, null given',
        );

        $this->artisan('cashfree:recover-historical')
            ->expectsOutputToContain('Recovered: 1')
            ->assertSuccessful();

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '5910748391')->count());

        $this->artisan('cashfree:recover-historical')
            ->expectsOutputToContain('Found: 0')
            ->expectsOutputToContain('Recoverable: 0')
            ->assertSuccessful();

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '5910748391')->count());
        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, 1);
    }

    public function test_dry_run_reports_recoverable_historical_failures(): void
    {
        $this->createFailedLog(
            cfPaymentId: '5890800716',
            orderId: 'RD3433235',
            error: 'Cashfree system user is not configured or inactive.',
        );

        $this->createFailedLog(
            cfPaymentId: '5890800716',
            orderId: 'RD3433235',
            error: 'Invalid webhook signature',
        );

        $this->artisan('cashfree:recover-historical', ['--dry-run' => true])
            ->expectsOutputToContain('Found: 1')
            ->expectsOutputToContain('Recoverable: 1')
            ->expectsOutputToContain('Already exists: 0')
            ->expectsOutputToContain('Unsafe: 0')
            ->assertSuccessful();
    }

    public function test_missing_cf_payment_id_is_unsafe(): void
    {
        $payload = $this->successfulPayload('5890111111', 'RD3431111');
        unset($payload['data']['payment']['cf_payment_id']);

        CashfreeWebhookLog::query()->create([
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'Cashfree webhook payload is missing cf_payment_id.',
            'processed_at' => now(),
        ]);

        $this->artisan('cashfree:recover-historical', ['--dry-run' => true])
            ->expectsOutputToContain('Found: 1')
            ->expectsOutputToContain('Recoverable: 0')
            ->expectsOutputToContain('Unsafe: 1')
            ->assertSuccessful();
    }
}
