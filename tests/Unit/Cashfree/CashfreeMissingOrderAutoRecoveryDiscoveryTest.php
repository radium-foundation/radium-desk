<?php

namespace Tests\Unit\Cashfree;

use App\Enums\CashfreeHistoricalRecoveryDisposition;
use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeHistoricalRecoveryService;
use App\Services\Cashfree\CashfreeMissingOrderAutoRecoveryService;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

class CashfreeMissingOrderAutoRecoveryDiscoveryTest extends TestCase
{
    use EnsuresCashfreeSystemUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.verify_signature' => false,
            'cashfree.auto_recover.enabled' => true,
            'cashfree.auto_recover.max_per_run' => 20,
            'radiumbox.enabled' => false,
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->ensureCashfreeSystemUser();
        $this->seed(SettingsSeeder::class);
    }

    public function test_found_zero_does_not_hydrate_full_webhook_table(): void
    {
        $payload = $this->successfulPayload('6999000001', 'RD-ZERO-1');
        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '6999000001',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(CashfreeMissingOrderAutoRecoveryService::class)->run();
        $queries = collect(DB::getQueryLog());

        $this->assertSame(0, $result->found);
        $this->assertCount(0, $this->fullTableSuccessfulPaymentHydrateQueries($queries));
    }

    public function test_discovery_recoverable_ids_match_reconcile_reference(): void
    {
        $this->seedRecoverableFixtures();

        $this->assertSame(
            $this->legacyReconcileRecoverableWebhookIds(),
            $this->discoveryRecoverableWebhookIds(),
        );
    }

    public function test_stuck_received_success_webhook_is_included_in_recoverable_discovery(): void
    {
        $payload = $this->successfulPayload('6999000101', 'RD-RECEIVED-1');

        $log = CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '6999000101',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => Carbon::parse('2024-02-01 09:00:00'),
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
        ]);

        $this->assertContains($log->id, $this->legacyReconcileRecoverableWebhookIds());
        $this->assertContains($log->id, $this->discoveryRecoverableWebhookIds());
    }

    public function test_max_twenty_recoveries_is_preserved(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->createFailedLog(
                cfPaymentId: sprintf('6999001%03d', $i),
                orderId: sprintf('RD-CAP-%03d', $i),
                receivedAt: Carbon::parse('2024-03-01 10:00:00')->addMinutes($i),
            );
        }

        $preview = app(CashfreeMissingOrderAutoRecoveryService::class)->previewRecoverableCandidates(20);

        $this->assertCount(20, $preview);
        $this->assertSame(
            array_slice($this->discoveryRecoverableWebhookIds(), 0, 20),
            $preview->map(fn (array $entry): int => $entry['log']->id)->all(),
        );
    }

    public function test_non_recoverable_candidates_are_not_selected_for_auto_recovery(): void
    {
        $payload = $this->successfulPayload('6999000201', 'RD-EXISTS-1');
        $user = User::query()->firstOrFail();

        Order::query()->create([
            'order_id' => 'RD-EXISTS-1',
            'cashfree_payment_id' => '6999000201',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->createFailedLog('6999000201', 'RD-EXISTS-1');
        $this->createFailedLog('6999000202', 'RD-UNSAFE-1', mutatePayload: function (array &$payload): void {
            unset($payload['data']['order']['order_id']);
        });

        $preview = app(CashfreeMissingOrderAutoRecoveryService::class)->previewRecoverableCandidates();

        $this->assertCount(0, $preview);
        $this->assertSame([], $this->discoveryRecoverableWebhookIds());
    }

    public function test_mixed_case_existing_business_order_is_not_recoverable(): void
    {
        $user = User::query()->firstOrFail();

        Order::query()->create([
            'order_id' => 'rd3483568',
            'cashfree_payment_id' => null,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->createFailedLog('6206001295', 'RD3483568');

        $preview = app(CashfreeMissingOrderAutoRecoveryService::class)->previewRecoverableCandidates();

        $this->assertCount(0, $preview);
        $this->assertSame([], $this->discoveryRecoverableWebhookIds());
    }

    public function test_candidate_ordering_follows_received_at_then_id(): void
    {
        $this->createFailedLog('6999000303', 'RD-ORDER-3', receivedAt: Carbon::parse('2024-04-01 12:00:00'));
        $this->createFailedLog('6999000301', 'RD-ORDER-1', receivedAt: Carbon::parse('2024-04-01 10:00:00'));
        $this->createFailedLog('6999000302', 'RD-ORDER-2', receivedAt: Carbon::parse('2024-04-01 11:00:00'));

        $orderedIds = app(CashfreeMissingOrderAutoRecoveryService::class)
            ->previewRecoverableCandidates()
            ->map(fn (array $entry): int => $entry['log']->id)
            ->all();

        $this->assertSame(
            CashfreeWebhookLog::query()
                ->whereIn('cf_payment_id', ['6999000301', '6999000302', '6999000303'])
                ->orderBy('received_at')
                ->orderBy('id')
                ->pluck('id')
                ->all(),
            $orderedIds,
        );
    }

    public function test_dry_run_uses_cheap_discovery_without_full_hydrate(): void
    {
        $this->createFailedLog('6999000401', 'RD-DRY-1');

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->artisan('cashfree:auto-recover-missing', ['--dry-run' => true])
            ->expectsOutputToContain('6999000401')
            ->assertSuccessful();

        $this->assertCount(0, $this->fullTableSuccessfulPaymentHydrateQueries(collect(DB::getQueryLog())));
    }

    /**
     * @return list<int>
     */
    private function legacyReconcileRecoverableWebhookIds(): array
    {
        return collect(app(CashfreePaymentIntegrityService::class)->reconcile()->missingOrders)
            ->filter(fn ($record): bool => $record->recoveryEligibility === CashfreeHistoricalRecoveryDisposition::Recoverable)
            ->pluck('webhookLogId')
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function discoveryRecoverableWebhookIds(): array
    {
        return app(CashfreeHistoricalRecoveryService::class)
            ->autoRecoveryCandidateAssessments()
            ->filter(fn (array $entry): bool => $entry['disposition'] === CashfreeHistoricalRecoveryDisposition::Recoverable)
            ->map(fn (array $entry): int => $entry['log']->id)
            ->sort()
            ->values()
            ->all();
    }

    private function seedRecoverableFixtures(): void
    {
        $this->createFailedLog('6999000501', 'RD-FIX-1', receivedAt: Carbon::parse('2024-05-01 10:00:00'));
        $this->createFailedLog('6999000502', 'RD-FIX-2', receivedAt: Carbon::parse('2024-05-01 11:00:00'));

        $receivedPayload = $this->successfulPayload('6999000503', 'RD-FIX-3');
        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '6999000503',
            'request_payload' => $receivedPayload,
            'request_headers' => [],
            'raw_body' => json_encode($receivedPayload),
            'received_at' => Carbon::parse('2024-05-01 09:30:00'),
            'processing_status' => CashfreeWebhookLog::STATUS_RECEIVED,
        ]);

        $laterDuplicatePayload = $this->successfulPayload('6999000501', 'RD-FIX-1-LATER');
        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '6999000501',
            'request_payload' => $laterDuplicatePayload,
            'request_headers' => [],
            'raw_body' => json_encode($laterDuplicatePayload),
            'received_at' => Carbon::parse('2024-05-01 12:00:00'),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'duplicate later failure',
            'processed_at' => Carbon::parse('2024-05-01 12:00:00'),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $queries
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fullTableSuccessfulPaymentHydrateQueries(\Illuminate\Support\Collection $queries): \Illuminate\Support\Collection
    {
        return $queries->filter(function (array $query): bool {
            $sql = strtolower((string) ($query['query'] ?? ''));

            return str_contains($sql, 'cashfree_webhook_logs')
                && str_contains($sql, 'order by')
                && str_contains($sql, 'received_at')
                && str_contains($sql, 'id')
                && ! str_contains($sql, 'processing_status');
        });
    }

    /**
     * @param  null|callable(array<string, mixed>&array): void  $mutatePayload
     */
    private function createFailedLog(
        string $cfPaymentId,
        string $orderId,
        ?Carbon $receivedAt = null,
        ?callable $mutatePayload = null,
    ): CashfreeWebhookLog {
        $payload = $this->successfulPayload($cfPaymentId, $orderId);

        if ($mutatePayload !== null) {
            $mutatePayload($payload);
        }

        return CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $cfPaymentId,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => $receivedAt ?? now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'seeded failure',
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
