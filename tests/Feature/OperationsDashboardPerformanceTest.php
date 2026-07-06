<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Operations\OperationsDashboardLiveRenderer;
use App\Services\Operations\OperationsDashboardService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OperationsDashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_dashboard_build_profiled_returns_component_timings(): void
    {
        Cache::flush();

        $result = app(OperationsDashboardService::class)->buildProfiled();

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('profile', $result);
        $this->assertArrayHasKey('total_ms', $result);
        $this->assertArrayHasKey('support_intelligence', $result['profile']);
        $this->assertArrayHasKey('cashfree_health', $result['profile']);
        $this->assertArrayHasKey('radiumbox_health', $result['profile']);
        $this->assertGreaterThan(0, $result['total_ms']);
    }

    public function test_live_endpoint_supports_partial_group_refresh(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $fullResponse = $this->actingAs($admin)
            ->getJson(route('admin.operations.live'))
            ->assertOk();

        $partialResponse = $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'critical,summary,health']))
            ->assertOk()
            ->assertJsonPath('groups', ['critical', 'summary', 'health']);

        $fullSections = array_keys($fullResponse->json('html'));
        $partialSections = array_keys($partialResponse->json('html'));

        $this->assertGreaterThan(count($partialSections), count($fullSections));
        $this->assertSame(['critical_alerts', 'overview_cards', 'health_status'], $partialSections);
    }

    public function test_live_partial_refresh_skips_ira_and_advisor_when_not_needed(): void
    {
        Cache::flush();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->getJson(route('admin.operations.live', ['groups' => 'performance']))
            ->assertOk()
            ->assertJsonMissingPath('html.critical_alerts')
            ->assertJsonMissingPath('html.ira_briefing')
            ->assertJsonMissingPath('html.advisor_insights')
            ->assertJsonStructure([
                'html' => [
                    'notification_metrics',
                    'automation_metrics',
                    'queue_metrics',
                ],
            ]);
    }

    public function test_live_renderer_group_sections_cover_all_dashboard_sections(): void
    {
        $groupSections = collect(OperationsDashboardLiveRenderer::GROUP_SECTIONS)
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $allSections = collect(OperationsDashboardLiveRenderer::ALL_SECTIONS)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($allSections, $groupSections);
    }
}
