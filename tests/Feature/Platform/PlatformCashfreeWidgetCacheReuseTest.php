<?php

namespace Tests\Feature\Platform;

use App\Enums\IntegrationHealthStatus;
use App\Models\User;
use App\Services\Operations\OperationsCashfreeHealthService;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class PlatformCashfreeWidgetCacheReuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_cashfree_overview_card_reuses_operations_widget_cache_when_available(): void
    {
        $cachedWidget = [
            'is_healthy' => false,
            'detail' => '2 paid payment(s) missing Desk orders.',
            'paid_without_desk_order' => 2,
            'active_failed_webhooks' => 0,
        ];

        Cache::put(
            OperationsCashfreeHealthService::CACHE_KEY,
            $cachedWidget,
            now()->addSeconds(30),
        );

        $cashfree = Mockery::mock(OperationsCashfreeHealthService::class);
        $cashfree->shouldReceive('platformOverviewCard')
            ->once()
            ->andReturn([
                'is_healthy' => false,
                'detail' => '2 paid payment(s) missing Desk orders.',
            ]);
        $cashfree->shouldNotReceive('widget');
        $this->app->instance(OperationsCashfreeHealthService::class, $cashfree);

        $item = app(PlatformIntegrationHealthOverviewService::class)->refreshItem('cashfree');

        $this->assertSame(IntegrationHealthStatus::Critical->value, $item['status']);
        $this->assertSame('2 paid payment(s) missing Desk orders.', $item['summary']);
        $this->assertSame('2 paid payment(s) missing Desk orders.', $item['detail']);
        $this->assertTrue(Cache::has(OperationsCashfreeHealthService::CACHE_KEY));
    }

    public function test_cashfree_overview_card_builds_on_cache_miss_via_platform_path(): void
    {
        $this->assertFalse(Cache::has(OperationsCashfreeHealthService::CACHE_KEY));

        $cashfree = Mockery::mock(OperationsCashfreeHealthService::class);
        $cashfree->shouldReceive('platformOverviewCard')
            ->once()
            ->andReturn([
                'is_healthy' => true,
                'detail' => 'Payment webhooks are healthy.',
            ]);
        $cashfree->shouldNotReceive('widget');
        $this->app->instance(OperationsCashfreeHealthService::class, $cashfree);

        $item = app(PlatformIntegrationHealthOverviewService::class)->refreshItem('cashfree');

        $this->assertSame('cashfree', $item['key']);
        $this->assertSame(IntegrationHealthStatus::Healthy->value, $item['status']);
        $this->assertSame('Payment webhooks are healthy.', $item['summary']);
        $this->assertSame('Payment webhooks are healthy.', $item['detail']);
    }

    public function test_cashfree_expand_diagnostics_still_uses_cold_widget(): void
    {
        $cashfree = Mockery::mock(OperationsCashfreeHealthService::class);
        $cashfree->shouldReceive('widget')
            ->once()
            ->with(false)
            ->andReturn([
                'is_healthy' => true,
                'status_label' => 'Healthy',
                'detail' => 'Payment webhooks are healthy.',
            ]);
        $cashfree->shouldNotReceive('platformOverviewCard');
        $this->app->instance(OperationsCashfreeHealthService::class, $cashfree);

        $diagnostics = app(PlatformIntegrationHealthOverviewService::class)->diagnostics('cashfree');

        $this->assertSame('cashfree', $diagnostics['key']);
        $this->assertSame('Payment webhooks are healthy.', $diagnostics['health']['detail']);
    }

    public function test_cashfree_overview_card_matches_platform_overview_card_is_healthy_and_detail(): void
    {
        $card = [
            'is_healthy' => true,
            'detail' => 'Cashfree healthy. 3 historical failure(s) archived.',
        ];

        $cashfree = Mockery::mock(OperationsCashfreeHealthService::class);
        $cashfree->shouldReceive('platformOverviewCard')
            ->once()
            ->andReturn($card);
        $this->app->instance(OperationsCashfreeHealthService::class, $cashfree);

        $item = app(PlatformIntegrationHealthOverviewService::class)->refreshItem('cashfree');

        $this->assertSame(IntegrationHealthStatus::Healthy->value, $item['status']);
        $this->assertSame($card['detail'], $item['summary']);
        $this->assertSame($card['detail'], $item['detail']);
    }

    public function test_critical_alerts_still_aggregate_cached_cashfree_integration_item(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $overview = app(PlatformIntegrationHealthOverviewService::class);

        Cache::put($overview->itemCacheKey('cashfree'), [
            'key' => 'cashfree',
            'label' => 'Cashfree',
            'status' => IntegrationHealthStatus::Critical->value,
            'status_label' => 'Critical',
            'badge_class' => 'danger',
            'platform_status' => 'critical',
            'platform_status_label' => 'Critical',
            'summary' => '1 paid payment(s) missing Desk orders.',
            'detail' => '1 paid payment(s) missing Desk orders.',
            'updated_at' => now()->toIso8601String(),
            'available' => true,
        ], now()->addMinutes(5));

        Cache::put($overview->itemCacheKey('gmail'), [
            'key' => 'gmail',
            'label' => 'Gmail',
            'status' => IntegrationHealthStatus::Warning->value,
            'status_label' => 'Warning',
            'badge_class' => 'warning',
            'platform_status' => 'warning',
            'platform_status_label' => 'Warning',
            'summary' => 'Sync delay',
            'detail' => 'Sync delay',
            'updated_at' => now()->toIso8601String(),
            'available' => true,
        ], now()->addMinutes(5));

        $cashfree = Mockery::mock(OperationsCashfreeHealthService::class);
        $cashfree->shouldReceive('platformOverviewCard')->never();
        $cashfree->shouldReceive('widget')->never();
        $this->app->instance(OperationsCashfreeHealthService::class, $cashfree);

        $response = $this->actingAs($user)
            ->getJson(route('admin.platform.zones.show', ['zone' => 'critical_alerts']));

        $response->assertOk();
        $html = (string) $response->json('html');
        $this->assertStringContainsString('Cashfree', $html);
        $this->assertStringContainsString('Gmail', $html);
        $this->assertGreaterThanOrEqual(2, (int) data_get($response->json('summary'), 'alert_count'));
    }
}
