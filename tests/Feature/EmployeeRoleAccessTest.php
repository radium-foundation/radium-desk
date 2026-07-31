<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_employee_can_login(): void
    {
        $employee = $this->createEmployee([
            'email' => 'employee@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->post('/login', [
            'email' => 'employee@example.com',
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($employee);
    }

    public function test_employee_is_forbidden_from_incident_and_support_modules(): void
    {
        $employee = $this->createEmployee();

        $this->actingAs($employee)
            ->get(route('incidents.index'))
            ->assertForbidden();

        $this->actingAs($employee)
            ->get(route('orders.index'))
            ->assertForbidden();

        $this->actingAs($employee)
            ->get(route('workforce-management.attendance.index'))
            ->assertForbidden();

        $this->actingAs($employee)
            ->get(route('workforce.index'))
            ->assertForbidden();
    }

    public function test_employee_can_access_personal_attendance_leave_and_profile(): void
    {
        $employee = $this->createEmployee();

        $this->actingAs($employee)
            ->get(route('my-workforce.index'))
            ->assertOk();

        $this->actingAs($employee)
            ->get(route('leave-requests.index'))
            ->assertOk();

        $this->actingAs($employee)
            ->get(route('leave-requests.create'))
            ->assertOk();

        $this->actingAs($employee)
            ->get(route('profile.edit'))
            ->assertOk();

        $this->actingAs($employee)
            ->get(route('notifications.index'))
            ->assertOk();
    }

    public function test_existing_agent_permissions_unchanged_by_employee_role(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->assertTrue($agent->can('incidents.view'));
        $this->assertTrue($agent->can('orders.view'));
        $this->assertTrue($agent->can('workforce.view'));
        $this->assertTrue($agent->can('workforce.self'));
        $this->assertTrue($agent->can('leave-requests.view'));

        $this->actingAs($agent)
            ->get(route('incidents.index'))
            ->assertOk();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createEmployee(array $overrides = []): User
    {
        $employee = User::factory()->create(array_merge([
            'is_active' => true,
        ], $overrides));
        $employee->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        return $employee;
    }
}
