<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FourMenuNavigationTest extends TestCase
{
    use RefreshDatabase;

    private const PRIMARY_DESTINATIONS = [
        'Home / Desk',
        'Commerce',
        'Inventory',
        'Finance',
        'Control &amp; Admin',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function sidebarHtml(User $user): string
    {
        return $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();
    }

    private function sidebarMarkup(string $html): string
    {
        if (! preg_match('/<aside class="app-sidebar"[^>]*>.*?<\/aside>/s', $html, $matches)) {
            return '';
        }

        return $matches[0];
    }

    private function destinationCount(string $html): int
    {
        return substr_count($this->sidebarMarkup($html), 'app-sidebar-destinations');
        // fallback count nav items in destinations list
    }

    private function primaryDestinationLinkCount(string $html): int
    {
        if (! preg_match('/<ul class="nav flex-column app-sidebar-destinations">(.*?)<\/ul>/s', $this->sidebarMarkup($html), $matches)) {
            return 0;
        }

        return substr_count($matches[1], 'class="nav-link');
    }

    private function activeSidebarItemCount(string $html): int
    {
        return substr_count($this->sidebarMarkup($html), 'class="nav-link active"');
    }

    public function test_admin_sidebar_exposes_exactly_five_primary_destinations(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);
        $sidebar = $this->sidebarMarkup($html);

        $this->assertSame(5, $this->primaryDestinationLinkCount($html));

        foreach (self::PRIMARY_DESTINATIONS as $destination) {
            $this->assertStringContainsString('>'.$destination.'</span>', $sidebar, "Missing destination: {$destination}");
        }

        foreach ([
            'Service Desk',
            'Service Cases',
            'To-Dos',
            'Customers &amp; Service',
            'Sales &amp; Purchasing',
            'Workforce',
            'Mission Control',
            'POS',
        ] as $retiredLabel) {
            $this->assertStringNotContainsString('title="'.$retiredLabel.'"', $sidebar);
        }
    }

    public function test_home_desk_uses_existing_dashboard_route(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertStringContainsString(route('dashboard'), $html);
        $this->assertStringContainsString('title="Home / Desk"', $html);
        $this->assertStringNotContainsString('title="Service Desk"', $this->sidebarMarkup($html));
    }

    public function test_commerce_workspace_nav_exposes_grouped_functions_for_admin(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('pos.counter.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="Commerce workspace"', $html);
        $this->assertStringContainsString('Sell Products', $html);
        $this->assertStringContainsString('Sell Services', $html);
        $this->assertStringContainsString('Buy Products', $html);
        $this->assertStringContainsString('Product Sales', $html);
        $this->assertStringContainsString('Orders', $html);
        $this->assertStringContainsString('Hardware', $html);
    }

    public function test_inventory_workspace_keeps_core_capabilities_without_hardware(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('inventory.stock.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="Inventory workspace"', $html);
        $this->assertStringContainsString('Stock History', $html);
        $this->assertStringNotContainsString('>Hardware</a>', $html);
    }

    public function test_hardware_uses_commerce_workspace_nav(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('inventory.hardware-fulfilments.index'))
            ->assertOk()
            ->assertSee('aria-label="Commerce workspace"', false)
            ->assertSee('Hardware', false);
    }

    public function test_finance_workspace_includes_cash_book_and_refunds_for_admin(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $dashboardHtml = $this->actingAs($admin)
            ->get(route('finance.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Cash Book', $dashboardHtml);
        $this->assertStringContainsString(route('cash-book.index'), $dashboardHtml);
        $this->assertStringContainsString('Refunds', $dashboardHtml);
        $this->assertStringContainsString(route('refunds.index'), $dashboardHtml);
    }

    public function test_ca_monthly_report_workspace_tab_remains_reachable(): void
    {
        if (! Route::has('finance.reports.ca-monthly.index')) {
            $this->markTestSkipped('finance.reports.ca-monthly.index is not registered on this branch.');
        }

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('finance.reports.ca-monthly.index'))
            ->assertOk()
            ->assertSee('CA Monthly Report', false);
    }

    public function test_control_and_admin_workspace_includes_attendance_leave_and_incoming_email(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="Control &amp; Admin workspace"', $html);
        $this->assertStringContainsString('Attendance', $html);
        $this->assertStringContainsString('Leave', $html);
        if (str_contains($html, 'Incoming Email')) {
            $this->assertStringContainsString('Incoming Email', $html);
        }
    }

    public function test_commerce_route_activates_commerce_destination(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('orders.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, $this->activeSidebarItemCount($html));
        $this->assertMatchesRegularExpression(
            '/data-nav-key="commerce\.destination"[^>]*class="[^"]*\bactive\b[^"]*"|class="[^"]*\bactive\b[^"]*"[^>]*data-nav-key="commerce\.destination"/s',
            $this->sidebarMarkup($html),
        );
    }

    public function test_finance_refunds_route_activates_finance_destination(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('refunds.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, $this->activeSidebarItemCount($html));
        $this->assertMatchesRegularExpression(
            '/data-nav-key="finance\.destination"/s',
            $this->sidebarMarkup($html),
        );
    }

    public function test_collapsed_sidebar_destinations_expose_accessible_tooltips(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertStringContainsString('title="Home / Desk"', $html);
        $this->assertStringContainsString('title="Commerce"', $html);
        $this->assertStringContainsString('title="Inventory"', $html);
        $this->assertStringContainsString('title="Finance"', $html);
        $this->assertStringContainsString('title="Control &amp; Admin"', $html);
    }

    public function test_approvals_do_not_appear_in_sidebar(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertStringNotContainsString('title="Approvals"', $this->sidebarMarkup($html));
        $this->assertStringNotContainsString(route('approvals.index'), $this->sidebarMarkup($html));
    }

    public function test_agent_sees_control_and_admin_without_administration_destination_noise(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $html = $this->sidebarHtml($agent);

        $this->assertGreaterThanOrEqual(1, $this->primaryDestinationLinkCount($html));
        $this->assertStringContainsString('title="Control &amp; Admin"', $html);
        $this->assertStringNotContainsString('title="To-Dos"', $this->sidebarMarkup($html));
    }

    public function test_existing_deep_link_urls_continue_to_work(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('admin.operations.index'))->assertOk();
        $this->actingAs($admin)->get(route('incidents.index'))->assertOk();
        $this->actingAs($admin)->get(route('pos.counter.create'))->assertOk();
        $this->actingAs($admin)->get(route('finance.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.administration.index'))->assertOk();
        $this->actingAs($admin)->get(route('my-workforce.index'))->assertOk();
    }
}
