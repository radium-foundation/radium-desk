<?php

namespace Tests\Unit\Cashfree;

use App\Enums\CashfreeHistoricalRecoveryDisposition;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
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

class CashfreePaymentIntegrityCandidatePaidWithoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_zero_candidates_yields_zero_paid_without(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->createMatchedOrderAndLog($user, '7001000001', 'RD-ZERO-CAND-1');

        $service = app(CashfreePaymentIntegrityService::class);

        $this->assertSame(0, $service->candidateSuccessfulPaymentLogsByCfPaymentId()->count());
        $this->assertSame(0, $service->candidatePaidWithoutDeskOrders()->count());
        $this->assertSame(0, $service->paidWithoutDeskOrderCount());
        $this->assertSame(['count' => 0, 'order_ids' => []], $service->missingPaidOrderSample(5));
    }

    public function test_real_missing_candidate_is_counted(): void
    {
        $this->createFailedSuccessLog('7001000002', 'RD-MISSING-CAND-1');

        $service = app(CashfreePaymentIntegrityService::class);
        $missing = $service->candidatePaidWithoutDeskOrders();

        $this->assertSame(1, $missing->count());
        $this->assertSame(CashfreeHistoricalRecoveryDisposition::Recoverable, $missing->first()['disposition']);
        $this->assertSame(1, $service->paidWithoutDeskOrderCount());
        $this->assertSame(
            ['count' => 1, 'order_ids' => ['RD-MISSING-CAND-1']],
            $service->missingPaidOrderSample(5),
        );
    }

    public function test_already_existing_order_id_is_not_counted_as_missing(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        Order::query()->create([
            'order_id' => 'RD-ORDER-ID-EXISTS',
            'cashfree_payment_id' => null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->createFailedSuccessLog('7001000003', 'RD-ORDER-ID-EXISTS');

        $service = app(CashfreePaymentIntegrityService::class);

        $this->assertSame(1, $service->candidateSuccessfulPaymentLogsByCfPaymentId()->count());
        $this->assertSame(0, $service->candidatePaidWithoutDeskOrders()->count());
        $this->assertSame(0, $service->paidWithoutDeskOrderCount());
    }

    public function test_processed_sibling_assessment_excludes_candidate(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => 'RD-SIBLING-ORDER',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-SIBLING-1',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Sibling processed webhook',
            'description' => 'Sibling processed webhook.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $this->createFailedSuccessLog(
            '7001000004',
            'RD-SIBLING-MISSING',
            receivedAt: Carbon::parse('2024-06-01 09:00:00'),
        );

        $payload = $this->successfulPayload('7001000004', 'RD-SIBLING-MISSING');
        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '7001000004',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => Carbon::parse('2024-06-01 10:00:00'),
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'processed_at' => Carbon::parse('2024-06-01 10:00:00'),
            'incident_id' => $incident->id,
        ]);

        $service = app(CashfreePaymentIntegrityService::class);

        $this->assertSame(1, $service->candidateSuccessfulPaymentLogsByCfPaymentId()->count());
        $this->assertSame(0, $service->candidatePaidWithoutDeskOrders()->count());
        $this->assertSame(0, $service->paidWithoutDeskOrderCount());
    }

    public function test_null_cf_payment_id_column_with_payload_payment_id_is_included(): void
    {
        $payload = $this->successfulPayload('7001000005', 'RD-NULL-COL-1');

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => null,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => Carbon::parse('2024-06-02 10:00:00'),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'null column fixture',
            'processed_at' => Carbon::parse('2024-06-02 10:00:00'),
        ]);

        $service = app(CashfreePaymentIntegrityService::class);
        $candidates = $service->candidateSuccessfulPaymentLogsByCfPaymentId();

        $this->assertTrue($candidates->has('7001000005'));
        $this->assertSame(1, $service->paidWithoutDeskOrderCount());
        $this->assertSame(
            ['count' => 1, 'order_ids' => ['RD-NULL-COL-1']],
            $service->missingPaidOrderSample(5),
        );
    }

    public function test_earliest_success_per_cf_payment_id_is_preserved(): void
    {
        $earliest = $this->createFailedSuccessLog(
            '7001000006',
            'RD-EARLIEST-1',
            receivedAt: Carbon::parse('2024-06-03 08:00:00'),
        );
        $this->createFailedSuccessLog(
            '7001000006',
            'RD-EARLIEST-2-LATER',
            receivedAt: Carbon::parse('2024-06-03 09:00:00'),
        );

        $service = app(CashfreePaymentIntegrityService::class);
        $candidate = $service->candidateSuccessfulPaymentLogsByCfPaymentId()->get('7001000006');
        $missing = $service->candidatePaidWithoutDeskOrders();

        $this->assertNotNull($candidate);
        $this->assertSame($earliest->id, $candidate->id);
        $this->assertSame(1, $missing->count());
        $this->assertSame($earliest->id, $missing->first()['log']->id);
        $this->assertSame(
            ['count' => 1, 'order_ids' => ['RD-EARLIEST-1']],
            $service->missingPaidOrderSample(5),
        );
    }

    public function test_candidate_path_equals_full_universe_missing_result(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->createMatchedOrderAndLog($user, '7001000010', 'RD-EQ-PRESENT');
        $this->createFailedSuccessLog('7001000011', 'RD-EQ-MISSING');
        $this->createFailedSuccessLog(
            '7001000011',
            'RD-EQ-MISSING-LATER',
            receivedAt: Carbon::parse('2024-06-04 12:00:00'),
        );

        Order::query()->create([
            'order_id' => 'RD-EQ-ORDER-EXISTS',
            'cashfree_payment_id' => null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $this->createFailedSuccessLog('7001000012', 'RD-EQ-ORDER-EXISTS');

        $nullPayload = $this->successfulPayload('7001000013', 'RD-EQ-NULL-COL');
        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => null,
            'request_payload' => $nullPayload,
            'request_headers' => [],
            'raw_body' => json_encode($nullPayload),
            'received_at' => Carbon::parse('2024-06-04 13:00:00'),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'null column equivalence',
            'processed_at' => Carbon::parse('2024-06-04 13:00:00'),
        ]);

        $malformedPayload = ['unexpected' => 'shape'];
        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => null,
            'request_payload' => $malformedPayload,
            'request_headers' => [],
            'raw_body' => json_encode($malformedPayload),
            'received_at' => Carbon::parse('2024-06-04 14:00:00'),
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
        ]);

        $service = app(CashfreePaymentIntegrityService::class);
        $fullMissing = $this->fullUniverseMissingPaidOrders($service);
        $candidateMissing = $service->candidatePaidWithoutDeskOrders();

        $this->assertSame(
            $this->missingFingerprint($fullMissing),
            $this->missingFingerprint($candidateMissing),
            'Candidate assessed missing set must equal full-universe missing set',
        );
        $this->assertSame($fullMissing->count(), $service->paidWithoutDeskOrderCount());

        $fullSample = $this->sampleFromMissing($fullMissing, 5);
        $candidateSample = $service->missingPaidOrderSample(5);
        $this->assertSame($fullSample['count'], $candidateSample['count']);
        $this->assertEqualsCanonicalizing($fullSample['order_ids'], $candidateSample['order_ids']);
    }

    public function test_candidate_paid_without_avoids_full_table_hydrate(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        for ($i = 1; $i <= 120; $i++) {
            $this->createMatchedOrderAndLog(
                $user,
                sprintf('7002%06d', $i),
                sprintf('RD-BENCH-%06d', $i),
                receivedAt: Carbon::parse('2024-07-01 10:00:00')->addSeconds($i),
            );
        }

        $this->createFailedSuccessLog(
            '7002999999',
            'RD-BENCH-MISSING',
            receivedAt: Carbon::parse('2024-07-01 12:00:00'),
        );

        $totalWebhookRows = CashfreeWebhookLog::query()->count();
        $this->assertSame(121, $totalWebhookRows);

        $service = app(CashfreePaymentIntegrityService::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $count = $service->paidWithoutDeskOrderCount();
        $paidQueries = collect(DB::getQueryLog());

        DB::flushQueryLog();
        $sample = $service->missingPaidOrderSample(5);
        $sampleQueries = collect(DB::getQueryLog());

        $this->assertSame(1, $count);
        $this->assertSame(['count' => 1, 'order_ids' => ['RD-BENCH-MISSING']], $sample);

        $this->assertCount(0, $this->unconstrainedWebhookHydrateQueries($paidQueries));
        $this->assertCount(0, $this->unconstrainedWebhookHydrateQueries($sampleQueries));
        $this->assertTrue(
            $this->candidateUniverseQueries($paidQueries)->isNotEmpty(),
            'Expected anti-join / null-column candidate SQL for paidWithoutDeskOrderCount',
        );
        $this->assertTrue(
            $this->candidateUniverseQueries($sampleQueries)->isNotEmpty(),
            'Expected anti-join / null-column candidate SQL for missingPaidOrderSample',
        );

        $candidateMapSize = $service->candidateSuccessfulPaymentLogsByCfPaymentId()->count();
        $fullMapSize = $service->successfulPaymentLogsByCfPaymentId()->count();

        $this->assertSame(1, $candidateMapSize);
        $this->assertSame(121, $fullMapSize);
        $this->assertLessThan($totalWebhookRows, $candidateMapSize + 5);
    }

    public function test_reconcile_still_uses_full_universe_path(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->createMatchedOrderAndLog($user, '7001000020', 'RD-RECON-PRESENT');
        $this->createFailedSuccessLog('7001000021', 'RD-RECON-MISSING');

        $service = app(CashfreePaymentIntegrityService::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $report = $service->reconcile();
        $queries = collect(DB::getQueryLog());

        $this->assertSame(1, $report->paidWithoutDeskOrderCount);
        $this->assertSame(1, $service->paidWithoutDeskOrderCount());
        $this->assertTrue(
            $this->unconstrainedWebhookHydrateQueries($queries)->isNotEmpty(),
            'reconcile() must keep full historical successful-payment hydrate',
        );
    }

    /**
     * @return Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>
     */
    private function fullUniverseMissingPaidOrders(CashfreePaymentIntegrityService $service): Collection
    {
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('missingPaidOrders');
        $method->setAccessible(true);

        return $method->invoke($service, $service->successfulPaymentLogsByCfPaymentId());
    }

    /**
     * @param  Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>  $missing
     * @return list<array{webhook_log_id: int, cf_payment_id: string, disposition: string, reason: string}>
     */
    private function missingFingerprint(Collection $missing): array
    {
        return $missing
            ->map(function (array $entry): array {
                $log = $entry['log'];

                return [
                    'webhook_log_id' => (int) $log->id,
                    'cf_payment_id' => (string) ($log->cf_payment_id ?? ''),
                    'disposition' => $entry['disposition']->value,
                    'reason' => $entry['reason'],
                ];
            })
            ->sortBy('webhook_log_id')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array{log: CashfreeWebhookLog, disposition: CashfreeHistoricalRecoveryDisposition, reason: string}>  $missing
     * @return array{count: int, order_ids: list<string>}
     */
    private function sampleFromMissing(Collection $missing, int $limit): array
    {
        $service = app(CashfreePaymentIntegrityService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('toMissingRecord');
        $method->setAccessible(true);

        $orderIds = $missing
            ->take(max(0, $limit))
            ->map(function (array $entry) use ($method, $service): string {
                $record = $method->invoke($service, $entry);

                return (string) ($record->orderId ?? $record->cfPaymentId ?? '');
            })
            ->filter()
            ->values()
            ->all();

        return [
            'count' => $missing->count(),
            'order_ids' => $orderIds,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $queries
     * @return Collection<int, array<string, mixed>>
     */
    private function unconstrainedWebhookHydrateQueries(Collection $queries): Collection
    {
        return $queries->filter(function (array $query): bool {
            $sql = strtolower((string) ($query['query'] ?? ''));

            if (! str_contains($sql, 'cashfree_webhook_logs')) {
                return false;
            }

            if (! str_contains($sql, 'order by') || ! str_contains($sql, 'received_at')) {
                return false;
            }

            return ! str_contains($sql, ' where ');
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
                || str_contains($sql, 'cf_payment_id" is null')
                || str_contains($sql, 'cf_payment_id` is null')
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
    ): CashfreeWebhookLog {
        $payload = $this->successfulPayload($cfPaymentId, $orderId);

        return CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $cfPaymentId,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => $receivedAt ?? now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'seeded missing paid order',
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
