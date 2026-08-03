<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FourMenuNavigationTest extends TestCase
{
    use RefreshDatabase;

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

    private function navSectionCount(string $html, string $label): int
    {
        return substr_count($html, '>'.$label.'</span>');
    }

    private function activeSidebarItemCount(string $html): int
    {
        if (! preg_match('/<aside class="app-sidebar"[^>]*>.*?<\/aside>/s', $html, $matches)) {
            return 0;
        }

        return substr_count($matches[0], 'class="nav-link active"');
    }

    public function test_admin_sidebar_uses_standardized_menu_sections(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertGreaterThanOrEqual(1, $this->navSectionCount($html, 'Dashboard'));
        $this->assertGreaterThanOrEqual(1, $this->navSectionCount($html, 'Operations'));
        $this->assertGreaterThanOrEqual(1, $this->navSectionCount($html, 'Mission Control'));
        $this->assertGreaterThanOrEqual(1, $this->navSectionCount($html, 'Administration'));
        $this->assertStringNotContainsString('Control Center</span>', $html);
        $this->assertStringNotContainsString('Super Admin</span>', $html);
        $this->assertStringNotContainsString('Operations Hub', $html);
        $this->assertStringNotContainsString('Workforce Hub', $html);
        $this->assertStringNotContainsString('title="Approvals"', $html);
        $this->assertStringNotContainsString(route('approvals.index'), $html);
    }

    public function test_agent_sidebar_shows_operations_without_admin_menus(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $html = $this->sidebarHtml($agent);

        $this->assertGreaterThanOrEqual(1, $this->navSectionCount($html, 'Operations'));
        $this->assertStringContainsString(route('orders.index'), $html);
        $this->assertStringContainsString('My Leave', $html);
        $this->assertStringContainsString('Mission Control</span>', $html);
        $this->assertStringNotContainsString('Administration</span>', $html);
        $this->assertStringNotContainsString('title="Automation Health"', $html);
        $this->assertStringNotContainsString('title="Automation Operations"', $html);
    }

    public function test_admin_mission_control_sidebar_points_to_operations_workspace(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertSame(1, substr_count($html, 'title="Mission Control"'));
        $this->assertStringContainsString(route('admin.operations.index'), $html);
        $this->assertStringNotContainsString('title="Audit Logs"', $html);
        $this->assertStringNotContainsString('title="Webhook Explorer"', $html);
        $this->assertStringNotContainsString('title="Automation"', $html);
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
        $this->assertStringNotContainsString('title="Holiday Calendar"', $html);
    }

    public function test_superadmin_mission_control_sidebar_is_deduplicated(): void
    {
        $superadmin = User::factory()->create(['is_active' => true]);
        $superadmin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $html = $this->sidebarHtml($superadmin);

        $this->assertStringContainsString(route('admin.platform.index'), $html);
        $this->assertSame(1, substr_count($html, 'title="Mission Control"'));
        $this->assertStringNotContainsString('title="Audit Logs"', $html);
        $this->assertStringNotContainsString('title="Webhook Explorer"', $html);
        $this->assertStringNotContainsString('title="Automation"', $html);
    }

    public function test_plain_admin_uses_single_mission_control_entry(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->sidebarHtml($admin);

        $this->assertSame(1, substr_count($html, 'title="Mission Control"'));
        $this->assertStringNotContainsString('title="Audit Logs"', $html);
        $this->assertStringNotContainsString('title="Webhook Explorer"', $html);
        $this->assertStringNotContainsString('title="Automation"', $html);
    }

    public function test_operations_control_center_highlights_mission_control_item(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, $this->activeSidebarItemCount($html));
        $this->assertMatchesRegularExpression(
            '/title="Mission Control".*?class="[^"]*\bactive\b[^"]*"|class="[^"]*\bactive\b[^"]*".*?title="Mission Control"/s',
            $html,
        );
    }

    public function test_team_hub_tab_highlights_mission_control_workspace(): void
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

    public function test_automation_hub_tab_uses_mission_control_workspace(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('admin.operations.index', ['hub_tab' => 'automation']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, $this->activeSidebarItemCount($html));
        $this->assertStringContainsString('aria-label="Mission Control workspace"', $html);
        $this->assertMatchesRegularExpression(
            '/aria-label="Mission Control workspace".*?aria-current="page"[^>]*>\s*Automation\s*</s',
            $html,
        );
    }

    public function test_document_title_includes_menu_context(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('<title>Mission Control · Operations Control Center</title>', false);
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
            ->assertSee('Administration', false)
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
            ->assertSee('id="platform-health"', false)
            ->assertSee('data-platform-workspace-links', false);
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
}
