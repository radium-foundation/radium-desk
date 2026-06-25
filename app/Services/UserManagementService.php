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
     * @param  array{first_name: string, last_name: string, email: string, password: string, role: string, is_active: bool}  $data
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
     * @param  array{first_name: string, last_name: string, email: string, role: string, is_active: bool}  $data
     */
    public function updateUser(User $user, array $data, User $actor): User
    {
        $this->ensureAssignableRole($actor, $data['role']);

        return DB::transaction(function () use ($user, $data, $actor): User {
            $oldRole = $user->roles->first()?->name;
            $oldValues = $this->auditSnapshot($user, $oldRole);

            $wasActive = $user->is_active;

            $user->update([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'],
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
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ];
        }

        return [
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_ADMIN,
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
        ];
    }
}
