<?php

namespace Tests\Unit\Cashfree;

use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashfreePaymentIntegrityReconciliationScalarsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_reconciliation_scalars_match_reconcile_scalar_fields(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        Order::query()->create([
            'order_id' => 'RD-SCALAR-PRESENT',
            'cashfree_payment_id' => '7003000001',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->createFailedSuccessLog('7003000002', 'RD-SCALAR-MISSING');
        $this->createMatchedLog('7003000001', 'RD-SCALAR-PRESENT');
        $this->createFailedNonSuccessLog('7003000099', 'RD-SCALAR-NON-SUCCESS');

        $service = app(CashfreePaymentIntegrityService::class);
        $report = $service->reconcile();
        $scalars = $service->reconciliationScalars();

        $this->assertSame($report->successfulCashfreePayments, $scalars->successfulCashfreePayments);
        $this->assertSame($report->deskOrders, $scalars->deskOrders);
        $this->assertSame($report->missingOrdersCount, $scalars->missingOrdersCount);
        $this->assertSame($report->failedProcessing, $scalars->failedProcessing);
        $this->assertSame(2, $scalars->successfulCashfreePayments);
        $this->assertSame(1, $scalars->deskOrders);
        $this->assertSame(1, $scalars->missingOrdersCount);
        $this->assertSame(1, $scalars->failedProcessing);
    }

    public function test_reconciliation_scalars_avoids_full_universe_missing_assessment(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        for ($i = 1; $i <= 550; $i++) {
            $this->createMatchedOrderAndLog(
                $user,
                sprintf('7004%06d', $i),
                sprintf('RD-SCALAR-BENCH-%06d', $i),
                receivedAt: Carbon::parse('2024-08-01 10:00:00')->addSeconds($i),
            );
        }

        $this->createFailedSuccessLog(
            '7004999999',
            'RD-SCALAR-BENCH-MISSING',
            receivedAt: Carbon::parse('2024-08-01 12:00:00'),
        );

        $service = app(CashfreePaymentIntegrityService::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $scalars = $service->reconciliationScalars();
        $scalarQueries = collect(DB::getQueryLog());

        DB::flushQueryLog();
        $report = $service->reconcile();
        $reconcileQueries = collect(DB::getQueryLog());

        $this->assertSame(1, $scalars->missingOrdersCount);
        $this->assertSame($report->missingOrdersCount, $scalars->missingOrdersCount);
        $this->assertSame($report->successfulCashfreePayments, $scalars->successfulCashfreePayments);
        $this->assertSame($report->deskOrders, $scalars->deskOrders);
        $this->assertSame($report->failedProcessing, $scalars->failedProcessing);

        $this->assertLessThan(
            $this->orderLookupQueries($reconcileQueries)->count(),
            $this->orderLookupQueries($scalarQueries)->count(),
            'Scalar path should not run full-universe order lookup chunks',
        );
        $this->assertGreaterThanOrEqual(
            4,
            $this->orderLookupQueries($reconcileQueries)->count(),
            'Reconcile fixture should exercise chunked order lookups',
        );
        $this->assertTrue(
            $this->candidateUniverseQueries($scalarQueries)->isNotEmpty(),
            'Scalar path should use candidate discovery for missing count',
        );
    }

    public function test_failed_successful_payment_processing_count_matches_reconcile(): void
    {
        $this->createFailedSuccessLog('7003000100', 'RD-FAILED-SUCCESS');
        $this->createFailedNonSuccessLog('7003000101', 'RD-FAILED-NON-SUCCESS');

        $service = app(CashfreePaymentIntegrityService::class);

        $this->assertSame(
            $service->reconcile()->failedProcessing,
            $service->failedSuccessfulPaymentProcessingCount(),
        );
        $this->assertSame(1, $service->failedSuccessfulPaymentProcessingCount());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $queries
     * @return Collection<int, array<string, mixed>>
     */
    private function orderLookupQueries(Collection $queries): Collection
    {
        return $queries->filter(function (array $query): bool {
            $sql = strtolower((string) ($query['query'] ?? ''));

            return str_contains($sql, 'orders')
                && str_contains($sql, ' in (');
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $queries
     * @return Collection<int, array<string, mixed>>
     */
    private function candidateUniverseQueries(Collection $queries): Collection
    {
        return $queries->filter(function (array $query): bool {
            $sql = strtolower((string) ($query['query'] ?? ''));

            if (! str_contains($sql, 'cashfree_webhook_logs')) {
                return false;
            }

            return str_contains($sql, 'not exists')
                || str_contains($sql, 'cf_payment_id is null');
        });
    }

    private function createMatchedOrderAndLog(
        User $user,
        string $cfPaymentId,
        string $orderId,
        ?Carbon $receivedAt = null,
    ): void {
        Order::query()->create([
            'order_id' => $orderId,
            'cashfree_payment_id' => $cfPaymentId,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->createMatchedLog($cfPaymentId, $orderId, $receivedAt);
    }

    private function createMatchedLog(
        string $cfPaymentId,
        string $orderId,
        ?Carbon $receivedAt = null,
    ): void {
        $payload = $this->successfulPayload($cfPaymentId, $orderId);

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $cfPaymentId,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => $receivedAt ?? now(),
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'processed_at' => $receivedAt ?? now(),
        ]);
    }

    private function createFailedSuccessLog(
        string $cfPaymentId,
        string $orderId,
        ?Carbon $receivedAt = null,
    ): void {
        $payload = $this->successfulPayload($cfPaymentId, $orderId);

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $cfPaymentId,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => $receivedAt ?? now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'seeded failed success payment',
            'processed_at' => $receivedAt ?? now(),
        ]);
    }

    private function createFailedNonSuccessLog(string $cfPaymentId, string $orderId): void
    {
        $payload = [
            'type' => 'PAYMENT_FAILED_WEBHOOK',
            'data' => [
                'order' => ['order_id' => $orderId],
                'payment' => [
                    'cf_payment_id' => $cfPaymentId,
                    'payment_status' => 'FAILED',
                ],
            ],
        ];

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $cfPaymentId,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'seeded non-success failure',
            'processed_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulPayload(string $cfPaymentId, string $orderId): array
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
                ],
            ],
        ];
    }
}
