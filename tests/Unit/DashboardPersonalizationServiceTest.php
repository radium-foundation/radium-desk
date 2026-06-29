<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\DashboardPersonalizationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardPersonalizationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardPersonalizationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->service = app(DashboardPersonalizationService::class);
    }

    public function test_default_views_follow_role_personalization(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $superadmin = User::factory()->create();
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->assertSame(DashboardPersonalizationService::VIEW_MY_WORK, $this->service->defaultViewFor($agent));
        $this->assertSame(DashboardPersonalizationService::VIEW_TEAM, $this->service->defaultViewFor($admin));
        $this->assertSame(DashboardPersonalizationService::VIEW_ALL, $this->service->defaultViewFor($superadmin));
    }

    public function test_hardware_aliases_normalize_to_hardware_orders_view(): void
    {
        $this->assertSame(
            DashboardPersonalizationService::VIEW_HARDWARE_ORDERS,
            $this->service->normalizeRequestedView('hardware'),
        );
        $this->assertSame(
            DashboardPersonalizationService::VIEW_HARDWARE_ORDERS,
            $this->service->normalizeRequestedView('warehouse'),
        );
    }

    public function test_agent_hardware_request_requires_redirect(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $resolution = $this->service->resolveView($agent, 'hardware');

        $this->assertTrue($resolution['redirect']);
        $this->assertSame(DashboardPersonalizationService::VIEW_MY_WORK, $resolution['view']);
    }

    public function test_agent_module_navigation_includes_my_work_and_team_only(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $modules = $this->service->availableModulesFor($agent);

        $this->assertSame(['my_work', 'team'], array_keys($modules));
    }

    public function test_admin_module_navigation_includes_hardware_orders(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $modules = $this->service->availableModulesFor($admin);

        $this->assertSame(['my_work', 'team', 'hardware_orders'], array_keys($modules));
    }
}
