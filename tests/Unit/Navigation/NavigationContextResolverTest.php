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

    public function test_dashboard_resolves_dashboard_menu_home(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $request = $this->requestFor($user, route('dashboard'));
        $context = $this->resolver->resolve($request, 'Dashboard');

        $this->assertSame(NavigationMenu::Dashboard, $context->menu);
        $this->assertSame('dashboard.home', $context->activeItemKey);
        $this->assertSame('Dashboard · Dashboard', $context->documentTitle);
        $this->assertSame('Dashboard', $context->breadcrumbs[0]['label']);
        $this->assertNull($context->breadcrumbs[0]['url']);
    }

    public function test_operations_control_center_resolves_mission_control_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('admin.operations.index'));
        $context = $this->resolver->resolve($request, 'Operations Control Center');

        $this->assertSame(NavigationMenu::MissionControl, $context->menu);
        $this->assertSame('mission_control.home', $context->activeItemKey);
        $this->assertSame('Mission Control · Operations Control Center', $context->documentTitle);
        $this->assertSame(route('admin.operations.index'), $context->menuHomeUrl());
    }

    public function test_agent_mission_control_home_resolves_to_workforce(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $request = $this->requestFor($user, route('workforce.index'));
        $context = $this->resolver->resolve($request, 'Team Workforce');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::MissionControl, $context->menu);
        $this->assertSame('mission_control.home', $context->activeItemKey);
        $this->assertSame(route('workforce.index'), $context->menuHomeUrl());
        $this->assertSame(route('workforce.index'), $sidebar['mission_control']['home_url']);
    }

    public function test_automation_hub_tab_resolves_mission_control_for_plain_admin(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('admin.operations.index', ['hub_tab' => 'automation']));
        $context = $this->resolver->resolve($request, 'Operations Control Center');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame('mission_control.home', $context->activeItemKey);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'mission_control.home'));
    }

    public function test_automation_hub_tab_activates_mission_control_for_superadmin(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $request = $this->requestFor($user, route('admin.operations.index', ['hub_tab' => 'automation']));
        $context = $this->resolver->resolve($request, 'Operations Control Center');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame('mission_control.home', $context->activeItemKey);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'mission_control.home'));
    }

    public function test_team_hub_tab_keeps_mission_control_home_active(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('admin.operations.index', ['hub_tab' => 'team']));
        $context = $this->resolver->resolve($request, 'Operations Control Center');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame('mission_control.home', $context->activeItemKey);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'mission_control.home'));
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
                $sidebar['dashboard']['items'],
                $sidebar['operations']['items'],
                $sidebar['mission_control']['items'],
                $sidebar['workforce_management']['items'],
                $sidebar['inventory']['items'],
                $sidebar['pos']['items'],
                $sidebar['finance']['items'],
                $sidebar['administration']['items'],
                $sidebar['personal']['items'],
            ),
        );

        $this->assertContains('dashboard.home', $keys);
        $this->assertContains('mission_control.home', $keys);
        $this->assertContains('workforce_management.attendance', $keys);
        $this->assertContains('finance.dashboard', $keys);
        $this->assertContains('finance.invoices', $keys);
        $this->assertContains('finance.reports', $keys);
        $this->assertContains('inventory.stock', $keys);
        $this->assertContains('pos.counter', $keys);
        $this->assertContains('administration.home', $keys);
        $this->assertNotContains('super_admin.audit_logs', $keys);
        $this->assertNotContains('super_admin.automation', $keys);
        $this->assertNotContains('super_admin.webhook_explorer', $keys);
        $this->assertNotContains('administration.users', $keys);
        $this->assertNotContains('administration.holiday_calendar', $keys);
        $this->assertNotContains('operations.approvals', $keys);
        $this->assertNotContains('operations.orders', $keys);
        $this->assertNotContains('operations.incidents', $keys);
        $this->assertNotContains('operations.refunds', $keys);
    }

    public function test_approvals_route_resolves_operations_incidents_context_without_sidebar_highlight(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('approvals.index'));
        $context = $this->resolver->resolve($request, 'Approvals');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame('operations.incidents', $context->activeItemKey);
        $this->assertFalse($this->sidebarItemIsActive($sidebar, 'operations.incidents'));
        $this->assertFalse($this->sidebarItemIsActive($sidebar, 'operations.approvals'));
    }

    public function test_workforce_management_attendance_resolves_dedicated_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('workforce-management.attendance.index'));
        $context = $this->resolver->resolve($request, 'Attendance');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::WorkforceManagement, $context->menu);
        $this->assertSame('workforce_management.attendance', $context->activeItemKey);
        $this->assertTrue($sidebar['workforce_management']['visible']);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'workforce_management.attendance'));
    }

    public function test_finance_dashboard_resolves_dedicated_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('finance.dashboard'));
        $context = $this->resolver->resolve($request, 'Dashboard');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::Finance, $context->menu);
        $this->assertSame('finance.dashboard', $context->activeItemKey);
        $this->assertTrue($sidebar['finance']['visible']);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'finance.dashboard'));
    }

    public function test_inventory_stock_resolves_dedicated_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('inventory.stock.index'));
        $context = $this->resolver->resolve($request, 'Stock');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::Inventory, $context->menu);
        $this->assertSame('inventory.stock', $context->activeItemKey);
        $this->assertTrue($sidebar['inventory']['visible']);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'inventory.stock'));
    }

    public function test_pos_counter_resolves_dedicated_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('pos.counter.create'));
        $context = $this->resolver->resolve($request, 'POS counter');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::Pos, $context->menu);
        $this->assertSame('pos.counter', $context->activeItemKey);
        $this->assertTrue($sidebar['pos']['visible']);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'pos.counter'));
    }

    public function test_accountant_sees_invoices_and_reports_without_pos_or_inventory(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ACCOUNTANT);

        $request = $this->requestFor($user, route('finance.invoices.index'));
        $context = $this->resolver->resolve($request, 'Invoices');
        $sidebar = $this->resolver->sidebar($request, $context);
        $keys = array_map(
            static fn (array $item): string => $item['key'],
            array_merge(
                $sidebar['finance']['items'],
                $sidebar['inventory']['items'],
                $sidebar['pos']['items'],
                $sidebar['administration']['items'],
            ),
        );

        $this->assertSame(NavigationMenu::Finance, $context->menu);
        $this->assertSame('finance.invoices', $context->activeItemKey);
        $this->assertContains('finance.invoices', $keys);
        $this->assertContains('finance.reports', $keys);
        $this->assertNotContains('finance.dashboard', $keys);
        $this->assertNotContains('inventory.stock', $keys);
        $this->assertNotContains('pos.counter', $keys);
        $this->assertNotContains('administration.home', $keys);
        $this->assertSame(route('finance.invoices.index'), $context->menuHomeUrl());
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
