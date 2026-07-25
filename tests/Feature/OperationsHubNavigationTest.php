<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsHubNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    private function createAgent(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return $user;
    }

    private function sidebarHtml(User $user): string
    {
        return $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();
    }

    private function operationsHubSidebarLabelCount(string $html, string $label): int
    {
        return substr_count($html, '<span class="nav-label">'.$label.'</span>');
    }

    public function test_agent_cannot_access_operations_control_center(): void
    {
        $agent = $this->createAgent();

        $this->actingAs($agent)
            ->get(route('admin.operations.index'))
            ->assertForbidden();
    }

    public function test_admin_sidebar_shows_single_control_center_entry(): void
    {
        $admin = $this->createAdmin();

        $html = $this->sidebarHtml($admin);

        $this->assertSame(1, substr_count($html, 'title="Mission Control"'));
        $this->assertSame(0, $this->operationsHubSidebarLabelCount($html, 'Automation Health'));
        $this->assertStringContainsString(route('admin.operations.index'), $html);
        $this->assertStringNotContainsString('title="Automation Operations"', $html);
        $this->assertStringNotContainsString('title="Automation Health"', $html);
        $this->assertStringNotContainsString('title="Operations Control Center"', $html);
    }

    public function test_agent_sidebar_navigation_excludes_admin_control_center_entries(): void
    {
        $agent = $this->createAgent();

        $html = $this->sidebarHtml($agent);

        $this->assertStringNotContainsString('Operations Hub', $html);
        $this->assertStringNotContainsString('title="Operations Control Center"', $html);
        $this->assertStringNotContainsString('title="Automation Health"', $html);
        $this->assertStringNotContainsString('title="Automation Operations"', $html);
        $this->assertStringContainsString('Mission Control</span>', $html);
        $this->assertStringContainsString(route('orders.index'), $html);
    }

    public function test_admin_operations_control_center_shows_merged_hub_navigation(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee('aria-label="Operations hub"', false)
            ->assertSee('id="operations-dashboard-tabs"', false)
            ->assertSee('id="operations-tab-today"', false)
            ->assertSee('id="operations-tab-team"', false)
            ->assertSee('data-bs-toggle="tab"', false)
            ->assertSee('data-operations-live-group="today"', false)
            ->assertSee('id="operations-tab-automation"', false)
            ->assertSee('data-automation-health-url="'.route('admin.operations.automation-health').'"', false)
            ->assertSee('data-automation-pipeline-url="'.route('admin.automation.index').'"', false)
            ->assertSee('data-operations-automation-tab', false)
            ->assertSee('data-automation-subview-target="health"', false)
            ->assertSee('data-automation-subview-target="pipeline"', false)
            ->assertSee('id="operations-automation-health-content"', false)
            ->assertSee('id="operations-automation-pipeline-content"', false)
            ->assertSee(route('admin.automation.index'), false);

        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, 'aria-label="Operations hub"'));
        $this->assertSame(1, substr_count($html, 'id="operations-tab-today"'));
        $this->assertSame(1, substr_count($html, 'id="operations-tab-team"'));
        $this->assertSame(1, substr_count($html, 'id="operations-tab-performance"'));
        $this->assertSame(1, substr_count($html, 'id="operations-tab-system"'));
        $this->assertSame(1, substr_count($html, 'id="operations-tab-automation"'));
        $this->assertStringNotContainsString('>Webhook Explorer</a>', $html);
    }

    public function test_admin_operations_hub_supports_automation_hub_tab_query(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.operations.index', ['hub_tab' => 'automation']))
            ->assertOk()
            ->assertSee('id="operations-tab-automation"', false)
            ->assertSee('data-bs-target="#operations-pane-automation"', false)
            ->assertSee('data-automation-health-url="'.route('admin.operations.automation-health').'"', false)
            ->assertSee('data-automation-subview-target="health"', false)
            ->assertSee('aria-label="Mission Control workspace"', false);
    }

    public function test_admin_operations_hub_supports_automation_pipeline_subview_query(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.operations.index', [
                'hub_tab' => 'automation',
                'automation_view' => 'pipeline',
            ]))
            ->assertOk()
            ->assertSee('data-automation-subview-target="pipeline"', false)
            ->assertSee('data-automation-pipeline-url="'.route('admin.automation.index').'"', false)
            ->assertSee('id="operations-automation-pipeline-content"', false);
    }

    public function test_automation_operations_standalone_page_exposes_embed_marker(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.automation.index'))
            ->assertOk()
            ->assertSee('data-automation-pipeline-embed', false)
            ->assertSee('Automation Operations')
            ->assertSee('Action Queues')
            ->assertSee('Validation Summary');
    }

    public function test_operations_control_center_hides_automation_tab_without_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo('operations-dashboard.view');

        $this->actingAs($user)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertDontSee('id="operations-tab-automation"', false)
            ->assertDontSee('data-automation-health-url', false)
            ->assertDontSee('data-automation-pipeline-url', false)
            ->assertDontSee('id="operations-pane-automation"', false);
    }

    public function test_automation_health_standalone_page_exposes_embed_marker(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.operations.automation-health'))
            ->assertOk()
            ->assertSee('data-automation-health-embed', false)
            ->assertSee('Automation Health')
            ->assertSee('Overview')
            ->assertSee('Failures');
    }

    public function test_admin_operations_hub_supports_hub_tab_query_links(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.operations.index', ['hub_tab' => 'team']))
            ->assertOk()
            ->assertSee('id="operations-tab-team"', false)
            ->assertSee('data-bs-target="#operations-pane-team"', false);
    }

    public function test_control_center_merged_nav_lives_inside_dashboard_tabs_card(): void
    {
        $admin = $this->createAdmin();

        $html = $this->actingAs($admin)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->getContent();

        $cardPos = strpos($html, 'operations-dashboard-tabs');
        $hubNavPos = strpos($html, 'operations-hub-nav--card-header');

        $this->assertNotFalse($cardPos);
        $this->assertNotFalse($hubNavPos);
        $this->assertGreaterThan($cardPos, $hubNavPos);
    }

    public function test_automation_health_page_shows_super_admin_workspace_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.operations.automation-health'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee(route('admin.operations.index', ['hub_tab' => 'automation']), false)
            ->assertSee(route('cashfree.webhook-explorer.index'), false)
            ->assertSee('Automation Health')
            ->assertDontSee('aria-label="Operations hub"', false)
            ->assertDontSee('operations-hub-nav--card-header', false);
    }

    public function test_automation_operations_page_shows_super_admin_workspace_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.automation.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee('hub_tab=automation', false)
            ->assertDontSee('aria-label="Operations hub"', false);
    }

    public function test_webhook_explorer_page_shows_super_admin_workspace_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('cashfree.webhook-explorer.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee(route('audit-logs.index'), false)
            ->assertDontSee('aria-label="Operations hub"', false);
    }

    public function test_existing_operations_urls_continue_to_work_for_admin(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)->get(route('admin.operations.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.operations.automation-health'))->assertOk();
        $this->actingAs($admin)->get(route('admin.automation.index'))->assertOk();
        $this->actingAs($admin)->get(route('cashfree.webhook-explorer.index'))->assertOk();
    }

    public function test_agent_cannot_access_automation_health_or_webhook_explorer(): void
    {
        $agent = $this->createAgent();

        $this->actingAs($agent)->get(route('admin.operations.automation-health'))->assertForbidden();
        $this->actingAs($agent)->get(route('admin.automation.index'))->assertForbidden();
        $this->actingAs($agent)->get(route('cashfree.webhook-explorer.index'))->assertForbidden();
    }
}
