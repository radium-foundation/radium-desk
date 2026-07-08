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
     * @param  array{first_name: string, last_name: string, email: string, password: string, role: string, is_active: bool, bonvoice_extension?: string|null}  $data
     */
    public function createUser(array $data, User $actor): User
    {
        $this->ensureAssignableRole($actor, $data['role']);

        return DB::transaction(function () use ($data, $actor): User {
            $user = User::query()->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'],
                'bonvoice_extension' => $data['bonvoice_extension'] ?? null,
            ]);

            $user->syncRoles([$data['role']]);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'user.created',
                auditable: $user,
                newValues: $this->auditSnapshot($user, $data['role']),
            );

            return $user->fresh(['roles']);
        });
    }

    /**
     * @param  array{first_name: string, last_name: string, email: string, role: string, is_active: bool, bonvoice_extension?: string|null}  $data
     */
    public function updateUser(User $user, array $data, User $actor): User
    {
        $this->ensureAssignableRole($actor, $data['role']);
        $this->ensureSuperadminRoleRetained($user, $data['role'], $actor);
        $this->ensureActiveSuperadminRetained($user, $data['is_active']);

        return DB::transaction(function () use ($user, $data, $actor): User {
            $oldRole = $user->roles->first()?->name;
            $oldValues = $this->auditSnapshot($user, $oldRole);

            $wasActive = $user->is_active;

            $user->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'],
                'bonvoice_extension' => $data['bonvoice_extension'] ?? null,
            ]);

            $user->syncRoles([$data['role']]);

            $freshUser = $user->fresh(['roles']);
            $newRole = $freshUser->roles->first()?->name;
            $newValues = $this->auditSnapshot($freshUser, $newRole);

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
                oldValues: $this->auditSnapshot($user, $user->roles->first()?->name),
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

    private function ensureAssignableRole(User $actor, string $role): void
    {
        if (! in_array($role, $this->assignableRoles($actor), true)) {
            throw ValidationException::withMessages([
                'role' => 'You are not allowed to assign this role.',
            ]);
        }
    }

    private function ensureSuperadminRoleRetained(User $target, string $newRole, User $actor): void
    {
        if (! $target->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            return;
        }

        if ($newRole === RolePermissionSeeder::ROLE_SUPERADMIN) {
            return;
        }

        if ($actor->id === $target->id) {
            throw ValidationException::withMessages([
                'role' => 'You cannot remove your own superadmin role.',
            ]);
        }

        if ($target->is_active && $this->countActiveSuperadminsExcluding($target->id) === 0) {
            throw ValidationException::withMessages([
                'role' => 'Cannot remove the last active superadmin.',
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
     * @return array<string, mixed>
     */
    private function auditSnapshot(User $user, ?string $role): array
    {
        return [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'role' => $role,
            'is_active' => $user->is_active,
            'bonvoice_extension' => $user->bonvoice_extension,
        ];
    }
}
