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

    public function test_dashboard_resolves_home_desk_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $request = $this->requestFor($user, route('dashboard'));
        $context = $this->resolver->resolve($request, 'Dashboard');

        $this->assertSame(NavigationMenu::HomeDesk, $context->menu);
        $this->assertSame('home_desk.destination', $context->activeItemKey);
        $this->assertSame('Home / Desk · Dashboard', $context->documentTitle);
    }

    public function test_incidents_resolve_home_desk_not_primary_sidebar_item(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('incidents.index'));
        $context = $this->resolver->resolve($request, 'Service Cases');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::HomeDesk, $context->menu);
        $this->assertTrue($sidebar['home_desk']['destination']['active']);
        $this->assertFalse($sidebar['commerce']['destination']['active']);
    }

    public function test_orders_resolve_commerce_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('orders.index'));
        $context = $this->resolver->resolve($request, 'Orders');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::Commerce, $context->menu);
        $this->assertTrue($sidebar['commerce']['destination']['active']);
    }

    public function test_hardware_fulfilments_resolve_commerce_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('inventory.hardware-fulfilments.index'));
        $context = $this->resolver->resolve($request, 'Hardware');

        $this->assertSame(NavigationMenu::Commerce, $context->menu);
    }

    public function test_refunds_resolve_finance_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('refunds.index'));
        $context = $this->resolver->resolve($request, 'Refunds');

        $this->assertSame(NavigationMenu::Finance, $context->menu);
    }

    public function test_attendance_resolves_control_and_admin_menu(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('workforce-management.attendance.index'));
        $context = $this->resolver->resolve($request, 'Attendance');
        $sidebar = $this->resolver->sidebar($request, $context);

        $this->assertSame(NavigationMenu::ControlAndAdmin, $context->menu);
        $this->assertTrue($sidebar['control_and_admin']['destination']['active']);
    }

    public function test_approvals_resolve_home_desk_without_sidebar_highlight_item(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $request = $this->requestFor($user, route('approvals.index'));
        $context = $this->resolver->resolve($request, 'Approvals');

        $this->assertSame(NavigationMenu::HomeDesk, $context->menu);
        $this->assertSame('home_desk.approvals', $context->activeItemKey);
    }

    public function test_admin_sidebar_has_five_destinations(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $request = $this->requestFor($user, route('dashboard'));
        $context = $this->resolver->resolve($request, 'Dashboard');
        $sidebar = $this->resolver->sidebar($request, $context);

        $visibleDestinations = array_filter($sidebar, static fn (array $group): bool => $group['visible']);

        $this->assertCount(5, $visibleDestinations);
        $this->assertArrayHasKey('home_desk', $sidebar);
        $this->assertArrayHasKey('commerce', $sidebar);
        $this->assertArrayHasKey('inventory', $sidebar);
        $this->assertArrayHasKey('finance', $sidebar);
        $this->assertArrayHasKey('control_and_admin', $sidebar);
    }
}
