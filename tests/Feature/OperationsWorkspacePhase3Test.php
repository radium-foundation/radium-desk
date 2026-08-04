<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsWorkspacePhase3Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_active_cases_workspace_uses_native_dashboard_chrome(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $html = $this->actingAs($admin)
            ->getJson(route('dashboard.workspace', ['workspace' => 'active_cases']))
            ->assertOk()
            ->assertJsonPath('workspace', 'active_cases')
            ->assertJsonPath('panel_title', 'Active Service Cases')
            ->json('panel_html');

        $this->assertStringContainsString('dashboard-cases-table', $html);
        $this->assertStringContainsString('dashboard-ops-filter', $html);
        $this->assertStringContainsString('data-operations-embedded-panel="active_cases"', $html);
        $this->assertStringNotContainsString('<h2 class="h6 mb-0">Filters</h2>', $html);
    }

    public function test_refunds_workspace_uses_native_dashboard_chrome(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('refunds.view');

        $html = $this->actingAs($admin)
            ->getJson(route('dashboard.workspace', [
                'workspace' => 'refunds',
                'status' => 'pending',
            ]))
            ->assertOk()
            ->assertJsonPath('workspace', 'refunds')
            ->assertJsonPath('panel_title', 'Refund Queue')
            ->json('panel_html');

        $this->assertStringContainsString('dashboard-cases-table', $html);
        $this->assertStringContainsString('dashboard-case-filter-chip', $html);
        $this->assertStringContainsString('data-operations-embedded-panel="refunds"', $html);
        $this->assertStringContainsString('Refund Queue', $html);
        $this->assertStringNotContainsString('<h2 class="h6 mb-0">Filters</h2>', $html);
    }

    public function test_dashboard_ssr_embeds_native_active_cases_layout(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'active_cases']))
            ->assertOk()
            ->assertSee('dashboard-ops-workspace-card', false)
            ->assertSee('dashboard-cases-table', false)
            ->assertSee('data-operations-embedded-panel="active_cases"', false);
    }

    public function test_phase3_can_roll_back_to_legacy_listing_chrome(): void
    {
        config(['dashboard.operations_workspace_phase3_native' => false]);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $html = $this->actingAs($admin)
            ->getJson(route('dashboard.workspace', ['workspace' => 'active_cases']))
            ->assertOk()
            ->json('panel_html');

        $this->assertStringContainsString('<h2 class="h6 mb-0">Filters</h2>', $html);
        $this->assertStringNotContainsString('dashboard-ops-filter', $html);
    }

    public function test_phase1_queues_remain_unchanged(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'attention']))
            ->assertOk()
            ->assertSee('data-live-queue="attention"', false)
            ->assertSee('data-operations-workspace-kind="case_queue"', false)
            ->assertSee('id="dashboard-service-cases-panel"', false);
    }
}
