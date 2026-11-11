<?php

namespace Tests\Feature\Dashboard;

use App\Enums\HardwareFulfilmentState;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\HardwareFulfilment\HardwareDashboardWorkspace;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Dashboard\Concerns\CreatesHardwareDashboardFulfilments;
use Tests\TestCase;

class HardwareDashboardStuckSummaryDeferTest extends TestCase
{
    use CreatesHardwareDashboardFulfilments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seedCatalog();
        $this->withoutVite();
    }

    public function test_initial_dashboard_ssr_excludes_hardware_stuck_summary_stats(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $stats = app(DashboardService::class)->statsForInitialDashboardRender($admin);
        $this->assertArrayNotHasKey('hardware_stuck_orders', $stats);
        $this->assertTrue(app(DashboardService::class)->shouldDeferHardwareStuckSummary($admin));
    }

    public function test_initial_dashboard_get_does_not_call_stuck_order_summary(): void
    {
        $this->fulfilment('RDE-DEFER-01', HardwareFulfilmentState::ReadyForFulfilment);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'action_required']))
            ->assertOk()
            ->assertSee('data-hardware-stuck-summary', false)
            ->assertSee('Loading…', false)
            ->assertDontSee('admin-hardware-stuck-chip', false);
        $ssrHwQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'hardware_fulfilments'))
            ->count();
        DB::disableQueryLog();

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(HardwareDashboardWorkspace::class)->stuckOrderSummary();
        $stuckSummaryHwQueries = collect(DB::getQueryLog())
            ->filter(fn (array $q): bool => str_contains($q['query'], 'hardware_fulfilments'))
            ->count();
        DB::disableQueryLog();

        $this->assertGreaterThan($ssrHwQueries, $stuckSummaryHwQueries);
        $this->assertGreaterThanOrEqual(8, $stuckSummaryHwQueries);
    }

    public function test_stuck_summary_endpoint_returns_matching_counts(): void
    {
        $this->fulfilment('RDE-DEFER-02', HardwareFulfilmentState::ReadyForFulfilment);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $expected = app(HardwareDashboardWorkspace::class)->stuckOrderSummary();

        $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.stuck-summary'))
            ->assertOk()
            ->assertJsonPath('summary.needs_action_total', $expected['needs_action_total'])
            ->assertJsonStructure(['html', 'summary']);
    }

    public function test_agent_cannot_access_stuck_summary_endpoint(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->getJson(route('dashboard.hardware.stuck-summary'))
            ->assertForbidden();
    }
}
