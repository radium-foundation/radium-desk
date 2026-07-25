<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Services\Platform\PlatformHealthCache;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MissionControlCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-07-25 12:00:00', 'Asia/Kolkata'));
        PlatformHealthCache::recordSchedulerHeartbeat(now());
        PlatformHealthCache::recordPresenceTimeoutRun(0, 0, now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function createSuperadmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user;
    }

    private function createOperationsAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        return $user;
    }

    private function createAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return $user;
    }

    public function test_superadmin_mission_control_exposes_workspace_tabs(): void
    {
        $this->actingAs($this->createSuperadmin())
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->assertSee('data-platform-workspace-links', false)
            ->assertSee(route('admin.operations.index'), false)
            ->assertSee(route('admin.operations.index', ['hub_tab' => 'automation']), false)
            ->assertSee(route('cashfree.webhook-explorer.index'), false)
            ->assertSee(route('audit-logs.index'), false)
            ->assertSee('#platform-health', false)
            ->assertSee(route('workforce.index'), false)
            ->assertSee(route('refunds.index', ['status' => 'pending']), false)
            ->assertDontSee('Cards coming next', false);
    }

    public function test_operations_admin_sees_rbac_filtered_mission_control_tabs(): void
    {
        $this->actingAs($this->createOperationsAdmin())
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee(route('admin.operations.index'), false)
            ->assertSee('data-platform-workspace-links', false)
            ->assertDontSee('Application Settings', false);
    }

    public function test_plain_admin_cannot_open_mission_control_overview_but_keeps_tool_routes(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.platform.index'))
            ->assertForbidden();

        $this->actingAs($admin)->get(route('admin.system-settings.index'))->assertOk();
        $this->actingAs($admin)->get(route('audit-logs.index'))->assertOk();
        $this->actingAs($admin)->get(route('cashfree.webhook-explorer.index'))->assertOk();
        $this->actingAs($admin)
            ->get(route('admin.operations.index', ['hub_tab' => 'automation']))
            ->assertOk();
    }

    public function test_superadmin_workspace_nav_includes_operations_and_platform_health(): void
    {
        $html = $this->actingAs($this->createSuperadmin())
            ->get(route('admin.platform.index'))
            ->assertOk()
            ->assertSee('aria-label="Mission Control workspace"', false)
            ->getContent();

        $this->assertMatchesRegularExpression('/aria-label="Mission Control workspace".*?Operations/s', $html);
        $this->assertMatchesRegularExpression('/aria-label="Mission Control workspace".*?Platform Health/s', $html);
    }

    public function test_system_settings_shows_administration_and_settings_workspace_nav(): void
    {
        $this->actingAs($this->createSuperadmin())
            ->get(route('admin.system-settings.index'))
            ->assertOk()
            ->assertSee('aria-label="Administration workspace"', false)
            ->assertSee('aria-label="Settings workspace"', false)
            ->assertSee('id="realtime-settings-card"', false);
    }

    public function test_existing_platform_routes_remain_reachable(): void
    {
        $superadmin = $this->createSuperadmin();

        $this->actingAs($superadmin)->get(route('admin.platform.index'))->assertOk();
        $this->actingAs($superadmin)->get(route('admin.operations.index'))->assertOk();
        $this->actingAs($superadmin)->get(route('admin.operations.automation-health'))->assertOk();
        $this->actingAs($superadmin)->get(route('admin.automation.index'))->assertOk();
        $this->actingAs($superadmin)->get(route('cashfree.webhook-explorer.index'))->assertOk();
        $this->actingAs($superadmin)->get(route('audit-logs.index'))->assertOk();
        $this->actingAs($superadmin)->get(route('admin.system-settings.index'))->assertOk();
        $this->actingAs($superadmin)->get(route('admin.administration.index'))->assertOk();
    }
}
