<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardPersonalizationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsWorkspacePhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_dashboard_accepts_workspace_query_alias(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'scheduled']))
            ->assertOk()
            ->assertSee('data-live-workspace="scheduled"', false)
            ->assertSee('data-live-queue="scheduled"', false)
            ->assertSee('data-operations-workspace-soft-switch="1"', false)
            ->assertSee('data-operations-workspace-link', false)
            ->assertSee('data-workspace="scheduled"', false);
    }

    public function test_legacy_queue_and_filter_urls_still_work(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $this->actingAs($admin)
            ->get(route('dashboard', ['queue' => 'attention']))
            ->assertOk()
            ->assertSee('data-live-queue="attention"', false);

        $this->actingAs($admin)
            ->get(route('dashboard', ['filter' => 'overdue']))
            ->assertOk()
            ->assertSee('data-live-filter="overdue"', false)
            ->assertSee('data-live-queue="'.DashboardPersonalizationService::QUEUE_ACTION_REQUIRED.'"', false);
    }

    public function test_live_refresh_accepts_workspace_and_returns_chrome_meta(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['workspace' => 'waiting_customer']))
            ->assertOk()
            ->assertJsonPath('workspace', 'waiting_customer')
            ->assertJsonPath('operation_queue', 'waiting_customer')
            ->assertJsonPath('service_case_filter', 'waiting_customer')
            ->assertJsonStructure([
                'rows',
                'kpi_strip_html',
                'service_case_filter_counts',
                'panel_title',
                'live_scope',
            ]);
    }

    public function test_live_refresh_workspace_overdue_matches_filter_overdue(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo('incidents.view');

        $viaWorkspace = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['workspace' => 'overdue']))
            ->assertOk()
            ->json();

        $viaFilter = $this->actingAs($admin)
            ->getJson(route('dashboard.live', ['filter' => 'overdue']))
            ->assertOk()
            ->json();

        $this->assertSame($viaFilter['operation_queue'], $viaWorkspace['operation_queue']);
        $this->assertSame($viaFilter['service_case_filter'], $viaWorkspace['service_case_filter']);
        $this->assertSame('overdue', $viaWorkspace['workspace']);
    }

    public function test_active_cases_and_refunds_kpi_links_are_soft_switch_marked_when_phase2_enabled(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $admin->givePermissionTo(['incidents.view', 'refunds.view']);

        $html = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-workspace="active_cases"', $html);
        $this->assertStringContainsString('data-workspace="refunds"', $html);
        $this->assertStringContainsString(route('dashboard', ['workspace' => 'active_cases']), $html);
        $this->assertStringContainsString('workspace=refunds', $html);
    }
}
