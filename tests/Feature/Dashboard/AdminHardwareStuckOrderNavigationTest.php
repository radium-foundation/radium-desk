<?php

namespace Tests\Feature\Dashboard;

use App\Enums\HardwareFulfilmentState;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\Operations\OperationsRoleService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboard\Concerns\CreatesHardwareDashboardFulfilments;
use Tests\TestCase;

class AdminHardwareStuckOrderNavigationTest extends TestCase
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

    public function test_admin_dashboard_shows_hardware_stuck_card_with_queue_links(): void
    {
        $this->fulfilment('RDE-STUCK-01', HardwareFulfilmentState::ReadyForFulfilment);
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin = $admin->fresh();

        $stats = app(DashboardService::class)->fastChangingStatsForKpiStrip($admin);
        $summary = $stats['hardware_stuck_orders'] ?? null;
        $this->assertIsArray($summary);
        $this->assertGreaterThan(0, $summary['needs_action_total']);
        $this->assertContains('awaiting_serial', array_column($summary['breakdown'], 'filter'));

        $partial = view('dashboard.partials.admin-hardware-stuck-card', ['summary' => $summary])->render();
        $this->assertStringContainsString('data-hw-filter="awaiting_serial"', $partial);
        $this->assertStringContainsString('hw_filter=awaiting_serial', $partial);

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'action_required']))
            ->assertOk()
            ->assertSee('Hardware Stuck Orders', false)
            ->assertSee('admin-hardware-stuck-kpi', false);
    }

    public function test_stuck_card_link_opens_matching_hardware_filter(): void
    {
        $this->fulfilment('RDE-STUCK-02', HardwareFulfilmentState::ReadyForFulfilment);
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->getJson(route('dashboard.hardware.rows', [
                'workspace' => 'hardware',
                'hw_filter' => 'awaiting_serial',
            ]))
            ->assertOk()
            ->assertJsonPath('filter', 'awaiting_serial')
            ->assertJsonPath('filter_counts.awaiting_serial', 1);
    }

    public function test_agent_dashboard_does_not_show_hardware_stuck_card(): void
    {
        $this->fulfilment('RDE-STUCK-03', HardwareFulfilmentState::ReadyForFulfilment);
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Hardware Stuck Orders', false)
            ->assertDontSee('admin-hardware-stuck-kpi', false);
    }

    public function test_empty_needs_action_shows_zero_state(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin = $admin->fresh();

        $this->assertTrue($admin->can('dashboard.hardware.view'));
        $this->assertTrue(app(OperationsRoleService::class)->usesAdminQueues($admin));
        $this->assertArrayHasKey(
            'hardware_stuck_orders',
            app(DashboardService::class)->fastChangingStatsForKpiStrip($admin),
        );

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Hardware Stuck Orders', false)
            ->assertSee('No fulfilment blockers', false);
    }
}
