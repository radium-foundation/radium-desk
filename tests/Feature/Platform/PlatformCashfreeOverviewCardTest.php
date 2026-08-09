<?php

namespace Tests\Feature\Platform;

use App\Enums\IntegrationHealthStatus;
use App\Infrastructure\IntegrationHealth\Probes\CashfreeIntegrationHealthProbe;
use App\Models\CashfreeWebhookLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use App\Services\Operations\OperationsCashfreeHealthService;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PlatformCashfreeOverviewCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cashfree.verify_signature' => false]);
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_platform_overview_card_matches_full_widget_for_healthy_state(): void
    {
        $user = $this->createCashfreeSystemUser();
        Order::query()->create([
            'order_id' => 'RD-PLATFORM-HEALTHY',
            'cashfree_payment_id' => '7008000001',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $this->createProcessedLog('7008000001', 'RD-PLATFORM-HEALTHY');

        $this->assertCardEquivalentToFullWidget();
    }

    public function test_platform_overview_card_matches_full_widget_for_paid_without_order(): void
    {
        $this->createCashfreeSystemUser();
        $this->createFailedSuccessLog('7008000002', 'RD-PLATFORM-MISSING');

        $this->assertCardEquivalentToFullWidget();
    }

    public function test_platform_overview_card_matches_full_widget_for_active_failed_webhook(): void
    {
        $this->createCashfreeSystemUser();
        $this->seedUnresolvedFailedPayment();

        $this->assertCardEquivalentToFullWidget();
    }

    public function test_platform_overview_card_matches_full_widget_for_configuration_failure(): void
    {
        config(['cashfree.system_user_email' => 'missing-user@radium.local']);

        $this->assertCardEquivalentToFullWidget();
    }

    public function test_platform_overview_card_matches_full_widget_for_mixed_paid_missing_and_active_failed(): void
    {
        $this->createCashfreeSystemUser();
        $this->createFailedSuccessLog('7008000003', 'RD-PLATFORM-MIXED-MISSING');
        $this->seedUnresolvedFailedPayment();

        $this->assertCardEquivalentToFullWidget();
    }

    public function test_platform_overview_card_matches_full_widget_for_historical_resolved_only(): void
    {
        $user = $this->createCashfreeSystemUser();
        $order = Order::query()->create([
            'order_id' => 'RD-PLATFORM-HIST',
            'cashfree_payment_id' => '7008000004',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-PLATFORM-HIST',
            'category' => 'General',
            'source' => 'call',
            'title' => 'Historical resolved',
            'description' => 'Historical resolved.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $payload = $this->successfulPayload('7008000004', 'RD-PLATFORM-HIST');
        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => '7008000004',
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now()->subMinute(),
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'processed_at' => now()->subMinute(),
            'incident_id' => $incident->id,
        ]);
        $this->createFailedSuccessLog('7008000004', 'RD-PLATFORM-HIST-DUP');

        $this->assertCardEquivalentToFullWidget();
    }

    public function test_cache_miss_avoids_integration_probe_and_seeds_operations_cache(): void
    {
        $this->createCashfreeSystemUser();
        $this->seedUnresolvedFailedPayment();

        $probe = Mockery::mock(CashfreeIntegrationHealthProbe::class);
        $probe->shouldReceive('probe')->never();
        $this->app->instance(CashfreeIntegrationHealthProbe::class, $probe);

        $this->assertFalse(Cache::has(OperationsCashfreeHealthService::CACHE_KEY));

        DB::enableQueryLog();
        DB::flushQueryLog();

        $item = app(PlatformIntegrationHealthOverviewService::class)->refreshItem('cashfree');
        $queries = collect(DB::getQueryLog());

        $this->assertTrue(Cache::has(OperationsCashfreeHealthService::CACHE_KEY));
        $this->assertSame(IntegrationHealthStatus::Critical->value, $item['status']);
        $this->assertStringContainsString('paid payment(s) missing Desk orders', (string) $item['detail']);
        $this->assertSame(
            0,
            $this->probeStyleClassifyQueries($queries)->count(),
            'Platform overview card must not run probe-style per-failed-log classify queries',
        );
    }

    public function test_cache_hit_does_not_rebuild_overview_card_or_invoke_probe(): void
    {
        Cache::put(OperationsCashfreeHealthService::CACHE_KEY, [
            'is_healthy' => true,
            'detail' => 'Payment webhooks are healthy.',
            'paid_without_desk_order' => 0,
            'active_failed_webhooks' => 0,
        ], now()->addSeconds(30));

        $probe = Mockery::mock(CashfreeIntegrationHealthProbe::class);
        $probe->shouldReceive('probe')->never();
        $this->app->instance(CashfreeIntegrationHealthProbe::class, $probe);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $item = app(PlatformIntegrationHealthOverviewService::class)->refreshItem('cashfree');

        $this->assertSame(IntegrationHealthStatus::Healthy->value, $item['status']);
        $this->assertSame('Payment webhooks are healthy.', $item['detail']);
        $this->assertSame(0, count(DB::getQueryLog()));
    }

    private function assertCardEquivalentToFullWidget(): void
    {
        Cache::flush();

        $health = app(OperationsCashfreeHealthService::class);
        $reference = $health->widget(useCache: false);

        Cache::flush();

        $card = $health->platformOverviewCard();
        $item = app(PlatformIntegrationHealthOverviewService::class)->refreshItem('cashfree');

        $this->assertSame($reference['is_healthy'], $card['is_healthy']);
        $this->assertSame($reference['detail'], $card['detail']);
        $this->assertSame(
            ($reference['is_healthy'] ?? false) ? IntegrationHealthStatus::Healthy->value : IntegrationHealthStatus::Critical->value,
            $item['status'],
        );
        $this->assertSame($reference['detail'], $item['detail']);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $queries
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function probeStyleClassifyQueries(\Illuminate\Support\Collection $queries): \Illuminate\Support\Collection
    {
        return $queries->filter(function (array $query): bool {
            $sql = strtolower((string) ($query['query'] ?? ''));

            return str_contains($sql, 'cashfree_webhook_logs')
                && str_contains($sql, 'processing_status')
                && str_contains($sql, 'cf_payment_id')
                && str_contains($sql, 'orders');
        });
    }

    private function createCashfreeSystemUser(): User
    {
        $user = User::factory()->create([
            'email' => (string) config('cashfree.system_user_email'),
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    private function seedUnresolvedFailedPayment(): void
    {
        $this->createFailedSuccessLog('5900000404', 'RD-H4-4-MISSING');
    }

    private function createProcessedLog(string $cfPaymentId, string $orderId): void
    {
        $payload = $this->successfulPayload($cfPaymentId, $orderId);

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $cfPaymentId,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookProcessorService::STATUS_PROCESSED,
            'processed_at' => now(),
        ]);
    }

    private function createFailedSuccessLog(string $cfPaymentId, string $orderId): void
    {
        $payload = $this->successfulPayload($cfPaymentId, $orderId);

        CashfreeWebhookLog::query()->create([
            'cf_payment_id' => $cfPaymentId,
            'request_payload' => $payload,
            'request_headers' => [],
            'raw_body' => json_encode($payload),
            'received_at' => now(),
            'processing_status' => CashfreeWebhookLog::STATUS_FAILED,
            'processing_error' => 'platform overview card fixture',
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
