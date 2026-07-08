<?php

namespace App\Services;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, roles: list<string>, is_active: bool, bonvoice_extension?: string|null}  $data
     */
    public function createUser(array $data, User $actor): User
    {
        $roles = $this->normalizedRoleNamesFromArray($data['roles']);
        $this->ensureAssignableRoles($actor, $roles);

        return DB::transaction(function () use ($data, $actor, $roles): User {
            $user = User::query()->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'],
                'bonvoice_extension' => $data['bonvoice_extension'] ?? null,
            ]);

            $user->syncRoles($roles);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'user.created',
                auditable: $user,
                newValues: $this->auditSnapshot($user, $roles),
            );

            return $user->fresh(['roles']);
        });
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, roles: list<string>, is_active: bool, bonvoice_extension?: string|null}  $data
     */
    public function updateUser(User $user, array $data, User $actor): User
    {
        $roles = $this->normalizedRoleNamesFromArray($data['roles']);
        $this->ensureAssignableRoles($actor, $roles);
        $this->ensureSuperadminRoleRetained($user, $roles, $actor);
        $this->ensureActiveSuperadminRetained($user, $data['is_active']);

        return DB::transaction(function () use ($user, $data, $actor, $roles): User {
            $oldValues = $this->auditSnapshot($user, $this->roleNamesFromUser($user));

            $wasActive = $user->is_active;

            $user->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'],
                'bonvoice_extension' => $data['bonvoice_extension'] ?? null,
            ]);

            $user->syncRoles($roles);

            $freshUser = $user->fresh(['roles']);
            $newValues = $this->auditSnapshot($freshUser, $this->roleNamesFromUser($freshUser));

            if ($oldValues !== $newValues) {
                $this->auditLogService->log(
                    userId: $actor->id,
                    event: 'user.updated',
                    auditable: $freshUser,
                    oldValues: $oldValues,
                    newValues: $newValues,
                );
            }

            if ($wasActive && ! $freshUser->is_active) {
                $this->auditLogService->log(
                    userId: $actor->id,
                    event: 'user.deactivated',
                    auditable: $freshUser,
                    oldValues: ['is_active' => true],
                    newValues: ['is_active' => false],
                );
            } elseif (! $wasActive && $freshUser->is_active) {
                $this->auditLogService->log(
                    userId: $actor->id,
                    event: 'user.activated',
                    auditable: $freshUser,
                    oldValues: ['is_active' => false],
                    newValues: ['is_active' => true],
                );
            }

            return $freshUser;
        });
    }

    public function updateStatus(User $user, bool $isActive, User $actor): User
    {
        if ($user->is_active === $isActive) {
            return $user;
        }

        $this->ensureActiveSuperadminRetained($user, $isActive);

        return DB::transaction(function () use ($user, $isActive, $actor): User {
            $user->update(['is_active' => $isActive]);
            $freshUser = $user->fresh(['roles']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: $isActive ? 'user.activated' : 'user.deactivated',
                auditable: $freshUser,
                oldValues: ['is_active' => ! $isActive],
                newValues: ['is_active' => $isActive],
            );

            return $freshUser;
        });
    }

    public function resetPassword(User $user, string $password, User $actor): User
    {
        return DB::transaction(function () use ($user, $password, $actor): User {
            $user->update(['password' => Hash::make($password)]);
            $freshUser = $user->fresh(['roles']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'password.reset',
                auditable: $freshUser,
                newValues: ['password' => '[reset]'],
            );

            return $freshUser;
        });
    }

    public function deleteUser(User $user, User $actor): void
    {
        if ($actor->id === $user->id) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        $this->ensureSuperadminDeletionAllowed($user);

        DB::transaction(function () use ($user, $actor): void {
            $this->auditLogService->log(
                userId: $actor->id,
                event: 'user.deleted',
                auditable: $user,
                oldValues: $this->auditSnapshot($user, $this->roleNamesFromUser($user)),
            );

            $user->delete();
        });
    }

    /**
     * @return list<string>
     */
    public function assignableRoles(User $actor): array
    {
        if ($actor->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return [
                RolePermissionSeeder::ROLE_AGENT,
                RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
                RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
                RolePermissionSeeder::ROLE_HARDWARE_TEAM,
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ];
        }

        return [
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
            RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ];
    }

    /**
     * @param  list<string>  $roles
     */
    private function ensureAssignableRoles(User $actor, array $roles): void
    {
        $assignable = $this->assignableRoles($actor);

        foreach ($roles as $role) {
            if (! in_array($role, $assignable, true)) {
                throw ValidationException::withMessages([
                    'roles' => 'You are not allowed to assign this role.',
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $newRoles
     */
    private function ensureSuperadminRoleRetained(User $target, array $newRoles, User $actor): void
    {
        if (! $target->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return;
        }

        if (in_array(RolePermissionSeeder::ROLE_SUPERADMIN, $newRoles, true)) {
            return;
        }

        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'roles' => 'You cannot remove your own superadmin role.',
            ]);
        }

        if ($target->is_active && $this->countActiveSuperadminsExcluding($target->id) === 0) {
            throw ValidationException::withMessages([
                'roles' => 'Cannot remove the last active superadmin.',
            ]);
        }
    }

    private function ensureActiveSuperadminRetained(User $target, bool $willBeActive): void
    {
        if ($willBeActive || ! $target->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN) || ! $target->is_active) {
            return;
        }

        if ($this->countActiveSuperadminsExcluding($target->id) === 0) {
            throw ValidationException::withMessages([
                'is_active' => 'Cannot deactivate the last active superadmin.',
            ]);
        }
    }

    private function ensureSuperadminDeletionAllowed(User $target): void
    {
        if (! $target->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN) || ! $target->is_active) {
            return;
        }

        if ($this->countActiveSuperadminsExcluding($target->id) === 0) {
            throw ValidationException::withMessages([
                'user' => 'Cannot delete the last active superadmin.',
            ]);
        }
    }

    private function countActiveSuperadminsExcluding(?int $userId = null): int
    {
        $query = User::query()
            ->where('is_active', true)
            ->role(RolePermissionSeeder::ROLE_SUPERADMIN);

        if ($userId !== null) {
            $query->where('id', '!=', $userId);
        }

        return $query->count();
    }

    /**
     * @return list<string>
     */
    private function roleNamesFromUser(User $user): array
    {
        return $this->normalizedRoleNamesFromArray(
            $user->roles->pluck('name')->all(),
        );
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function normalizedRoleNamesFromArray(array $roles): array
    {
        return collect($roles)
            ->filter(fn (mixed $role): bool => is_string($role) && $role !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function auditSnapshot(User $user, array $roles): array
    {
        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'roles' => $roles,
            'is_active' => $user->is_active,
            'bonvoice_extension' => $user->bonvoice_extension,
        ];
    }
}
