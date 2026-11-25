<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DashboardMissionControlTabRowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->withoutVite();
    }

    public function test_superadmin_dashboard_omits_mission_control_horizontal_tab_row(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $html = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="dashboard-kpi-strip"', false)
            ->assertSee('data-operations-primary-panel', false)
            ->assertDontSee('aria-label="Mission Control workspace"', false)
            ->getContent();

        $this->assertStringNotContainsString('mission-control-workspace-nav', $html);
    }

    public function test_admin_dashboard_omits_mission_control_horizontal_tab_row(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('id="dashboard-kpi-strip"', false)
            ->assertDontSee('aria-label="Mission Control workspace"', false);
    }

    public function test_agent_dashboard_omits_mission_control_horizontal_tab_row(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('aria-label="Mission Control workspace"', false);
    }

    public function test_mission_control_destination_routes_remain_registered(): void
    {
        $expected = [
            'admin.platform.index',
            'admin.operations.index',
            'workforce.index',
            'admin.workforce.performance.index',
            'leave-requests.index',
            'audit-logs.index',
            'cashfree.webhook-explorer.index',
        ];

        foreach ($expected as $name) {
            $this->assertTrue(Route::has($name), "Missing route: {$name}");
        }
    }

    public function test_superadmin_can_still_reach_mission_control_destinations_by_url(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($user)->get(route('admin.platform.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.operations.index'))->assertOk();
        $this->actingAs($user)->get(route('workforce.index'))->assertOk();
        $this->actingAs($user)->get(route('admin.workforce.performance.index'))->assertOk();
        $this->actingAs($user)->get(route('leave-requests.index'))->assertOk();
        $this->actingAs($user)->get(route('audit-logs.index'))->assertOk();
        $this->actingAs($user)->get(route('cashfree.webhook-explorer.index'))->assertOk();
        $this->actingAs($user)
            ->get(route('admin.operations.index', ['hub_tab' => 'automation']))
            ->assertOk();
    }
}