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

class CashfreePaymentIntegrityHydrateProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_successful_payment_hydrate_uses_minimum_column_projection(): void
    {
        $this->seedHydrateFixture();

        DB::enableQueryLog();
        DB::flushQueryLog();
        app(CashfreePaymentIntegrityService::class)->successfulPaymentLogsByCfPaymentId();
        $hydrateQuery = collect(DB::getQueryLog())->first(function (array $query): bool {
            $sql = strtolower((string) ($query['query'] ?? ''));

            return str_contains($sql, 'cashfree_webhook_logs')
                && str_contains($sql, 'order by')
                && str_contains($sql, 'received_at');
        });

        $this->assertNotNull($hydrateQuery);
        $sql = strtolower((string) $hydrateQuery['query']);
        $this->assertMatchesRegularExpression('/\bid\b/', $sql);
        $this->assertMatchesRegularExpression('/\bcf_payment_id\b/', $sql);
        $this->assertMatchesRegularExpression('/\brequest_payload\b/', $sql);
        $this->assertMatchesRegularExpression('/\breceived_at\b/', $sql);
        $this->assertDoesNotMatchRegularExpression('/\braw_body\b/', $sql);
        $this->assertDoesNotMatchRegularExpression('/\brequest_headers\b/', $sql);
        $this->assertDoesNotMatchRegularExpression('/\bprocessing_status\b/', $sql);
    }

    public function test_projected_hydrate_matches_full_row_integrity_results(): void
    {
        $this->seedHydrateFixture();

        $service = app(CashfreePaymentIntegrityService::class);
        $projected = $service->successfulPaymentLogsByCfPaymentId();
        $reference = $this->referenceSuccessfulPaymentLogsByCfPaymentId();

        $this->assertSuccessfulPaymentMapsMatch($reference, $projected);
        $this->assertSame(
            $this->referencePaidWithoutDeskOrderCount($reference),
            $service->paidWithoutDeskOrderCount(),
        );
        $this->assertSame(
            $this->referenceReconcileMissingCount($reference),
            $service->reconcile()->missingOrdersCount,
        );
    }

    public function test_projected_models_only_load_integrity_columns(): void
    {
        $this->seedHydrateFixture();

        $projected = app(CashfreePaymentIntegrityService::class)->successfulPaymentLogsByCfPaymentId();
        $earliest = $projected->get('5900PROJ-A');

        $this->assertNotNull($earliest);
        $this->assertSame(['id', 'cf_payment_id', 'request_payload', 'received_at'], array_keys($earliest->getAttributes()));
        $this->assertFalse(array_key_exists('raw_body', $earliest->getAttributes()));
        $this->assertFalse(array_key_exists('request_headers', $earliest->getAttributes()));
        $this->assertFalse(array_key_exists('processing_status', $earliest->getAttributes()));
    }

    /**
     * @param  Collection<string, CashfreeWebhookLog>  $reference
     * @param  Collection<string, CashfreeWebhookLog>  $projected
     */
    private function assertSuccessfulPaymentMapsMatch(Collection $reference, Collection $projected): void
    {
        $this->assertSame($reference->keys()->sort()->values()->all(), $projected->keys()->sort()->values()->all());

        foreach ($reference as $cfPaymentId => $referenceLog) {
            $projectedLog = $projected->get($cfPaymentId);
            $this->assertNotNull($projectedLog, "Missing projected log for {$cfPaymentId}");

            $this->assertSame($referenceLog->id, $projectedLog->id);
            $this->assertSame($referenceLog->cf_payment_id, $projectedLog->cf_payment_id);
            $this->assertSame($referenceLog->request_payload, $projectedLog->request_payload);
            $this->assertTrue(
                $referenceLog->received_at?->equalTo($projectedLog->received_at) ?? $projectedLog->received_at === null,
            );
        }
    }

    /**
     * Reference implementation using full-row hydrate (pre-optimization A behavior).
     *
     * @return Collection<string, CashfreeWebhookLog>
     */
    private function referenceSuccessfulPaymentLogsByCfPaymentId(): Collection
    {
        $parser = app(\App\Services\Cashfree\CashfreeWebhookPayloadParser::class);
        /** @var Collection<string, CashfreeWebhookLog> $byPaymentId */
        $byPaymentId = collect();

        CashfreeWebhookLog::query()
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->each(function (CashfreeWebhookLog $log) use ($byPaymentId, $parser): void {
                if (! $parser->isSuccessfulPayment($log->request_payload ?? [])) {
                    return;
                }

                $cfPaymentId = $parser->cfPaymentId($log->request_payload ?? []) ?? $log->cf_payment_id;

                if ($cfPaymentId === null) {
                    return;
                }

                if (! $byPaymentId->has($cfPaymentId)) {
                    $byPaymentId->put($cfPaymentId, $log);
                }
            });

        return $byPaymentId;
    }

    /**
     * @param  Collection<string, CashfreeWebhookLog>  $successfulPayments
     */
    private function referencePaidWithoutDeskOrderCount(Collection $successfulPayments): int
    {
        $service = app(CashfreePaymentIntegrityService::class);
        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('missingPaidOrders');
        $method->setAccessible(true);

        return $method->invoke($service, $successfulPayments)->count();
    }

    /**
     * @param  Collection<string, CashfreeWebhookLog>  $successfulPayments
     */
    private function referenceReconcileMissingCount(Collection $successfulPayments): int
    {
        return $this->referencePaidWithoutDeskOrderCount($successfulPayments);
    }

    private function seedHydrateFixture(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        Order::query()->create([
            'order_id' => 'RD-PROJ-PRESENT',
            'cashfree_payment_id' => '5900PROJ-B',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $payloadA = $this->successfulPayload('5900PROJ-A', 'RD-PROJ-MISSING-A');
        $payloadB = $this->successfulPayload('5900PROJ-B', 'RD-PROJ-PRESENT');
        $payloadLaterA = $this->successfulPayload('5900PROJ-A', 'RD-PROJ-MISSING-A-LATER');
        $payloadMalformed = ['unexpected' => 'shape'];
        $payloadNonSuccess = [
            'type' => 'PAYMENT_FAILED_WEBHOOK',
            'data' => [
                'payment' => ['cf_payment_id' => '5900PROJ-FAIL', 'payment_status' => 'FAILED'],
                'order' => ['order_id' => 'RD-PROJ-FAIL'],
            ],
        ];

        $this->createLog([
            'cf_payment_id' => '5900PROJ-A',
            'request_payload' => $payloadA,
            'received_at' => Carbon::parse('2024-01-01 10:00:00'),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'raw_body' => str_repeat('A', 50_000),
            'request_headers' => ['x-heavy' => str_repeat('h', 10_000)],
        ]);
        $this->createLog([
            'cf_payment_id' => '5900PROJ-A',
            'request_payload' => $payloadLaterA,
            'received_at' => Carbon::parse('2024-01-02 10:00:00'),
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'raw_body' => str_repeat('B', 50_000),
            'request_headers' => ['x-heavy' => str_repeat('h', 10_000)],
        ]);
        $this->createLog([
            'cf_payment_id' => '5900PROJ-B',
            'request_payload' => $payloadB,
            'received_at' => Carbon::parse('2024-01-01 11:00:00'),
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'raw_body' => str_repeat('C', 50_000),
            'request_headers' => ['x-heavy' => str_repeat('h', 10_000)],
        ]);
        $this->createLog([
            'cf_payment_id' => null,
            'request_payload' => $payloadMalformed,
            'received_at' => Carbon::parse('2024-01-01 12:00:00'),
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
            'raw_body' => str_repeat('D', 50_000),
            'request_headers' => ['x-heavy' => str_repeat('h', 10_000)],
        ]);
        $this->createLog([
            'cf_payment_id' => '5900PROJ-FAIL',
            'request_payload' => $payloadNonSuccess,
            'received_at' => Carbon::parse('2024-01-01 13:00:00'),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'raw_body' => str_repeat('E', 50_000),
            'request_headers' => ['x-heavy' => str_repeat('h', 10_000)],
        ]);
        $this->createLog([
            'cf_payment_id' => '5900PROJ-COLUMN-ONLY',
            'request_payload' => [],
            'received_at' => Carbon::parse('2024-01-01 14:00:00'),
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
            'raw_body' => str_repeat('F', 50_000),
            'request_headers' => ['x-heavy' => str_repeat('h', 10_000)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLog(array $overrides): CashfreeWebhookLog
    {
        $payload = $overrides['request_payload'] ?? $this->successfulPayload('default', 'RD-DEFAULT');

        return CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $overrides['cf_payment_id'] ?? 'default',
            'request_payload' => $payload,
            'request_headers' => $overrides['request_headers'] ?? ['content-type' => 'application/json'],
            'raw_body' => $overrides['raw_body'] ?? json_encode($payload),
            'received_at' => $overrides['received_at'] ?? now(),
            'processing_status' => $overrides['processing_status'] ?? CashfreeWebhookLog::STATUS_RECEIVED,
            'processing_error' => $overrides['processing_error'] ?? null,
            'processed_at' => $overrides['processed_at'] ?? null,
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
