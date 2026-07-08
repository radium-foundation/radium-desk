<?php

namespace App\Services\Operations;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class OperationsRoleService
{
    /**
     * @return list<string>
     */
    public function legacyRoleSlugs(): array
    {
        return [
            RolePermissionSeeder::ROLE_SUPERADMIN,
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_AGENT,
        ];
    }

    /**
     * @return list<string>
     */
    public function operationalRoleSlugs(): array
    {
        return [
            RolePermissionSeeder::ROLE_SUPERADMIN,
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
            RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
        ];
    }

    public function displayLabel(?string $roleSlug): string
    {
        if ($roleSlug === null || $roleSlug === '') {
            return '';
        }

        return config("operations.roles.{$roleSlug}.label", ucfirst(str_replace('_', ' ', $roleSlug)));
    }

    public function usesAdminQueues(User $user): bool
    {
        return $user->hasAnyRole([
            RolePermissionSeeder::ROLE_SUPERADMIN,
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
        ]);
    }

    public function usesSupportQueues(User $user): bool
    {
        if ($this->usesAdminQueues($user)) {
            return false;
        }

        return $user->hasAnyRole([
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
            RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
        ]);
    }

    public function isHardwareTeam(User $user): bool
    {
        return $user->hasRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
    }

    public function isTeamMember(User $user): bool
    {
        return $user->hasAnyRole($this->operationalRoleSlugs());
    }

    public function isAttendanceTracked(User $user): bool
    {
        return $user->hasAnyRole([
            ...RolePermissionSeeder::SUPPORT_TEAM_ROLES,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
        ]);
    }

    public function isNormalAssignmentPool(User $user): bool
    {
        return $user->hasAnyRole(RolePermissionSeeder::SUPPORT_TEAM_ROLES);
    }

    /**
     * @return list<string>
     */
    public function attendanceTrackedRoleSlugs(): array
    {
        return [
            ...RolePermissionSeeder::SUPPORT_TEAM_ROLES,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
        ];
    }
}
