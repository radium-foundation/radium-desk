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

    public function test_allowlisted_superadmin_can_open_payroll_and_salaries(): void
    {
        $ravi = User::factory()->create([
            'email' => 'info@radiumbox.com',
            'is_active' => true,
        ]);
        $ravi->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->assertTrue(AttendanceManagementAccess::allowsPayroll($ravi));

        $this->actingAs($ravi)
            ->get(route('workforce-management.payroll.index'))
            ->assertOk();

        $this->actingAs($ravi)
            ->get(route('workforce-management.salaries.index'))
            ->assertOk();
    }

    public function test_allowlisted_admin_cannot_open_payroll_or_salaries(): void
    {
        $admin = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->assertTrue(AttendanceManagementAccess::allows($admin));
        $this->assertFalse(AttendanceManagementAccess::allowsPayroll($admin));
        $this->assertFalse($admin->can(RolePermissionSeeder::PERMISSION_WORKFORCE_PAYROLL_MANAGE));

        $html = $this->actingAs($admin)
            ->get(route('workforce-management.attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('workforce-management.payroll.index'),
            $html,
        );
        $this->assertStringNotContainsString(
            route('workforce-management.salaries.index'),
            $html,
        );

        $this->actingAs($admin)
            ->get(route('workforce-management.payroll.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('workforce-management.salaries.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('workforce-management.salaries.store'), [
                'user_id' => $admin->id,
                'monthly_salary' => 10000,
                'effective_from' => '2026-07-01',
                'is_active' => '1',
            ])
            ->assertForbidden();
    }

    public function test_allowlisted_operations_admin_can_open_payroll_and_salaries(): void
    {
        $ops = User::factory()->create([
            'email' => 'shipra@radiumbox.com',
            'is_active' => true,
        ]);
        $ops->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->assertTrue(AttendanceManagementAccess::allows($ops));
        $this->assertTrue(AttendanceManagementAccess::allowsPayroll($ops));
        $this->assertTrue($ops->can(RolePermissionSeeder::PERMISSION_WORKFORCE_PAYROLL_MANAGE));
        $this->assertFalse(AttendanceManagementAccess::allowsPayrollReopen($ops));
        $this->assertFalse($ops->can(RolePermissionSeeder::PERMISSION_WORKFORCE_PAYROLL_REOPEN));

        $html = $this->actingAs($ops)
            ->get(route('workforce-management.attendance.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            route('workforce-management.payroll.index'),
            $html,
        );
        $this->assertStringContainsString(
            route('workforce-management.salaries.index'),
            $html,
        );

        $this->actingAs($ops)
            ->get(route('workforce-management.payroll.index'))
            ->assertOk();

        $this->actingAs($ops)
            ->get(route('workforce-management.salaries.index'))
            ->assertOk();
    }

    public function test_superadmin_can_reopen_permission_ops_cannot(): void
    {
        $ravi = User::factory()->create([
            'email' => 'info@radiumbox.com',
            'is_active' => true,
        ]);
        $ravi->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->assertTrue(AttendanceManagementAccess::allowsPayrollReopen($ravi));
        $this->assertTrue($ravi->can(RolePermissionSeeder::PERMISSION_WORKFORCE_PAYROLL_REOPEN));
    }
}
