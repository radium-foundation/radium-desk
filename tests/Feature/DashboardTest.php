<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_agent_dashboard_shows_quick_create_and_service_cases(): void
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
            ->assertSee('Recent Service Cases')
            ->assertSee('Hardware Orders', false)
            ->assertSee('Service Cases', false)
            ->assertDontSee('>Warehouse<', false)
            ->assertDontSee('>Dispatch<', false);
    }

    public function test_dashboard_module_navigation_shows_three_modules_only(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dashboard-module-nav', false)
            ->assertSee('>All<', false)
            ->assertSee('>Service Cases<', false)
            ->assertSee('>Hardware Orders<', false)
            ->assertDontSee('>Warehouse<', false)
            ->assertDontSee('>Dispatch<', false);
    }

    public function test_dashboard_hardware_orders_view_shows_placeholder_without_service_cases(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard', ['view' => 'hardware_orders']))
            ->assertOk()
            ->assertSee('Order fulfillment stages such as warehouse and dispatch will be managed here.')
            ->assertDontSee('Recent Service Cases');
    }

    public function test_dashboard_legacy_warehouse_view_maps_to_hardware_orders_module(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('dashboard', ['view' => 'warehouse']))
            ->assertOk()
            ->assertSee('Order fulfillment stages such as warehouse and dispatch will be managed here.')
            ->assertDontSee('Recent Service Cases')
            ->assertSee('aria-selected="true"', false)
            ->assertSee('>Hardware Orders<', false);
    }
}
