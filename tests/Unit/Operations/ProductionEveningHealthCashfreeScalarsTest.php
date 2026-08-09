<?php

namespace Tests\Unit\Operations;

use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use App\Models\User;
use App\ReadModels\Integrations\CashfreeIntegrityReadModel;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\Operations\ProductionEveningHealthService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class ProductionEveningHealthCashfreeScalarsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-07-08 20:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_evening_health_uses_scalar_reconciliation_values(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        Order::query()->create([
            'order_id' => 'RD-EVENING-PRESENT',
            'cashfree_payment_id' => '7005000001',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->createFailedSuccessLog('7005000002', 'RD-EVENING-MISSING');
        $this->createMatchedLog('7005000001', 'RD-EVENING-PRESENT');

        $expected = app(CashfreeIntegrityReadModel::class)->reconciliationScalars();
        $summary = app(ProductionEveningHealthService::class)->build()['cashfree_reconciliation'];

        $this->assertSame($expected->successfulCashfreePayments, $summary['successful_payments']);
        $this->assertSame($expected->deskOrders, $summary['desk_orders']);
        $this->assertSame($expected->missingOrdersCount, $summary['missing_orders']);
        $this->assertSame($expected->failedProcessing, $summary['failed_processing']);
        $this->assertSame(2, $summary['successful_payments']);
        $this->assertSame(1, $summary['desk_orders']);
        $this->assertSame(1, $summary['missing_orders']);
        $this->assertSame(1, $summary['failed_processing']);
    }

    public function test_evening_health_does_not_invoke_reconcile(): void
    {
        $this->partialMock(CashfreePaymentIntegrityService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')->never();
        });

        $summary = app(ProductionEveningHealthService::class)->build()['cashfree_reconciliation'];

        $this->assertArrayHasKey('successful_payments', $summary);
        $this->assertArrayHasKey('desk_orders', $summary);
        $this->assertArrayHasKey('missing_orders', $summary);
        $this->assertArrayHasKey('failed_processing', $summary);
    }

    public function test_evening_health_avoids_full_universe_missing_assessment_queries(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        for ($i = 1; $i <= 80; $i++) {
            Order::query()->create([
                'order_id' => sprintf('RD-EVENING-BENCH-%06d', $i),
                'cashfree_payment_id' => sprintf('7006%06d', $i),
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            $this->createMatchedLog(
                sprintf('7006%06d', $i),
                sprintf('RD-EVENING-BENCH-%06d', $i),
                Carbon::parse('2024-08-02 10:00:00')->addSeconds($i),
            );
        }

        $this->createFailedSuccessLog(
            '7006999999',
            'RD-EVENING-BENCH-MISSING',
            Carbon::parse('2024-08-02 12:00:00'),
        );

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(ProductionEveningHealthService::class)->build();
        $queries = collect(DB::getQueryLog());

        $this->assertLessThan(
            20,
            $this->fullUniverseAssessmentQueries($queries)->count(),
            'Evening health must not run full-universe missing assessment chunk queries',
        );
        $this->assertTrue(
            $this->candidateUniverseQueries($queries)->isNotEmpty(),
            'Evening health should use candidate discovery for missing count',
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $queries
     * @return Collection<int, array<string, mixed>>
     */
    private function fullUniverseAssessmentQueries(Collection $queries): Collection
    {
        return $queries->filter(function (array $query): bool {
            $sql = strtolower((string) ($query['query'] ?? ''));

            if (! str_contains($sql, 'where')) {
                return false;
            }

            return str_contains($sql, 'cashfree_payment_id')
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
