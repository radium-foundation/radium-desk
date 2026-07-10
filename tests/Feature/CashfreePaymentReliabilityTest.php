<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\NewContactIntent;
use App\Models\AuditLog;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\ReferenceSequence;
use App\Models\User;
use App\Services\Cashfree\CashfreeMissingOrderAutoRecoveryService;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\CustomerIntakeService;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CashfreePaymentReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.verify_signature' => false,
            'cashfree.persist_retry.max_attempts' => 3,
            'cashfree.persist_retry.sleep_milliseconds' => 0,
            'cashfree.auto_recover.enabled' => true,
            'radiumbox.enabled' => false,
        ]);

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
    private function successfulPayload(string $cfPaymentId, string $orderId): array
    {
        return [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2026-07-09T16:47:04+05:30',
            'data' => [
                'order' => [
                    'order_id' => $orderId,
                    'order_amount' => 917,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'SUCCESS',
                    'payment_amount' => 917,
                    'payment_currency' => 'INR',
                    'payment_time' => '2026-07-09T16:47:04+05:30',
                    'payment_group' => 'upi',
                    'bank_reference' => '244365871325',
                ],
                'customer_details' => [
                    'customer_name' => 'Paramesh',
                    'customer_email' => 'chcbpalli86@gmail.com',
                    'customer_phone' => '8688845550',
                ],
                'payment_gateway_details' => [
                    'gateway_name' => 'CASHFREE',
                    'gateway_order_id' => '6378590723',
                    'gateway_payment_id' => $cfPaymentId,
                ],
            ],
        ];
    }

    public function test_concurrent_intake_and_cashfree_webhook_both_succeed_with_unique_sc_references(): void
    {
        Queue::fake();

        ReferenceSequence::query()
            ->where('name', ReferenceSequence::SC)
            ->update(['current_value' => 100]);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $intakeIncident = app(CustomerIntakeService::class)->createNewContact(
            user: $agent,
            intent: NewContactIntent::GeneralSupport,
            source: IncidentSource::Call,
            customerName: 'Intake Caller',
            phone: '9000000001',
            serialNumber: null,
            product: null,
            notes: 'Concurrent intake',
            assignOnCreate: false,
        );

        $response = $this->postJson(
            '/api/webhooks/cashfree',
            $this->successfulPayload('5973763642', 'RD3445866'),
        );

        $response->assertOk()->assertExactJson(['status' => 'ok']);

        $cashfreeOrder = Order::query()->where('cashfree_payment_id', '5973763642')->first();
        $this->assertNotNull($cashfreeOrder);
        $this->assertSame('RD3445866', $cashfreeOrder->order_id);

        $cashfreeIncident = Incident::query()->where('order_id', $cashfreeOrder->id)->first();
        $this->assertNotNull($cashfreeIncident);
        $this->assertSame(IncidentSource::Cashfree, $cashfreeIncident->source);

        $this->assertNotSame($intakeIncident->reference_no, $cashfreeIncident->reference_no);
        $this->assertMatchesRegularExpression('/^SC\d{5}$/', $intakeIncident->reference_no);
        $this->assertMatchesRegularExpression('/^SC\d{5}$/', $cashfreeIncident->reference_no);
        $this->assertSame(2, Incident::query()->count());
        $this->assertSame(2, collect([$intakeIncident->reference_no, $cashfreeIncident->reference_no])->unique()->count());
    }

    public function test_mysql_deadlock_on_first_attempt_is_retried_successfully(): void
    {
        Queue::fake();

        $realReferenceService = app(IncidentReferenceService::class);
        $attempts = 0;

        $mock = Mockery::mock(IncidentReferenceService::class);
        $mock->shouldReceive('generate')
            ->andReturnUsing(function () use (&$attempts, $realReferenceService): string {
                $attempts++;

                if ($attempts === 1) {
                    throw $this->contentionException(1213, 'Deadlock found when trying to get lock; try restarting transaction');
                }

                return $realReferenceService->generate();
            });

        $this->app->instance(IncidentReferenceService::class, $mock);

        $response = $this->postJson(
            '/api/webhooks/cashfree',
            $this->successfulPayload('5973763642', 'RD3445866'),
        );

        $response->assertOk();

        $this->assertSame(2, $attempts);

        $log = CashfreeWebhookLog::query()->first();
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);
        $this->assertNull($log->processing_error);
        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '5973763642')->count());
        $this->assertSame(1, Incident::query()->count());
    }

    public function test_retry_does_not_duplicate_order_or_incident(): void
    {
        Queue::fake();

        $realReferenceService = app(IncidentReferenceService::class);
        $attempts = 0;

        $mock = Mockery::mock(IncidentReferenceService::class);
        $mock->shouldReceive('generate')
            ->andReturnUsing(function () use (&$attempts, $realReferenceService): string {
                $attempts++;

                if ($attempts === 1) {
                    throw $this->contentionException(1213, 'Deadlock found when trying to get lock');
                }

                return $realReferenceService->generate();
            });

        $this->app->instance(IncidentReferenceService::class, $mock);

        $payload = $this->successfulPayload('6111111111', 'RD3446111');

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();
        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '6111111111')->count());
        $this->assertSame(1, Incident::query()->where('source', IncidentSource::Cashfree)->count());
        $this->assertSame(2, CashfreeWebhookLog::query()->count());
        $this->assertSame(
            CashfreeWebhookLog::query()->oldest('id')->value('incident_id'),
            CashfreeWebhookLog::query()->latest('id')->value('incident_id'),
        );
    }

    public function test_already_recovered_payment_remains_safe_on_replay(): void
    {
        Queue::fake();

        $payload = $this->successfulPayload('6222222222', 'RD3446222');

        $this->postJson('/api/webhooks/cashfree', $payload)->assertOk();

        $failedSibling = CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '6222222222',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
            'processed_at' => now(),
        ]);

        $this->artisan('cashfree:recover-historical', ['--log' => (string) $failedSibling->id])
            ->expectsOutputToContain('Already exists: 1')
            ->expectsOutputToContain('Recovered: 0')
            ->assertSuccessful();

        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '6222222222')->count());
        $this->assertSame(1, Incident::query()->where('source', IncidentSource::Cashfree)->count());

        // AlreadyExists candidates are not replayed; processor still marks them processed if invoked.
        app(CashfreeWebhookProcessorService::class)->process($failedSibling->fresh());
        $failedSibling->refresh();
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $failedSibling->processing_status);
        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '6222222222')->count());
        $this->assertSame(1, Incident::query()->where('source', IncidentSource::Cashfree)->count());
    }

    public function test_auto_recover_heals_recoverable_missing_order_and_writes_audit(): void
    {
        Queue::fake();

        $payload = $this->successfulPayload('6333333333', 'RD3446333');

        $log = CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '6333333333',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock',
            'processed_at' => now(),
        ]);

        $this->artisan('cashfree:auto-recover-missing')
            ->expectsOutputToContain('Recovered: 1')
            ->assertSuccessful();

        $log->refresh();
        $this->assertSame(CashfreeWebhookProcessorService::STATUS_PROCESSED, $log->processing_status);
        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '6333333333')->count());

        $this->assertDatabaseHas('audit_logs', [
            'event' => CashfreeMissingOrderAutoRecoveryService::AUDIT_EVENT,
            'auditable_type' => $log->getMorphClass(),
            'auditable_id' => $log->id,
        ]);

        $audit = AuditLog::query()
            ->where('event', CashfreeMissingOrderAutoRecoveryService::AUDIT_EVENT)
            ->where('auditable_id', $log->id)
            ->first();

        $this->assertTrue((bool) ($audit?->new_values['recovered'] ?? false));
    }

    public function test_auto_recover_dry_run_does_not_mutate(): void
    {
        $payload = $this->successfulPayload('6444444444', 'RD3446444');

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '6444444444',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'deadlock',
            'processed_at' => now(),
        ]);

        $this->artisan('cashfree:auto-recover-missing', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('6444444444')
            ->assertSuccessful();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, AuditLog::query()->where('event', CashfreeMissingOrderAutoRecoveryService::AUDIT_EVENT)->count());
    }

    public function test_lock_wait_timeout_is_retried(): void
    {
        Queue::fake();

        $realReferenceService = app(IncidentReferenceService::class);
        $attempts = 0;

        $mock = Mockery::mock(IncidentReferenceService::class);
        $mock->shouldReceive('generate')
            ->andReturnUsing(function () use (&$attempts, $realReferenceService): string {
                $attempts++;

                if ($attempts === 1) {
                    throw $this->contentionException(1205, 'Lock wait timeout exceeded; try restarting transaction');
                }

                return $realReferenceService->generate();
            });

        $this->app->instance(IncidentReferenceService::class, $mock);

        $this->postJson(
            '/api/webhooks/cashfree',
            $this->successfulPayload('6555555555', 'RD3446555'),
        )->assertOk();

        $this->assertSame(2, $attempts);
        $this->assertSame(1, Order::query()->where('cashfree_payment_id', '6555555555')->count());
    }

    private function contentionException(int $driverCode, string $detail): QueryException
    {
        $sqlState = $driverCode === 1213 ? '40001' : 'HY000';
        $previous = new \PDOException(sprintf('SQLSTATE[%s]: %s: %d %s', $sqlState, $sqlState === '40001' ? 'Serialization failure' : 'General error', $driverCode, $detail));
        $previous->errorInfo = [$sqlState, $driverCode, $detail];

        return new QueryException(
            'mysql',
            'select * from `reference_sequences` where `name` = ? limit 1 for update',
            ['sc'],
            $previous,
        );
    }
}
