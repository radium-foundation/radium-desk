<?php

namespace Tests\Feature\DriverManagement;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverManagementNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_superadmin_sees_driver_management_in_control_workspace_nav(): void
    {
        config(['driver_management.radiumbox_com.enabled' => true]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($user)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Driver files (RadiumBox)', false)
            ->assertSee(route('driver-management.radiumbox.index'), false);
    }

    public function test_agent_does_not_see_driver_management_nav_link(): void
    {
        config(['driver_management.radiumbox_com.enabled' => true]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Driver files (RadiumBox)', false);
    }

    public function test_superadmin_does_not_see_nav_when_feature_flag_disabled(): void
    {
        config(['driver_management.radiumbox_com.enabled' => false]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($user)
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertDontSee('Driver files (RadiumBox)', false);
    }
}
