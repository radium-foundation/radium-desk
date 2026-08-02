<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Services\Operations\OperationsQueueMetricsService;
use App\Services\Platform\PlatformPerformanceOverviewService;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ExecutiveCommandCenterPhaseETest extends TestCase
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
            'email' => 'phase-e@test.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_platform_index_renders_all_phase_e_zones_as_shells(): void
    {
        $queue = Mockery::mock(OperationsQueueMetricsService::class);
        $queue->shouldReceive('metrics')->never();
        $this->app->instance(OperationsQueueMetricsService::class, $queue);

        $this->actingAs($this->createSuperadmin())
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee('data-platform-zone="performance"', false)
            ->assertSee('data-platform-zone="automation"', false)
            ->assertSee('data-platform-zone="communications"', false)
            ->assertSee('data-platform-zone="finance_overview"', false)
            ->assertSee('data-platform-zone="operations_overview"', false)
            ->assertSee('data-platform-zone="tools"', false)
            ->assertSee('Tools &amp; Diagnostics', false)
            ->assertSee('Performance', false)
            ->assertSee('Communications', false)
            ->assertSee('Finance Overview', false)
            ->assertSee('Operations Overview', false);
    }

    public function test_performance_zone_refresh_returns_summary_cards(): void
    {
        Cache::put(PlatformPerformanceOverviewService::CACHE_KEY, [
            'items' => [
                [
                    'key' => 'queue',
                    'label' => 'Queue',
                    'status' => 'healthy',
                    'status_label' => 'Healthy',
                    'badge_class' => 'success',
                    'platform_status' => 'healthy',
                    'summary' => '0 pending · 0 failed',
                    'updated_at' => now()->toIso8601String(),
                    'expandable' => true,
                ],
            ],
            'overall_status' => 'healthy',
            'generated_at' => now()->toIso8601String(),
            'available' => true,
        ], now()->addMinute());

        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.show', ['zone' => 'performance']));

        $response->assertOk()->assertJsonPath('key', 'performance');
        $html = (string) $response->json('html');
        $this->assertStringContainsString('data-platform-summary-card="queue"', $html);
        $this->assertStringContainsString('data-platform-integration-expand', $html);
    }

    public function test_tools_zone_is_links_catalog(): void
    {
        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.show', ['zone' => 'tools']));

        $response->assertOk();
        $html = (string) $response->json('html');
        $this->assertStringContainsString('data-platform-tools-catalog', $html);
        $this->assertStringContainsString('Webhook Explorer', $html);
        $this->assertStringContainsString('Automation Health', $html);
        $this->assertStringContainsString('Audit Logs', $html);
    }

    public function test_zone_sort_order_matches_executive_command_center(): void
    {
        $registry = app(PlatformZoneRegistry::class);
        $keys = array_map(
            static fn ($zone): string => $zone->definition()->key(),
            $registry->all(),
        );

        $this->assertSame(
            [
                'critical_alerts',
                'executive_snapshot',
                'platform_health',
                'integration_health',
                'performance',
                'automation',
                'communications',
                'finance_overview',
                'operations_overview',
                'tools',
            ],
            $keys,
        );
    }

    public function test_operations_performance_tab_demotes_integration_health_embeds(): void
    {
        $admin = $this->createSuperadmin();

        $response = $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'performance']));

        $response->assertOk();
        $html = (string) $response->json('html.performance_tab');
        $this->assertStringContainsString('Open Platform Dashboard', $html);
        $this->assertStringNotContainsString('id="radiumbox-health-heading"', $html);
        $this->assertStringNotContainsString('id="cashfree-health-heading"', $html);
        $this->assertStringNotContainsString('id="gmail-health-heading"', $html);
        $this->assertStringContainsString('operations-queue-metrics', $html);
    }

    public function test_operations_system_tab_demotes_system_and_integration_health(): void
    {
        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.operations.live', ['groups' => 'system']));

        $response->assertOk();
        $html = (string) $response->json('html.system_tab');
        $this->assertStringContainsString('Open Platform Dashboard', $html);
        $this->assertStringNotContainsString('id="system-health-heading"', $html);
        $this->assertStringNotContainsString('id="integration-health-heading"', $html);
        $this->assertStringContainsString('operations-recent-notification-failures', $html);
    }

    public function test_administration_still_has_zero_live_monitoring_widgets(): void
    {
        $this->actingAs($this->createSuperadmin())
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('Open Platform Dashboard', false)
            ->assertDontSee('Gmail Health', false)
            ->assertDontSee('Run Gmail Sync Now', false)
            ->assertDontSee('operations-integration-pill', false);
    }
}
