<?php

namespace Tests\Feature\DriverManagement;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RadiumBoxDriverManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_agent_cannot_access_driver_management(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->get(route('driver-management.radiumbox.index'))
            ->assertForbidden();
    }

    public function test_superadmin_has_driver_management_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_DRIVERS_MANAGE_RADIUMBOX));
    }

    public function test_permission_is_not_assigned_to_agent_role_by_seeder(): void
    {
        $role = Role::findByName(RolePermissionSeeder::ROLE_AGENT, 'web');
        $this->assertFalse($role->hasPermissionTo(RolePermissionSeeder::PERMISSION_DRIVERS_MANAGE_RADIUMBOX));
    }
}
