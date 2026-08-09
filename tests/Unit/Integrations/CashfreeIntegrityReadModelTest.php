<?php

namespace Tests\Unit\Integrations;

use App\Enums\CashfreeWebhookFailureCategory;
use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use App\Models\User;
use App\ReadModels\Integrations\CashfreeIntegrityReadModel;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use App\Services\Cashfree\CashfreeWebhookReliabilityMetrics;
use App\Services\Operations\OperationsCashfreeHealthService;
use App\Services\Operations\OperationsIntegrationHealthService;
use App\Services\Operations\ProductionWatchdogService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashfreeIntegrityReadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $admin = User::factory()->create([
            'email' => 'cashfree-readmodel@radium.local',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        Cache::flush();
    }

    public function test_dto_metrics_match_owner_service_exactly(): void
    {
        $this->seedUnresolvedFailedPayment();

        $owner = app(CashfreePaymentIntegrityService::class);
        $readModel = app(CashfreeIntegrityReadModel::class);

        $classification = $owner->classifyFailedWebhooks();
        $paid = $owner->paidWithoutDeskOrderCount();
        $requiresAlert = $owner->requiresCashfreeHealthAlert();
        $metrics = $readModel->metrics();

        $this->assertSame($paid, $metrics->paidWithoutDeskOrderCount);
        $this->assertSame($classification->activeFailedWebhooks, $metrics->activeFailedWebhooks);
        $this->assertSame($classification->historicalResolvedFailures, $metrics->historicalResolvedFailures);
        $this->assertSame($classification->invalidEventFailures, $metrics->invalidEventFailures);
        $this->assertSame($classification->totalFailed, $metrics->totalFailedWebhooks);
        $this->assertSame($classification->countsByCategory, $metrics->countsByCategory);
        $this->assertSame($classification->affectedOrderIds, $metrics->affectedOrderIds);
        $this->assertSame($requiresAlert, $metrics->requiresAlert);
        $this->assertSame(1, $metrics->paidWithoutDeskOrderCount);
        $this->assertSame(1, $metrics->activeFailedWebhooks);
        $this->assertTrue($metrics->requiresAlert);
    }

    public function test_delegate_methods_match_owner_byte_for_byte_on_scalars(): void
    {
        $this->seedUnresolvedFailedPayment();

        $owner = app(CashfreePaymentIntegrityService::class);
        $readModel = app(CashfreeIntegrityReadModel::class);

        $this->assertSame($owner->paidWithoutDeskOrderCount(), $readModel->paidWithoutDeskOrderCount());
        $this->assertSame(
            $owner->reconciliationScalars()->successfulCashfreePayments,
            $readModel->reconciliationScalars()->successfulCashfreePayments,
        );
        $this->assertSame(
            $owner->reconciliationScalars()->missingOrdersCount,
            $readModel->reconciliationScalars()->missingOrdersCount,
        );
        $this->assertSame($owner->activeFailedWebhookCount(), $readModel->activeFailedWebhookCount());
        $this->assertSame($owner->historicalResolvedFailureCount(), $readModel->historicalResolvedFailureCount());
        $this->assertSame($owner->requiresCashfreeHealthAlert(), $readModel->requiresCashfreeHealthAlert());

        $ownerClassification = $owner->classifyFailedWebhooks();
        $readClassification = $readModel->classifyFailedWebhooks();

        $this->assertSame($ownerClassification->toArray(), $readClassification->toArray());
    }

    public function test_migrated_consumers_match_owner_integrity_kpis(): void
    {
        $this->seedUnresolvedFailedPayment();

        $owner = app(CashfreePaymentIntegrityService::class);
        $paid = $owner->paidWithoutDeskOrderCount();
        $active = $owner->activeFailedWebhookCount();
        $historical = $owner->historicalResolvedFailureCount();
        $requiresAlert = $owner->requiresCashfreeHealthAlert();

        $health = app(OperationsCashfreeHealthService::class)->widget(useCache: false);
        $card = collect(app(OperationsIntegrationHealthService::class)->cards())->firstWhere('key', 'cashfree');
        $reliability = app(CashfreeWebhookReliabilityMetrics::class)->snapshot();
        $alerts = app(ProductionWatchdogService::class)->collectCriticalAlerts();
        $paidAlert = collect($alerts)->firstWhere('key', 'cashfree:paid_missing_order');
        $failedAlert = collect($alerts)->firstWhere('key', 'cashfree:webhook_failures');

        $this->assertSame($paid, $health['paid_without_desk_order']);
        $this->assertSame($active, $health['active_failed_webhooks']);
        $this->assertSame($historical, $health['historical_resolved_failures']);
        $this->assertSame(! $requiresAlert, $health['is_healthy']);

        $this->assertSame($paid, $card['paid_without_desk_order']);
        $this->assertSame($active, $card['active_failed_webhooks']);
        $this->assertSame($historical, $card['historical_resolved_failures']);

        $this->assertSame($paid, $reliability->paidWithoutDeskOrderCount);
        $this->assertSame($active, $reliability->activeFailedWebhooks);
        $this->assertSame($historical, $reliability->historicalResolvedFailures);

        $this->assertNotNull($paidAlert);
        $this->assertSame($paid, $paidAlert->affectedCount);
        $this->assertNotNull($failedAlert);
        $this->assertSame($active, $failedAlert->affectedCount);
    }

    public function test_operations_cashfree_health_cache_is_reused_and_read_model_adds_no_cache(): void
    {
        $this->seedUnresolvedFailedPayment();

        $cacheKey = OperationsCashfreeHealthService::CACHE_KEY;
        $this->assertFalse(Cache::has($cacheKey));

        $first = app(OperationsCashfreeHealthService::class)->widget(useCache: true);
        $this->assertTrue(Cache::has($cacheKey));

        DB::enableQueryLog();
        DB::flushQueryLog();
        $second = app(OperationsCashfreeHealthService::class)->widget(useCache: true);
        $queryCount = count(DB::getQueryLog());

        foreach ([
            'is_healthy',
            'paid_without_desk_order',
            'active_failed_webhooks',
            'historical_resolved_failures',
            'invalid_event_failures',
            'total_failed_webhooks',
            'detail',
        ] as $field) {
            $this->assertSame($first[$field], $second[$field], "Cached widget mismatch on {$field}");
        }
        $this->assertSame(0, $queryCount, 'Cached widget must not re-query on hit.');

        $this->assertFalse(Cache::has('cashfree-integrity-read-model'));
        $this->assertFalse(Cache::has('CashfreeIntegrityReadModel'));
        $this->assertFalse(Cache::has('readmodel:cashfree-integrity'));
    }

    public function test_metrics_derives_alert_without_reentering_requires_alert_hydrate(): void
    {
        $this->seedUnresolvedFailedPayment();
        Cache::flush();

        $owner = app(CashfreePaymentIntegrityService::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $owner->classifyFailedWebhooks();
        $owner->paidWithoutDeskOrderCount();
        $classifyAndPaidQueryCount = count(DB::getQueryLog());

        Cache::flush();
        DB::flushQueryLog();
        $owner->classifyFailedWebhooks();
        $owner->paidWithoutDeskOrderCount();
        $owner->requiresCashfreeHealthAlert();
        $legacyTripleSequenceQueryCount = count(DB::getQueryLog());

        Cache::flush();
        DB::flushQueryLog();
        $metrics = app(CashfreeIntegrityReadModel::class)->metrics();
        $metricsQueryCount = count(DB::getQueryLog());

        $this->assertSame(
            $classifyAndPaidQueryCount,
            $metricsQueryCount,
            'metrics() must equal classify + paid only (alert derived from loaded counts).',
        );
        $this->assertLessThan(
            $legacyTripleSequenceQueryCount,
            $metricsQueryCount,
            'metrics() must not re-enter requiresCashfreeHealthAlert() hydrate/classify.',
        );
        $this->assertTrue($metrics->requiresAlert);
        $this->assertSame($owner->requiresCashfreeHealthAlert(), $metrics->requiresAlert);
    }

    public function test_widget_build_runs_paid_integrity_hydrate_once_and_preserves_alert(): void
    {
        $this->seedUnresolvedFailedPayment();
        Cache::flush();

        $expectedAlert = app(CashfreePaymentIntegrityService::class)->requiresCashfreeHealthAlert();
        Cache::flush();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $startedAt = hrtime(true);
        $widget = app(OperationsCashfreeHealthService::class)->widget(useCache: false);
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
        $queries = collect(DB::getQueryLog());

        $hydrateQueries = $queries->filter(fn (array $query): bool => $this->isSuccessfulPaymentHydrateQuery($query));
        $classifyQueries = $queries->filter(fn (array $query): bool => $this->isFailedWebhookClassifyQuery($query));

        $derivedAlert = $widget['paid_without_desk_order'] > 0
            || $widget['active_failed_webhooks'] > 0;

        // Legacy requiresCashfreeHealthAlert() would double both hydrate + classify SQL.
        $this->assertCount(1, $hydrateQueries, 'successful-payment hydrate SQL must run once per widget build.');
        $this->assertCount(1, $classifyQueries, 'failed-webhook classify SQL must run once per widget build.');
        $this->assertSame($expectedAlert, $derivedAlert, 'Widget alert semantics must match requiresCashfreeHealthAlert().');
        $this->assertFalse($widget['is_healthy']);
        $this->assertSame(1, $widget['paid_without_desk_order']);
        $this->assertSame(1, $widget['active_failed_webhooks']);
        $this->assertGreaterThan(0, $elapsedMs);
    }

    public function test_integration_cashfree_card_derives_alert_without_requires_alert_reentry(): void
    {
        $this->seedUnresolvedFailedPayment();
        Cache::flush();

        $expectedAlert = app(CashfreePaymentIntegrityService::class)->requiresCashfreeHealthAlert();
        Cache::flush();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $card = collect(app(OperationsIntegrationHealthService::class)->cards())->firstWhere('key', 'cashfree');
        $queries = collect(DB::getQueryLog());

        $hydrateQueries = $queries->filter(fn (array $query): bool => $this->isSuccessfulPaymentHydrateQuery($query));
        $classifyQueries = $queries->filter(fn (array $query): bool => $this->isFailedWebhookClassifyQuery($query));

        $derivedAlert = $card['paid_without_desk_order'] > 0
            || $card['active_failed_webhooks'] > 0;

        $this->assertCount(1, $hydrateQueries, 'Card build must not duplicate successful-payment hydrate.');
        $this->assertCount(1, $classifyQueries, 'Card build must not duplicate failed-webhook classify.');
        $this->assertSame($expectedAlert, $derivedAlert);
        $this->assertSame('failed', $card['status']);
        $this->assertSame(1, $card['paid_without_desk_order']);
        $this->assertSame(1, $card['active_failed_webhooks']);
    }

    public function test_alert_from_counts_matches_requires_cashfree_health_alert(): void
    {
        $this->seedUnresolvedFailedPayment();

        $owner = app(CashfreePaymentIntegrityService::class);
        $classification = $owner->classifyFailedWebhooks();
        $paid = $owner->paidWithoutDeskOrderCount();

        $this->assertSame(
            $owner->requiresCashfreeHealthAlert(),
            $owner->requiresCashfreeHealthAlertFromCounts($paid, $classification->activeFailedWebhooks),
        );
        $this->assertTrue($owner->requiresCashfreeHealthAlertFromCounts(1, 0));
        $this->assertTrue($owner->requiresCashfreeHealthAlertFromCounts(0, 1));
        $this->assertFalse($owner->requiresCashfreeHealthAlertFromCounts(0, 0));
    }

    public function test_admin_operations_cashfree_health_html_unchanged_across_repeat_requests(): void
    {
        $this->seedUnresolvedFailedPayment();

        $admin = User::query()->where('email', 'cashfree-readmodel@radium.local')->firstOrFail();

        $first = $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'health_cashfree']))
            ->assertOk()
            ->assertJsonStructure(['html' => ['cashfree_health']]);

        $second = $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'health_cashfree']))
            ->assertOk();

        $this->assertSame(
            $first->json('html.cashfree_health'),
            $second->json('html.cashfree_health'),
        );
        $this->assertNotSame('', trim((string) $first->json('html.cashfree_health')));
        $this->assertStringContainsString('Paid', (string) $first->json('html.cashfree_health'));
    }

    public function test_unresolved_failure_category_is_preserved(): void
    {
        $this->seedUnresolvedFailedPayment();

        $classification = app(CashfreeIntegrityReadModel::class)->classifyFailedWebhooks();

        $this->assertSame(CashfreeWebhookFailureCategory::Unresolved, $classification->records[0]->category);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function isSuccessfulPaymentHydrateQuery(array $query): bool
    {
        $sql = strtolower((string) ($query['query'] ?? ''));

        if (! str_contains($sql, 'cashfree_webhook_logs')
            || ! str_contains($sql, 'order by')
            || ! str_contains($sql, 'received_at')
            || ! str_contains($sql, 'id')
            || str_contains($sql, 'processing_status')
            || str_contains($sql, 'limit')
        ) {
            return false;
        }

        // Ignore anti-join id discovery; count the ordered hydrate (full-table or candidate whereIn).
        return ! str_contains($sql, 'not exists');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function isFailedWebhookClassifyQuery(array $query): bool
    {
        $sql = strtolower((string) ($query['query'] ?? ''));

        // classifyFailedWebhooks(): failed rows ordered by processed_at, id.
        return str_contains($sql, 'cashfree_webhook_logs')
            && str_contains($sql, 'processing_status')
            && str_contains($sql, 'order by')
            && str_contains($sql, 'processed_at')
            && str_contains($sql, 'id')
            && ! str_contains($sql, 'limit');
    }

    private function seedUnresolvedFailedPayment(): void
    {
        $payload = [
            'type' => 'PAYMENT_SUCCESS_WEBHOOK',
            'event_time' => '2023-08-01T11:16:10+05:30',
            'data' => [
                'order' => [
                    'order_id' => 'RD-H4-4-MISSING',
                    'order_amount' => 2,
                    'order_currency' => 'INR',
                ],
                'payment' => [
                    'cf_payment_id' => '5900000404',
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
            ],
        ];

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '5900000404',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'H4-4 integrity read model seed',
            'processed_at' => now(),
        ]);

        $this->assertSame(0, Order::query()->where('cashfree_payment_id', '5900000404')->count());
    }
}
