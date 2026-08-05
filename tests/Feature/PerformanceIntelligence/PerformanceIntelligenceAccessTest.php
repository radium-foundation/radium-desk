<?php

namespace Tests\Feature\PerformanceIntelligence;

use App\Models\User;
use App\Support\Administration\PerformanceIntelligenceAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerformanceIntelligenceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['performance_intelligence.enabled' => true]);
    }

    public function test_only_superadmin_can_view_when_enabled(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $super = User::factory()->create(['is_active' => true]);
        $super->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->assertFalse(PerformanceIntelligenceAccess::canView($agent));
        $this->assertFalse(PerformanceIntelligenceAccess::canView($admin));
        $this->assertTrue(PerformanceIntelligenceAccess::canView($super));
        $this->assertFalse(PerformanceIntelligenceAccess::canView(null));
    }

    public function test_admin_gets_403_and_superadmin_ok(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $super = User::factory()->create(['is_active' => true]);
        $super->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($admin)
            ->get(route('admin.performance-intelligence.index'))
            ->assertForbidden();

        $this->actingAs($super)
            ->get(route('admin.performance-intelligence.index'))
            ->assertOk()
            ->assertSee('Performance Intelligence')
            ->assertSee('shadow mode', false);
    }

    public function test_nav_tab_visible_only_to_superadmin_when_enabled(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $super = User::factory()->create(['is_active' => true]);
        $super->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($admin)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertDontSee('Performance Intelligence', false)
            ->assertDontSee(route('admin.performance-intelligence.index'), false);

        $this->actingAs($super)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('Performance Intelligence', false)
            ->assertSee(route('admin.performance-intelligence.index'), false);
    }
}
