<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Workforce\AttendanceManagementAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceManagementAccessLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'workforce.attendance_management.restricted' => true,
            'workforce.attendance_management.allowed_emails' => [
                'info@radiumbox.com',
                'shipra@radiumbox.com',
            ],
        ]);
    }

    public function test_allowlisted_superadmin_can_open_attendance_management(): void
    {
        $ravi = User::factory()->create([
            'email' => 'info@radiumbox.com',
            'is_active' => true,
        ]);
        $ravi->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->assertTrue(AttendanceManagementAccess::allows($ravi));

        $this->actingAs($ravi)
            ->get(route('workforce-management.attendance.index'))
            ->assertOk();
    }

    public function test_allowlisted_admin_can_open_attendance_management(): void
    {
        $shipra = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $shipra->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->assertTrue(AttendanceManagementAccess::allows($shipra));

        $this->actingAs($shipra)
            ->get(route('workforce-management.attendance.index'))
            ->assertOk();
    }

    public function test_other_admin_is_forbidden_while_lock_is_active(): void
    {
        $admin = User::factory()->create([
            'email' => 'other-admin@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertTrue($admin->can('team-performance.view'));
        $this->assertFalse(AttendanceManagementAccess::allows($admin));

        $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index'))
            ->assertForbidden();
    }

    public function test_member_360_remains_available_to_other_admins(): void
    {
        $admin = User::factory()->create([
            'email' => 'other-admin@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($admin)
            ->get(route('workforce-management.members.show', $member))
            ->assertOk();
    }

    public function test_sidebar_hides_attendance_for_non_allowlisted_admin(): void
    {
        $admin = User::factory()->create([
            'email' => 'other-admin@example.com',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $html = $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('workforce-management.attendance.index'),
            $html,
        );
    }
}
