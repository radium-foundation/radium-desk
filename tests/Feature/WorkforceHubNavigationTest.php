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

    private function workforceSidebarLabelCount(string $html): int
    {
        return substr_count($html, '<span class="nav-label">Workforce</span>');
    }

    public function test_admin_sees_single_workforce_sidebar_entry(): void
    {
        $admin = $this->createAdmin();

        $this->assertSame(1, $this->workforceSidebarLabelCount($this->sidebarHtml($admin)));
    }

    public function test_agent_sidebar_navigation_is_unchanged_without_workforce_hub_section(): void
    {
        $agent = $this->createAgent();

        $html = $this->sidebarHtml($agent);

        $this->assertSame(1, $this->workforceSidebarLabelCount($html));
        $this->assertStringContainsString(route('my-workforce.index'), $html);
        $this->assertStringContainsString('Leave Requests', $html);
        $this->assertStringNotContainsString('Workforce Hub', $html);
        $this->assertStringNotContainsString('Team Performance', $html);
    }

    public function test_admin_workforce_hub_shows_hub_navigation_with_deep_links(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('workforce.index'))
            ->assertOk()
            ->assertSee('Team', false)
            ->assertSee('Performance', false)
            ->assertSee('Leave', false)
            ->assertSee('Holidays', false)
            ->assertSee(route('admin.workforce.performance.index'), false)
            ->assertSee(route('leave-requests.index'), false)
            ->assertSee(route('admin.workforce.holidays.index'), false);
    }

    public function test_admin_team_performance_page_shows_workforce_hub_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.workforce.performance.index'))
            ->assertOk()
            ->assertSee('aria-label="Workforce hub"', false)
            ->assertSee(route('workforce.index'), false);
    }

    public function test_admin_holidays_page_shows_workforce_hub_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.workforce.holidays.index'))
            ->assertOk()
            ->assertSee('aria-label="Workforce hub"', false)
            ->assertSee(route('workforce.index'), false);
    }

    public function test_admin_leave_requests_page_shows_workforce_hub_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('leave-requests.index'))
            ->assertOk()
            ->assertSee('aria-label="Workforce hub"', false)
            ->assertSee(route('workforce.index'), false);
    }

    public function test_agent_leave_requests_page_does_not_show_workforce_hub_navigation(): void
    {
        $agent = $this->createAgent();

        $this->actingAs($agent)
            ->get(route('leave-requests.index'))
            ->assertOk()
            ->assertDontSee('aria-label="Workforce hub"', false);
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
