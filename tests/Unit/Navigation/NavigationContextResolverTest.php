<?php

namespace Tests\Unit\Navigation;

use App\Models\User;
use App\Support\Navigation\NavigationContextResolver;
use App\Support\Navigation\NavigationMenu;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class NavigationContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private NavigationContextResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->resolver = app(NavigationContextResolver::class);
    }

    private function requestFor(User $user, string $uri): Request
    {
        $this->actingAs($user);

        $request = Request::create($uri, 'GET');
        $request->setUserResolver(fn () => $user);
        $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));

        return $request;
    }

    public function test_dashboard_resolves_operations_menu_home(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $request = $this->requestFor($user, route('dashboard'));
        $context = $this->resolver->resolve($request, 'Dashboard');

        $this->assertSame(NavigationMenu::Operations, $context->menu);
        $this->assertSame('operations.dashboard', $context->activeItemKey);
        $this->assertSame('Operations · Dashboard', $context->documentTitle);
        $this->assertSame('Operations', $context->breadcrumbs[0]['label']);
        $this->assertNull($context->breadcrumbs[0]['url']);
    }

    public function test_operations_control_center_resolves_control_center_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('admin.operations.index'));
        $context = $this->resolver->resolve($request, 'Operations Control Center');

        $this->assertSame(NavigationMenu::ControlCenter, $context->menu);
        $this->assertSame('control_center.home', $context->activeItemKey);
        $this->assertSame('Control Center · Operations Control Center', $context->documentTitle);
        $this->assertSame(route('admin.operations.index'), $context->menuHomeUrl());
    }

    public function test_agent_control_center_home_resolves_to_workforce(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $request = $this->requestFor($user, route('workforce.index'));
        $context = $this->resolver->resolve($request, 'Team Workforce');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::ControlCenter, $context->menu);
        $this->assertSame('control_center.home', $context->activeItemKey);
        $this->assertSame(route('workforce.index'), $context->menuHomeUrl());
        $this->assertSame(route('workforce.index'), $sidebar['control_center']['home_url']);
    }

    public function test_automation_hub_tab_resolves_super_admin_for_plain_admin(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('admin.operations.index', ['hub_tab' => 'automation']));
        $context = $this->resolver->resolve($request, 'Operations Control Center');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame('super_admin.automation', $context->activeItemKey);
        $this->assertFalse($this->sidebarItemIsActive($sidebar, 'control_center.home'));
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'super_admin.automation'));
    }

    public function test_automation_hub_tab_activates_mission_control_for_superadmin(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $request = $this->requestFor($user, route('admin.operations.index', ['hub_tab' => 'automation']));
        $context = $this->resolver->resolve($request, 'Operations Control Center');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame('super_admin.mission_control', $context->activeItemKey);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'super_admin.mission_control'));
        $this->assertFalse($this->sidebarItemIsActive($sidebar, 'super_admin.automation'));
    }

    public function test_team_hub_tab_keeps_control_center_home_active(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('admin.operations.index', ['hub_tab' => 'team']));
        $context = $this->resolver->resolve($request, 'Operations Control Center');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame('control_center.home', $context->activeItemKey);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'control_center.home'));
        $this->assertFalse($this->sidebarItemIsActive($sidebar, 'super_admin.automation'));
    }

    public function test_holiday_calendar_resolves_administration_menu_home(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('admin.workforce.holidays.index'));
        $context = $this->resolver->resolve($request, 'Holiday Calendar');

        $this->assertSame(NavigationMenu::Administration, $context->menu);
        $this->assertSame('administration.home', $context->activeItemKey);
        $this->assertSame('Holiday Calendar', $context->breadcrumbs[1]['label'] ?? null);
    }

    public function test_admin_sidebar_consolidates_to_workspace_primaries(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $request = $this->requestFor($user, route('dashboard'));
        $context = $this->resolver->resolve($request, 'Dashboard');
        $sidebar = $this->resolver->sidebar($request, $context);
        $keys = array_map(
            static fn (array $item): string => $item['key'],
            array_merge(
                $sidebar['operations']['items'],
                $sidebar['control_center']['items'],
                $sidebar['administration']['items'],
                $sidebar['super_admin']['items'],
            ),
        );

        $this->assertContains('control_center.home', $keys);
        $this->assertContains('administration.home', $keys);
        $this->assertContains('super_admin.mission_control', $keys);
        $this->assertNotContains('control_center.workforce', $keys);
        $this->assertNotContains('control_center.team_performance', $keys);
        $this->assertNotContains('control_center.leave_management', $keys);
        $this->assertNotContains('administration.users', $keys);
        $this->assertNotContains('administration.holiday_calendar', $keys);
        $this->assertNotContains('super_admin.audit_logs', $keys);
        $this->assertNotContains('super_admin.automation', $keys);
        $this->assertNotContains('administration.integrations', $keys);
    }

    /**
     * @param  array<string, array{label: string, home_url: string, visible: bool, items: list<array<string, mixed>>}>  $sidebar
     */
    private function sidebarItemIsActive(array $sidebar, string $key): bool
    {
        foreach ($sidebar as $menu) {
            foreach ($menu['items'] as $item) {
                if ($item['key'] === $key) {
                    return (bool) $item['active'];
                }
            }
        }

        return false;
    }
}
