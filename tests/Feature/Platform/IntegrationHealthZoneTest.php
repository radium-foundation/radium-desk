<?php

namespace Tests\Feature\Platform;

use App\Enums\IntegrationHealthStatus;
use App\Models\User;
use App\Services\Operations\OperationsCashfreeHealthService;
use App\Services\Operations\OperationsGmailHealthService;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use App\Services\Platform\Zones\IntegrationHealthZone;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class IntegrationHealthZoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    private function createSuperadmin(): User
    {
        $user = User::factory()->create([
            'email' => 'integration-zone@test.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_first_paint_uses_cached_or_loading_snapshot_without_eager_diagnostics(): void
    {
        $gmail = Mockery::mock(OperationsGmailHealthService::class);
        $gmail->shouldReceive('card')->never();
        $gmail->shouldReceive('widget')->never();
        $this->app->instance(OperationsGmailHealthService::class, $gmail);

        $response = $this->actingAs($this->createSuperadmin())
            ->get(route('admin.platform.index'));

        $response->assertOk()
            ->assertSee('data-platform-zone="integration_health"', false)
            ->assertSee('Integration Health', false)
            ->assertSee('Gmail', false)
            ->assertSee('Cashfree', false)
            ->assertSee('Interakt', false)
            ->assertSee('RadiumBox', false)
            ->assertSee('ZeptoMail', false)
            ->assertSee('Telegram', false)
            ->assertSee('Meta', false)
            ->assertDontSee('Advanced Diagnostics', false)
            ->assertDontSee('data-gmail-health-card', false)
            ->assertDontSee('id="cashfree-health-heading"', false);
    }

    public function test_zone_refresh_returns_overview_cards_only(): void
    {
        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.show', ['zone' => 'integration_health']));

        $response->assertOk()
            ->assertJsonPath('key', 'integration_health');

        $html = (string) $response->json('html');
        $this->assertStringContainsString('data-platform-integration-card="gmail"', $html);
        $this->assertStringContainsString('data-platform-integration-card="cashfree"', $html);
        $this->assertStringContainsString('data-platform-integration-card="interakt"', $html);
        $this->assertStringContainsString('data-platform-integration-expand', $html);
        $this->assertStringNotContainsString('Advanced Diagnostics', $html);
        $this->assertStringNotContainsString('data-gmail-health-card', $html);
        $this->assertStringNotContainsString('Interakt Template Configuration', $html);
    }

    public function test_expand_gmail_loads_only_gmail_diagnostics(): void
    {
        $cashfree = Mockery::mock(OperationsCashfreeHealthService::class)->makePartial();
        $cashfree->shouldReceive('widget')->never();
        $this->app->instance(OperationsCashfreeHealthService::class, $cashfree);

        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.expand', [
                'zone' => 'integration_health',
                'item' => 'gmail',
            ]));

        $response->assertOk()
            ->assertJsonPath('zone', 'integration_health')
            ->assertJsonPath('item', 'gmail');

        $html = (string) $response->json('html');
        $this->assertStringContainsString('Gmail Health', $html);
        $this->assertStringContainsString('Advanced Diagnostics', $html);
        $this->assertStringNotContainsString('Cashfree Health', $html);
        $this->assertStringNotContainsString('RadiumBox Health', $html);
    }

    public function test_expand_interakt_merges_templates_section(): void
    {
        config(['interakt.api_key' => 'test-key']);

        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.expand', [
                'zone' => 'integration_health',
                'item' => 'interakt',
            ]));

        $response->assertOk();
        $html = (string) $response->json('html');
        $this->assertStringContainsString('Interakt', $html);
        $this->assertStringContainsString('Templates', $html);
        $this->assertStringNotContainsString('Interakt Template Configuration', $html);
    }

    public function test_item_cache_isolation_refreshing_gmail_does_not_clear_cashfree(): void
    {
        $service = app(PlatformIntegrationHealthOverviewService::class);

        Cache::put($service->itemCacheKey('cashfree'), [
            'key' => 'cashfree',
            'label' => 'Cashfree',
            'status' => IntegrationHealthStatus::Healthy->value,
            'status_label' => 'Healthy',
            'badge_class' => 'success',
            'platform_status' => 'healthy',
            'platform_status_label' => 'Healthy',
            'summary' => 'Cached cashfree summary',
            'detail' => 'Cached cashfree summary',
            'updated_at' => now()->toIso8601String(),
            'available' => true,
        ], now()->addMinutes(5));

        $service->refreshItem('gmail');

        $cashfree = $service->cachedItem('cashfree');
        $this->assertNotNull($cashfree);
        $this->assertSame('Cached cashfree summary', $cashfree['summary']);
        $this->assertNotNull($service->cachedItem('gmail'));
    }

    public function test_failure_isolation_marks_item_unavailable_without_clearing_siblings(): void
    {
        $service = app(PlatformIntegrationHealthOverviewService::class);

        Cache::put($service->itemCacheKey('telegram'), [
            'key' => 'telegram',
            'label' => 'Telegram',
            'status' => IntegrationHealthStatus::Healthy->value,
            'status_label' => 'Healthy',
            'badge_class' => 'success',
            'platform_status' => 'healthy',
            'platform_status_label' => 'Healthy',
            'summary' => 'Telegram ok',
            'detail' => 'Telegram ok',
            'updated_at' => now()->subMinute()->toIso8601String(),
            'available' => true,
        ], now()->addMinutes(5));

        $gmail = Mockery::mock(OperationsGmailHealthService::class);
        $gmail->shouldReceive('card')->andThrow(new \RuntimeException('gmail boom'));
        $this->app->instance(OperationsGmailHealthService::class, $gmail);

        $failed = app(PlatformIntegrationHealthOverviewService::class)->refreshItem('gmail');

        $this->assertSame(IntegrationHealthStatus::Unavailable->value, $failed['status']);
        $this->assertTrue($failed['retryable'] ?? false);

        $telegram = app(PlatformIntegrationHealthOverviewService::class)->cachedItem('telegram');
        $this->assertNotNull($telegram);
        $this->assertSame('Telegram ok', $telegram['summary']);
    }

    public function test_status_normalization_maps_operations_failed_to_critical(): void
    {
        $this->assertSame(
            IntegrationHealthStatus::Critical,
            IntegrationHealthStatus::fromOperations(\App\Enums\OperationsHealthStatus::Failed),
        );
        $this->assertSame(
            IntegrationHealthStatus::NotConfigured,
            IntegrationHealthStatus::fromOperations(\App\Enums\OperationsHealthStatus::NotConfigured),
        );
    }

    public function test_zone_status_reads_snapshot_without_refresh(): void
    {
        $zone = app(IntegrationHealthZone::class);
        $viewer = $this->createSuperadmin();

        $status = $zone->status($viewer);
        $this->assertInstanceOf(\App\Enums\PlatformHealthStatus::class, $status);
    }

    public function test_unknown_expand_item_returns_not_found(): void
    {
        $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.expand', [
                'zone' => 'integration_health',
                'item' => 'not-a-real-integration',
            ]))
            ->assertNotFound();
    }

    public function test_registry_still_resolves_integration_health_zone(): void
    {
        $zone = app(PlatformZoneRegistry::class)->get('integration_health');
        $this->assertInstanceOf(IntegrationHealthZone::class, $zone);
    }
}
