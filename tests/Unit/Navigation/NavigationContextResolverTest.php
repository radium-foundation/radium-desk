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

    public function test_dashboard_resolves_home_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $request = $this->requestFor($user, route('dashboard'));
        $context = $this->resolver->resolve($request, 'Dashboard');

        $this->assertSame(NavigationMenu::Home, $context->menu);
        $this->assertSame('home.dashboard', $context->activeItemKey);
        $this->assertSame('Home · Dashboard', $context->documentTitle);
        $this->assertSame('Home', $context->breadcrumbs[0]['label']);
        $this->assertNull($context->breadcrumbs[0]['url']);
    }

    public function test_operations_control_center_resolves_control_and_admin_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('admin.operations.index'));
        $context = $this->resolver->resolve($request, 'Operations Control Center');

        $this->assertSame(NavigationMenu::ControlAndAdmin, $context->menu);
        $this->assertSame('control_and_admin.control_center', $context->activeItemKey);
        $this->assertSame('Control & Admin · Operations Control Center', $context->documentTitle);
        $this->assertSame(route('admin.operations.index'), $context->menuHomeUrl());
    }

    public function test_agent_control_center_home_resolves_to_workforce(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $request = $this->requestFor($user, route('workforce.index'));
        $context = $this->resolver->resolve($request, 'Team Workforce');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::ControlAndAdmin, $context->menu);
        $this->assertSame('control_and_admin.control_center', $context->activeItemKey);
        $this->assertSame(route('workforce.index'), $context->menuHomeUrl());
        $this->assertSame(route('workforce.index'), $sidebar['control_and_admin']['home_url']);
    }

    public function test_purchasing_resolves_sales_and_purchasing_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('purchasing.purchase-orders.index'));
        $context = $this->resolver->resolve($request, 'Purchase Orders');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::SalesAndPurchasing, $context->menu);
        $this->assertSame('sales_and_purchasing.buy_products', $context->activeItemKey);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'sales_and_purchasing.buy_products'));
    }

    public function test_service_pos_resolves_sales_and_purchasing_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('service-pos.counter.create'));
        $context = $this->resolver->resolve($request, 'Service POS');

        $this->assertSame(NavigationMenu::SalesAndPurchasing, $context->menu);
        $this->assertSame('sales_and_purchasing.sell_services', $context->activeItemKey);
    }

    public function test_incidents_resolves_customers_and_service_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('incidents.index'));
        $context = $this->resolver->resolve($request, 'Service Cases');

        $this->assertSame(NavigationMenu::CustomersAndService, $context->menu);
        $this->assertSame('customers_and_service.service_cases', $context->activeItemKey);
    }

    public function test_automation_hub_tab_resolves_control_center_for_plain_admin(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('admin.operations.index', ['hub_tab' => 'automation']));
        $context = $this->resolver->resolve($request, 'Operations Control Center');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame('control_and_admin.control_center', $context->activeItemKey);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'control_and_admin.control_center'));
    }

    public function test_holiday_calendar_resolves_control_and_admin_administration_context(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('admin.workforce.holidays.index'));
        $context = $this->resolver->resolve($request, 'Holiday Calendar');

        $this->assertSame(NavigationMenu::ControlAndAdmin, $context->menu);
        $this->assertSame('control_and_admin.administration', $context->activeItemKey);
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
                $sidebar['home']['items'],
                $sidebar['customers_and_service']['items'],
                $sidebar['sales_and_purchasing']['items'],
                $sidebar['inventory']['items'],
                $sidebar['finance']['items'],
                $sidebar['workforce']['items'],
                $sidebar['control_and_admin']['items'],
            ),
        );

        $this->assertContains('home.dashboard', $keys);
        $this->assertContains('control_and_admin.control_center', $keys);
        $this->assertContains('workforce.attendance', $keys);
        $this->assertContains('finance.dashboard', $keys);
        $this->assertContains('control_and_admin.administration', $keys);
        $this->assertContains('sales_and_purchasing.buy_products', $keys);
        $this->assertNotContains('control_and_admin.audit_logs', $keys);
        $this->assertNotContains('customers_and_service.approvals', $keys);
    }

    public function test_approvals_route_resolves_customers_and_service_without_sidebar_highlight(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('approvals.index'));
        $context = $this->resolver->resolve($request, 'Approvals');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame('customers_and_service.approvals', $context->activeItemKey);
        $this->assertFalse($this->sidebarItemIsActive($sidebar, 'customers_and_service.approvals'));
    }

    public function test_workforce_attendance_resolves_dedicated_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('workforce-management.attendance.index'));
        $context = $this->resolver->resolve($request, 'Attendance');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::Workforce, $context->menu);
        $this->assertSame('workforce.attendance', $context->activeItemKey);
        $this->assertTrue($sidebar['workforce']['visible']);
        $this->assertTrue($this->sidebarItemIsActive($sidebar, 'workforce.attendance'));
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

    public function test_cash_book_resolves_finance_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('cash-book.index'));
        $context = $this->resolver->resolve($request, 'Cash Book');

        $this->assertSame(NavigationMenu::Finance, $context->menu);
        $this->assertSame('finance.cash_book', $context->activeItemKey);
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
