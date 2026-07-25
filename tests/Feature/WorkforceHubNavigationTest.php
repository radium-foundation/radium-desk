<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkforceHubNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
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

    public function test_admin_sees_single_control_center_sidebar_entry(): void
    {
        $admin = $this->createAdmin();
        $html = $this->sidebarHtml($admin);

        $this->assertSame(1, substr_count($html, 'title="Mission Control"'));
        $this->assertStringNotContainsString('<span class="nav-label">Workforce</span>', $html);
    }

    public function test_agent_sidebar_shows_control_center_primary_to_workforce(): void
    {
        $agent = $this->createAgent();

        $html = $this->sidebarHtml($agent);

        $this->assertSame(1, substr_count($html, 'title="Mission Control"'));
        $this->assertStringContainsString(route('workforce.index'), $html);
        $this->assertStringContainsString(route('my-workforce.index'), $html);
        $this->assertStringContainsString('My Leave', $html);
        $this->assertStringNotContainsString('Workforce Hub', $html);
        $this->assertStringContainsString('Mission Control</span>', $html);
        $this->assertStringNotContainsString('Team Performance', $html);
    }

    public function test_admin_workforce_page_shows_control_center_workspace_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee('Operations', false)
            ->assertSee('Performance', false)
            ->assertSee('Leave', false)
            ->assertSee(route('admin.operations.index'), false)
            ->assertSee(route('admin.workforce.performance.index'), false)
            ->assertSee(route('leave-requests.index'), false)
            ->assertDontSee(route('admin.workforce.holidays.index'), false);
    }

    public function test_admin_team_performance_page_shows_control_center_workspace_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.workforce.performance.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee(route('workforce.index'), false);
    }

    public function test_admin_holidays_page_shows_administration_workspace_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.workforce.holidays.index'))
            ->assertOk()
            ->assertSee('aria-label="Administration workspace"', false)
            ->assertDontSee('aria-label="Mission Control workspace"', false)
            ->assertSee(route('admin.administration.index'), false);
    }

    public function test_admin_leave_requests_page_shows_control_center_workspace_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('leave-requests.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee(route('workforce.index'), false);
    }

    public function test_agent_leave_requests_page_does_not_show_control_center_workspace_navigation(): void
    {
        $agent = $this->createAgent();

        $this->actingAs($agent)
            ->get(route('leave-requests.index'))
            ->assertOk()
            ->assertDontSee('aria-label="Mission Control workspace"', false);
    }

    public function test_existing_workforce_urls_continue_to_work_for_admin(): void
    {
        $admin = $this->createAdmin();
        $member = $this->createAgent();

        $this->actingAs($admin)->get(route('workforce.index'))->assertOk();
        $this->actingAs($admin)->get(route('workforce.show', $member))->assertOk();
        $this->actingAs($admin)->get(route('admin.workforce.performance.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.workforce.holidays.index'))->assertOk();
        $this->actingAs($admin)->get(route('leave-requests.index'))->assertOk();
    }
}
