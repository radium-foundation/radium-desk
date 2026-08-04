<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsWorkspacePhase2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dashboard_workspace_endpoint_renders_active_cases_panel(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $this->actingAs($admin)
            ->getJson(route('dashboard.workspace', ['workspace' => 'active_cases']))
            ->assertOk()
            ->assertJsonPath('workspace', 'active_cases')
            ->assertJsonPath('kind', 'embedded')
            ->assertJsonPath('supports_live', false)
            ->assertJsonStructure(['panel_html', 'panel_title'])
            ->assertJsonFragment(['panel_title' => 'Active Service Cases']);
    }

    public function test_dashboard_workspace_endpoint_renders_refunds_panel(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('refunds.view');

        $this->actingAs($admin)
            ->getJson(route('dashboard.workspace', [
                'workspace' => 'refunds',
                'status' => 'pending',
            ]))
            ->assertOk()
            ->assertJsonPath('workspace', 'refunds')
            ->assertJsonPath('supports_live', false)
            ->assertJsonPath('panel_title', 'Refund Queue');
    }

    public function test_dashboard_ssr_embeds_active_cases_workspace(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'active_cases']))
            ->assertOk()
            ->assertSee('data-operations-workspace-kind="embedded"', false)
            ->assertSee('data-operations-case-host', false)
            ->assertSee('data-operations-embedded-host', false)
            ->assertSee('data-operations-embedded-panel="active_cases"', false);
    }

    public function test_legacy_incidents_and_refunds_routes_still_work(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo(['incidents.view', 'refunds.view']);

        $this->actingAs($admin)
            ->get(route('incidents.index', ['status' => 'active']))
            ->assertOk()
            ->assertSee(config('ui.service_case.plural'));

        $this->actingAs($admin)
            ->get(route('refunds.index', ['status' => 'pending']))
            ->assertOk()
            ->assertSee('Refunds');
    }

    public function test_workspace_endpoint_respects_permissions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('dashboard.workspace', ['workspace' => 'refunds']))
            ->assertForbidden();
    }

    public function test_phase1_queues_still_soft_switch_on_dashboard(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'attention']))
            ->assertOk()
            ->assertSee('data-live-queue="attention"', false)
            ->assertSee('data-operations-workspace-kind="case_queue"', false);
    }
}
