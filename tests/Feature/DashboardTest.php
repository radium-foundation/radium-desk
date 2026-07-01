<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Enums\WorkspaceContext;
use App\Services\IncidentReferenceService;
use App\Services\DashboardPersonalizationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_the_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Dashboard');
    }

    public function test_agent_dashboard_shows_my_work_and_team_tabs_without_hardware_orders(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Create New Service Request')
            ->assertSee('Quick Create')
            ->assertSee('Pending Refunds')
            ->assertSee('Pending Approvals')
            ->assertSee('My Work')
            ->assertSee('dashboard-module-nav', false)
            ->assertSee('>My Work<', false)
            ->assertSee('>Team<', false)
            ->assertDontSee(route('dashboard', ['view' => 'hardware_orders']), false)
            ->assertDontSee('>Warehouse<', false)
            ->assertDontSee('>Dispatch<', false);
    }

    public function test_agent_can_switch_to_team_view(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard', ['view' => 'team']))
            ->assertOk()
            ->assertSee('Team Service Cases')
            ->assertSee('>Team<', false)
            ->assertSee('aria-selected="true"', false);
    }

    public function test_agent_is_redirected_from_unauthorized_hardware_view(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard', ['view' => 'hardware']))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();

        $this->actingAs($agent)
            ->get(route('dashboard', ['view' => 'hardware_orders']))
            ->assertRedirect(route('dashboard'));
    }

    public function test_admin_dashboard_defaults_to_team_view_with_personalization_tabs(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-module-nav', false)
            ->assertSee('Team Service Cases')
            ->assertSee('>My Work<', false)
            ->assertSee('>Team<', false)
            ->assertSee('>Hardware Orders<', false)
            ->assertSee('aria-selected="true"', false)
            ->assertSee('>Team<', false);
    }

    public function test_superadmin_dashboard_defaults_to_all_work(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($superadmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('All Service Cases')
            ->assertSee('>All<', false)
            ->assertSee('>Team<', false)
            ->assertSee('>Hardware Orders<', false);
    }

    public function test_admin_can_open_hardware_orders_view_with_permission(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard', ['view' => 'hardware_orders']))
            ->assertOk()
            ->assertSee('Order fulfillment stages such as warehouse and dispatch will be managed here.')
            ->assertDontSee('Team Service Cases')
            ->assertDontSee('dashboard-cases-title', false);
    }

    public function test_hardware_view_permission_is_assigned_by_role_not_username(): void
    {
        $agent = User::factory()->create(['email' => 'agent@example.com']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertFalse($agent->can(DashboardPersonalizationService::PERMISSION_HARDWARE_VIEW));
        $this->assertTrue($admin->can(DashboardPersonalizationService::PERMISSION_HARDWARE_VIEW));
        $this->assertDatabaseHas('permissions', [
            'name' => DashboardPersonalizationService::PERMISSION_HARDWARE_VIEW,
            'guard_name' => 'web',
        ]);
    }

    public function test_agent_dashboard_only_shows_cases_assigned_to_them(): void
    {
        $agent = User::factory()->create(['name' => 'Jayram Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $otherAgent = User::factory()->create(['name' => 'Other Agent']);
        $otherAgent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $assignedCase = $this->createIncident('ORD-ASSIGNED-1', $agent, $agent);
        $unassignedCase = $this->createIncident('ORD-UNASSIGNED-1', $otherAgent, null);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($assignedCase->reference_no)
            ->assertDontSee($unassignedCase->reference_no);
    }

    public function test_newly_assigned_case_appears_at_top_of_agent_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-29 10:00:00'));

        $admin = User::factory()->create(['name' => 'Vanshika Admin']);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $agent = User::factory()->create(['name' => 'Jayram Agent']);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $olderAssigned = $this->createIncident('ORD-OLDER', $admin, $agent);
        $olderAssigned->forceFill(['updated_at' => now()->subHour()])->save();

        $newlyAssigned = $this->createIncident('ORD-NEW', $admin, null);

        Carbon::setTestNow(Carbon::parse('2026-06-29 11:00:00'));

        $this->actingAs($admin)
            ->patchJson(route('incidents.workspace.assign', $newlyAssigned), [
                'assigned_to_user_id' => $agent->id,
                'workspace_context' => WorkspaceContext::Dashboard->value,
                'body' => 'Assigning to Jayram for immediate follow-up.',
            ])
            ->assertOk();

        $response = $this->actingAs($agent)->get(route('dashboard'));

        $response->assertOk();
        $this->assertLessThan(
            strpos((string) $response->getContent(), $olderAssigned->reference_no),
            strpos((string) $response->getContent(), $newlyAssigned->reference_no),
        );

        Carbon::setTestNow();
    }

    public function test_dashboard_legacy_warehouse_view_maps_to_hardware_orders_for_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('dashboard', ['view' => 'warehouse']))
            ->assertOk()
            ->assertSee('Order fulfillment stages such as warehouse and dispatch will be managed here.')
            ->assertDontSee('Team Service Cases')
            ->assertSee('aria-selected="true"', false)
            ->assertSee('>Hardware Orders<', false);
    }

    public function test_user_without_hardware_permission_is_redirected_from_hardware_view(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        Role::findByName(RolePermissionSeeder::ROLE_ADMIN, 'web')
            ->revokePermissionTo(DashboardPersonalizationService::PERMISSION_HARDWARE_VIEW);

        $this->actingAs($admin)
            ->get(route('dashboard', ['view' => 'hardware']))
            ->assertRedirect(route('dashboard'));
    }

    private function createIncident(string $orderId, User $creator, ?User $assignee): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Dashboard personalization test',
            'description' => 'Dashboard personalization test.',
            'status' => IncidentStatus::Open,
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'assigned_to_user_id' => $assignee?->id,
        ]);
    }
}
