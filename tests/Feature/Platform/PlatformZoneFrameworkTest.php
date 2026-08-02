<?php

namespace Tests\Feature\Platform;

use App\Enums\PlatformZoneId;
use App\Models\User;
use App\Services\Platform\Zones\PlatformZoneRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PlatformZoneFrameworkTest extends TestCase
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
            'email' => 'zone-framework@test.com',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    public function test_zone_registry_registers_all_framework_zones(): void
    {
        $registry = app(PlatformZoneRegistry::class);
        $keys = array_map(
            static fn ($zone): string => $zone->definition()->key(),
            $registry->all(),
        );

        foreach (PlatformZoneId::cases() as $zoneId) {
            $this->assertContains($zoneId->value, $keys);
        }
    }

    public function test_platform_index_renders_zone_shell_without_eager_executive_cards(): void
    {
        $this->actingAs($this->createSuperadmin())
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee('data-platform-zones', false)
            ->assertSee('data-platform-zone="critical_alerts"', false)
            ->assertSee('data-platform-zone="executive_snapshot"', false)
            ->assertSee('data-platform-zone="platform_health"', false)
            ->assertSee('data-platform-zone="integration_health"', false)
            ->assertSee('data-platform-zone="performance"', false)
            ->assertSee('data-platform-zone="automation"', false)
            ->assertSee('data-platform-zone="operations_overview"', false)
            ->assertSee('data-platform-zone="finance_overview"', false)
            ->assertSee('data-platform-zone="communications"', false)
            ->assertSee('data-platform-zone="tools"', false)
            ->assertSee('Executive Snapshot', false)
            ->assertSee('Platform Health', false)
            ->assertSee('Integration Health', false)
            ->assertDontSee('Open Cases', false);
    }

    public function test_zone_refresh_endpoint_returns_html_for_platform_health(): void
    {
        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.show', ['zone' => 'platform_health']));

        $response->assertOk()
            ->assertJsonPath('key', 'platform_health')
            ->assertJsonStructure([
                'key',
                'status',
                'status_label',
                'updated_at',
                'html',
                'summary',
            ]);

        $this->assertStringContainsString('Platform Health', (string) $response->json('html'));
        $this->assertStringContainsString('data-platform-card', (string) $response->json('html'));
    }

    public function test_zone_refresh_endpoint_returns_executive_cards(): void
    {
        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.show', ['zone' => 'executive_snapshot']));

        $response->assertOk()
            ->assertJsonPath('key', 'executive_snapshot');

        $html = (string) $response->json('html');
        $this->assertStringContainsString('Open Cases', $html);
        $this->assertStringContainsString('Critical Cases', $html);
    }

    public function test_zone_expand_endpoint_returns_stub_html(): void
    {
        Cache::put(app(\App\Services\Platform\PlatformIntegrationHealthOverviewService::class)->itemCacheKey('gmail'), [
            'key' => 'gmail',
            'label' => 'Gmail',
            'status' => \App\Enums\IntegrationHealthStatus::Warning->value,
            'status_label' => 'Warning',
            'badge_class' => 'warning',
            'platform_status' => 'warning',
            'platform_status_label' => 'Warning',
            'summary' => 'Sync delay',
            'detail' => 'Sync delay',
            'updated_at' => now()->toIso8601String(),
            'available' => true,
        ], now()->addMinutes(5));

        $response = $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.expand', [
                'zone' => 'critical_alerts',
                'item' => 'gmail',
            ]));

        $response->assertOk()
            ->assertJsonPath('zone', 'critical_alerts')
            ->assertJsonPath('item', 'gmail');

        $this->assertStringContainsString('Gmail', (string) $response->json('html'));
    }

    public function test_unknown_zone_returns_not_found(): void
    {
        $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.show', ['zone' => 'missing_zone']))
            ->assertNotFound();
    }

    public function test_non_expandable_zone_expand_returns_not_found(): void
    {
        $this->actingAs($this->createSuperadmin())
            ->getJson(route('admin.platform.zones.expand', [
                'zone' => 'performance',
                'item' => 'default',
            ]))
            ->assertNotFound();
    }
}
