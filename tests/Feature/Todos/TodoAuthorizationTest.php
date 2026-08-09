<?php

namespace Tests\Feature\Todos;

use App\Models\Todo;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TodoAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_seeder_creates_all_todo_permissions(): void
    {
        foreach ([
            RolePermissionSeeder::PERMISSION_TODOS_VIEW,
            RolePermissionSeeder::PERMISSION_TODOS_CREATE,
            RolePermissionSeeder::PERMISSION_TODOS_UPDATE,
            RolePermissionSeeder::PERMISSION_TODOS_ASSIGN,
            RolePermissionSeeder::PERMISSION_TODOS_MANAGE,
        ] as $permission) {
            $this->assertNotNull(
                Permission::findByName($permission, 'web'),
                "Missing permission {$permission}",
            );
        }
    }

    public function test_baseline_roles_receive_view_create_update_but_not_assign_or_manage(): void
    {
        foreach ([
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
            RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
            RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
            RolePermissionSeeder::ROLE_EMPLOYEE,
        ] as $role) {
            $user = $this->userWithRole($role);

            $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_TODOS_VIEW), $role);
            $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_TODOS_CREATE), $role);
            $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_TODOS_UPDATE), $role);
            $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_TODOS_ASSIGN), $role);
            $this->assertFalse($user->can(RolePermissionSeeder::PERMISSION_TODOS_MANAGE), $role);
        }
    }

    public function test_admin_team_roles_receive_assign_and_manage(): void
    {
        foreach (RolePermissionSeeder::ADMIN_TEAM_ROLES as $role) {
            $user = $this->userWithRole($role);

            $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_TODOS_VIEW), $role);
            $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_TODOS_CREATE), $role);
            $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_TODOS_UPDATE), $role);
            $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_TODOS_ASSIGN), $role);
            $this->assertTrue($user->can(RolePermissionSeeder::PERMISSION_TODOS_MANAGE), $role);
        }
    }

    public function test_creator_can_view_update_complete_and_cancel_own_todo(): void
    {
        $creator = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $todo = $this->todoOwnedBy($creator, $creator);

        $this->assertTrue(Gate::forUser($creator)->allows('viewAny', Todo::class));
        $this->assertTrue(Gate::forUser($creator)->allows('view', $todo));
        $this->assertTrue(Gate::forUser($creator)->allows('create', Todo::class));
        $this->assertTrue(Gate::forUser($creator)->allows('update', $todo));
        $this->assertTrue(Gate::forUser($creator)->allows('complete', $todo));
        $this->assertTrue(Gate::forUser($creator)->allows('delete', $todo));
        $this->assertTrue(Gate::forUser($creator)->allows('cancel', $todo));
        $this->assertFalse(Gate::forUser($creator)->allows('assign', $todo));
    }

    public function test_assignee_can_view_update_and_complete_but_cannot_cancel(): void
    {
        $creator = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $assignee = $this->userWithRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);
        $todo = $this->todoOwnedBy($creator, $assignee);

        $this->assertTrue(Gate::forUser($assignee)->allows('view', $todo));
        $this->assertTrue(Gate::forUser($assignee)->allows('update', $todo));
        $this->assertTrue(Gate::forUser($assignee)->allows('complete', $todo));
        $this->assertFalse(Gate::forUser($assignee)->allows('delete', $todo));
        $this->assertFalse(Gate::forUser($assignee)->allows('cancel', $todo));
        $this->assertFalse(Gate::forUser($assignee)->allows('assign', $todo));
    }

    public function test_unrelated_user_is_denied_all_instance_abilities(): void
    {
        $creator = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $other = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $todo = $this->todoOwnedBy($creator, $creator);

        $this->assertFalse(Gate::forUser($other)->allows('view', $todo));
        $this->assertFalse(Gate::forUser($other)->allows('update', $todo));
        $this->assertFalse(Gate::forUser($other)->allows('complete', $todo));
        $this->assertFalse(Gate::forUser($other)->allows('delete', $todo));
        $this->assertFalse(Gate::forUser($other)->allows('cancel', $todo));
        $this->assertFalse(Gate::forUser($other)->allows('assign', $todo));
    }

    public function test_manage_permission_bypasses_ownership_for_view_update_complete_and_cancel(): void
    {
        $creator = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $manager = $this->userWithRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);
        $todo = $this->todoOwnedBy($creator, $creator);

        $this->assertTrue(Gate::forUser($manager)->allows('view', $todo));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $todo));
        $this->assertTrue(Gate::forUser($manager)->allows('complete', $todo));
        $this->assertTrue(Gate::forUser($manager)->allows('delete', $todo));
        $this->assertTrue(Gate::forUser($manager)->allows('cancel', $todo));
    }

    public function test_assign_permission_allows_assign_regardless_of_ownership(): void
    {
        $creator = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $todo = $this->todoOwnedBy($creator, $creator);

        $this->assertTrue(Gate::forUser($admin)->allows('assign', $todo));
        $this->assertFalse(Gate::forUser($creator)->allows('assign', $todo));
    }

    public function test_create_permission_is_required_for_create_ability(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo([
            RolePermissionSeeder::PERMISSION_TODOS_VIEW,
            RolePermissionSeeder::PERMISSION_TODOS_UPDATE,
        ]);

        $this->assertFalse(Gate::forUser($user)->allows('create', Todo::class));

        $user->givePermissionTo(RolePermissionSeeder::PERMISSION_TODOS_CREATE);

        $this->assertTrue(Gate::forUser($user)->allows('create', Todo::class));
    }

    public function test_update_and_complete_require_update_permission_even_for_creator(): void
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->givePermissionTo([
            RolePermissionSeeder::PERMISSION_TODOS_VIEW,
            RolePermissionSeeder::PERMISSION_TODOS_CREATE,
        ]);
        $todo = $this->todoOwnedBy($creator, $creator);

        $this->assertFalse(Gate::forUser($creator)->allows('update', $todo));
        $this->assertFalse(Gate::forUser($creator)->allows('complete', $todo));
        $this->assertTrue(Gate::forUser($creator)->allows('view', $todo));
        $this->assertTrue(Gate::forUser($creator)->allows('delete', $todo));

        $creator->givePermissionTo(RolePermissionSeeder::PERMISSION_TODOS_UPDATE);

        $this->assertTrue(Gate::forUser($creator)->allows('update', $todo));
        $this->assertTrue(Gate::forUser($creator)->allows('complete', $todo));
    }

    public function test_view_requires_view_permission_even_for_creator(): void
    {
        $creator = User::factory()->create(['is_active' => true]);
        $creator->givePermissionTo([
            RolePermissionSeeder::PERMISSION_TODOS_CREATE,
            RolePermissionSeeder::PERMISSION_TODOS_UPDATE,
        ]);
        $todo = $this->todoOwnedBy($creator, $creator);

        $this->assertFalse(Gate::forUser($creator)->allows('viewAny', Todo::class));
        $this->assertFalse(Gate::forUser($creator)->allows('view', $todo));

        $creator->givePermissionTo(RolePermissionSeeder::PERMISSION_TODOS_VIEW);

        $this->assertTrue(Gate::forUser($creator)->allows('viewAny', Todo::class));
        $this->assertTrue(Gate::forUser($creator)->allows('view', $todo));
    }

    public function test_employee_has_baseline_todo_permissions_like_leave_create_roles(): void
    {
        $employee = $this->userWithRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $this->assertTrue($employee->can('leave-requests.create'));
        $this->assertTrue($employee->can(RolePermissionSeeder::PERMISSION_TODOS_VIEW));
        $this->assertTrue($employee->can(RolePermissionSeeder::PERMISSION_TODOS_CREATE));
        $this->assertTrue($employee->can(RolePermissionSeeder::PERMISSION_TODOS_UPDATE));
        $this->assertFalse($employee->can(RolePermissionSeeder::PERMISSION_TODOS_ASSIGN));
        $this->assertFalse($employee->can(RolePermissionSeeder::PERMISSION_TODOS_MANAGE));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function todoOwnedBy(User $creator, User $assignee): Todo
    {
        return Todo::factory()->create([
            'created_by' => $creator->id,
            'assigned_to' => $assignee->id,
        ]);
    }
}
