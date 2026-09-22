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

    private const TOP_LEVEL_GROUPS = [
        'Home',
        'Customers &amp; Service',
        'Sales &amp; Purchasing',
        'Inventory',
        'Finance',
        'Workforce',
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

    private function navSectionCount(string $html, string $label): int
    {
        return substr_count($this->sidebarMarkup($html), '>'.$label.'</span>');
    }

    private function sidebarContains(string $html, string $needle): bool
    {
        return str_contains($this->sidebarMarkup($html), $needle);
    }

    private function activeSidebarItemCount(string $html): int
    {
        return substr_count($this->sidebarMarkup($html), 'class="nav-link active"');
    }

    public function test_admin_sidebar_exposes_all_seven_top_level_groups(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        foreach (self::TOP_LEVEL_GROUPS as $group) {
            $this->assertGreaterThanOrEqual(1, $this->navSectionCount($html, $group), "Missing group: {$group}");
        }

        foreach ([
            'Operations',
            'Mission Control',
            'Workforce Management',
            'POS',
            'Personal',
        ] as $retiredSection) {
            $this->assertSame(0, $this->navSectionCount($html, $retiredSection), "Retired section still present: {$retiredSection}");
        }

        $this->assertFalse($this->sidebarContains($html, 'title="Approvals"'));
        $this->assertStringNotContainsString(route('approvals.index'), $this->sidebarMarkup($html));
    }

    public function test_home_dashboard_link_uses_existing_dashboard_route(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertStringContainsString(route('dashboard'), $html);
        $this->assertStringContainsString('title="Dashboard"', $html);
    }

    public function test_sales_and_purchasing_exposes_pos_service_pos_and_purchasing_for_admin(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertStringContainsString(route('pos.counter.create'), $html);
        $this->assertStringContainsString(route('service-pos.counter.create'), $html);
        $this->assertStringContainsString(route('purchasing.purchase-orders.index'), $html);
        $this->assertStringContainsString('title="Sell Products"', $html);
        $this->assertStringContainsString('title="Sell Services"', $html);
        $this->assertStringContainsString('title="Buy Products"', $html);
        $this->assertStringContainsString('title="Product Sales"', $html);
    }

    public function test_customers_and_service_exposes_discoverable_workflow_links_for_admin(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertStringContainsString('title="Service Desk"', $html);
        $this->assertStringContainsString(route('incidents.index'), $html);
        $this->assertStringContainsString(route('orders.index'), $html);
        $this->assertStringContainsString(route('refunds.index'), $html);
    }

    public function test_inventory_sidebar_keeps_core_capabilities_accessible(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertStringContainsString(route('inventory.stock.index'), $html);
        $this->assertStringContainsString(route('inventory.serials.index'), $html);
        $this->assertStringContainsString(route('inventory.hardware-fulfilments.index'), $html);
        $this->assertStringContainsString(route('inventory.transfers.index'), $html);
        $this->assertStringContainsString(route('inventory.movements.index'), $html);
        $this->assertStringContainsString('title="Stock History"', $html);
    }

    public function test_finance_and_cash_book_remain_accessible_for_admin(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertStringContainsString(route('finance.dashboard'), $html);
        $this->assertStringContainsString(route('cash-book.index'), $html);
    }

    public function test_ca_monthly_report_workspace_tab_is_registered_when_route_exists(): void
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

    public function test_agent_sidebar_shows_control_center_without_administration(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $html = $this->sidebarHtml($agent);

        $this->assertStringContainsString('title="Leave"', $html);
        $this->assertStringContainsString('title="Control Center"', $html);
        $this->assertStringNotContainsString('title="Administration"', $html);
        $this->assertStringNotContainsString('title="Automation Health"', $html);
    }

    public function test_agent_sees_customers_and_service_workflow_links(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $html = $this->sidebarHtml($agent);

        $this->assertStringContainsString('title="Service Desk"', $html);
        $this->assertStringContainsString('data-nav-key="customers_and_service.orders"', $html);
        $this->assertStringContainsString('data-nav-key="customers_and_service.refunds"', $html);
    }

    public function test_admin_control_center_sidebar_points_to_operations_workspace(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertSame(1, substr_count($html, 'title="Control Center"'));
        $this->assertStringContainsString(route('admin.operations.index'), $html);
        $this->assertStringNotContainsString('title="Audit Logs"', $html);
        $this->assertStringNotContainsString('title="Webhook Explorer"', $html);
    }

    public function test_admin_administration_sidebar_points_to_primary_workspace(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertSame(1, substr_count($html, 'title="Administration"'));
        $this->assertStringContainsString(route('admin.administration.index'), $html);
        $this->assertStringNotContainsString('title="Users"', $html);
        $this->assertStringNotContainsString('title="System Settings"', $html);
    }

    public function test_superadmin_control_center_sidebar_is_deduplicated(): void
    {
        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $html = $this->sidebarHtml($superadmin);

        $this->assertStringContainsString(route('admin.platform.index'), $html);
        $this->assertSame(1, substr_count($html, 'title="Control Center"'));
    }

    public function test_operations_control_center_highlights_control_center_item(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, $this->activeSidebarItemCount($html));
        $this->assertMatchesRegularExpression(
            '/title="Control Center".*?class="[^"]*\bactive\b[^"]*"|class="[^"]*\bactive\b[^"]*".*?title="Control Center"/s',
            $html,
        );
    }

    public function test_team_hub_tab_highlights_control_center_workspace(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('admin.operations.index', ['hub_tab' => 'team']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, $this->activeSidebarItemCount($html));
        $this->assertStringContainsString('breadcrumb-item active', $html);
        $this->assertStringContainsString('Team', $html);
        $this->assertStringContainsString('aria-label="Mission Control workspace"', $html);
    }

    public function test_document_title_uses_control_and_admin_menu_context(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('<title>Control &amp; Admin · Operations Control Center</title>', false);
    }

    public function test_administration_home_breadcrumb_shows_menu_only(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('aria-label="breadcrumb"', false)
            ->assertSee('breadcrumb-item active', false)
            ->assertSee('Control &amp; Admin', false)
            ->assertDontSee('Administration</a>', false);
    }

    public function test_mission_control_workspace_tabs_and_default_selection(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee(route('workforce.index'), false)
            ->assertSee(route('admin.workforce.performance.index'), false)
            ->assertSee(route('leave-requests.index'), false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/aria-label="Mission Control workspace".*?aria-current="page"[^>]*>\s*Operations\s*</s',
            $html,
        );
    }

    public function test_workforce_deep_link_selects_workforce_workspace_tab(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('workforce.index'), '/').'"[^>]*aria-current="page"|aria-current="page"[^>]*href="'.preg_quote(route('workforce.index'), '/').'"/s',
            $html,
        );
        $this->assertSame(1, $this->activeSidebarItemCount($html));
    }

    public function test_administration_workspace_deep_links_and_holiday_tab(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('aria-label="Administration workspace"', false)
            ->assertSee(route('users.index'), false)
            ->assertSee(route('admin.system-settings.index'), false)
            ->assertSee(route('admin.workforce.holidays.index'), false)
            ->assertSee('Holiday Calendar', false)
            ->assertSee('Users &amp; Roles', false)
            ->assertSee('Operational Settings', false);

        $html = $this->actingAs($admin)
            ->get(route('admin.workforce.holidays.index'))
            ->assertOk()
            ->assertSee('aria-label="Administration workspace"', false)
            ->assertDontSee('aria-label="Mission Control workspace"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/Holiday Calendar[^<]*<\/a>|aria-current="page"[^>]*>Holiday Calendar/s',
            $html,
        );
    }

    public function test_mission_control_workspace_tabs_and_platform_health_deep_link(): void
    {
        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($superadmin)
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee(route('admin.operations.index'), false)
            ->assertSee(route('admin.operations.index', ['hub_tab' => 'automation']), false)
            ->assertSee(route('cashfree.webhook-explorer.index'), false)
            ->assertSee(route('audit-logs.index'), false)
            ->assertSee('#platform-health', false)
            ->assertSee('id="platform-health"', false);
    }

    public function test_existing_deep_link_urls_continue_to_work(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)->get(route('admin.operations.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.operations.automation-health'))->assertOk();
        $this->actingAs($admin)->get(route('admin.automation.index'))->assertOk();
        $this->actingAs($admin)->get(route('cashfree.webhook-explorer.index'))->assertOk();
        $this->actingAs($admin)->get(route('workforce.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.administration.index'))->assertOk();
        $this->actingAs($admin)->get(route('audit-logs.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.workforce.holidays.index'))->assertOk();
        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.system-settings.index'))->assertOk();
    }

    public function test_operations_dashboard_keeps_contextual_widgets(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $html = $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-operations-widget="customer-360"', $html);
        $this->assertStringContainsString('data-operations-widget="recent-customers"', $html);
        $this->assertStringContainsString('data-agent-recent-customers', $html);
    }

    public function test_rbac_hides_mission_control_workspace_tabs_for_agent(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($agent)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertDontSee('aria-label="Mission Control workspace"', false)
            ->assertDontSee(route('admin.workforce.performance.index'), false);
    }

    public function test_collapsed_sidebar_items_expose_accessible_tooltips(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertStringContainsString('title="Dashboard"', $html);
        $this->assertStringContainsString('title="Sell Products"', $html);
        $this->assertStringContainsString('title="Stock History"', $html);
        $this->assertStringContainsString('title="Control Center"', $html);
    }

    public function test_learning_center_is_not_duplicated_in_sidebar(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);
        $learningCenterCount = substr_count($this->sidebarMarkup($html), 'title="Learning Center"');

        if ($learningCenterCount === 0) {
            $this->markTestSkipped('Learning Center is hidden when inbound email intake is disabled.');
        }

        $this->assertSame(1, $learningCenterCount);
    }
}
