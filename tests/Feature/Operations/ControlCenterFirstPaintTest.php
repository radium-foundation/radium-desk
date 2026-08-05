<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Services\Operations\OperationsDashboardLiveRenderer;
use App\Services\Operations\OperationsDashboardSectionBundles;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ControlCenterFirstPaintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_control_center_ssr_caches_first_paint_sections_not_full_dashboard(): void
    {
        Cache::flush();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Operations Control Center')
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee('operations-queue-summary-compact', false)
            ->assertSee('operations-active-operators-compact', false)
            // Health strip is a Platform demotion link on first paint (not a lazy integration-health stub).
            ->assertSee('Platform Health', false)
            ->assertSee('Open Platform Dashboard', false)
            ->assertDontSee('Loading integration health', false)
            // Ira remains deferred behind a first-paint placeholder.
            ->assertSee('Loading Ira insights', false);

        $firstPaintSections = OperationsDashboardLiveRenderer::resolveSections(
            OperationsDashboardLiveRenderer::FIRST_PAINT_GROUPS,
        );
        $normalized = $firstPaintSections;
        sort($normalized);
        $sectionCacheKey = 'operations:dashboard:sections:'.hash('xxh128', implode(',', $normalized));

        $this->assertTrue(Cache::has($sectionCacheKey), 'SSR should warm the first-paint section cache.');
        $this->assertFalse(Cache::has('operations:dashboard:latest:v2'), 'SSR should not build the full dashboard cache key.');

        $bundles = OperationsDashboardSectionBundles::bundlesForSections($firstPaintSections);
        $this->assertContains(OperationsDashboardSectionBundles::TEAM_AVAILABILITY, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::QUEUE_METRICS, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::SUPPORT_INTELLIGENCE, $bundles);
        $this->assertContains(OperationsDashboardSectionBundles::IVR_ANALYTICS, $bundles);
        $this->assertNotContains(OperationsDashboardSectionBundles::SYSTEM_HEALTH, $bundles);
        $this->assertNotContains(OperationsDashboardSectionBundles::INTEGRATION_HEALTH, $bundles);
        $this->assertNotContains(OperationsDashboardSectionBundles::TEAM_TELEGRAM_STATUS, $bundles);
    }
}
