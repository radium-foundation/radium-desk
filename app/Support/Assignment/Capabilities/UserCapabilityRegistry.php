<?php

namespace App\Support\Assignment\Capabilities;

use App\Enums\Assignment\AssignmentCapability;
use Database\Seeders\RolePermissionSeeder;

class UserCapabilityRegistry
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function capabilityDefinitions(): array
    {
        return config('assignment_capabilities.capabilities', []);
    }

    /**
     * @return list<string>
     */
    public function roleSlugsFor(AssignmentCapability $capability): array
    {
        $roles = config("assignment_capabilities.role_mappings.{$capability->value}");

        if (is_array($roles) && $roles !== []) {
            return array_values($roles);
        }

        return match ($capability) {
            AssignmentCapability::SupportAgent => RolePermissionSeeder::SUPPORT_TEAM_ROLES,
            AssignmentCapability::ReadyQueueAdmin,
            AssignmentCapability::AfterHoursSupport,
            AssignmentCapability::IncomingEmailSupervisor,
            AssignmentCapability::WhatsAppSupervisor,
            AssignmentCapability::SalesLeadHandler => [
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            ],
        };
    }

    public function usesSupportPool(AssignmentCapability $capability): bool
    {
        $config = $this->capabilityDefinitions()[$capability->value] ?? null;

        return is_array($config) && ($config['resolver'] ?? null) === 'support_pool';
    }

    public function usesSettingsResolver(AssignmentCapability $capability): bool
    {
        $config = $this->capabilityDefinitions()[$capability->value] ?? null;

        if (! is_array($config)) {
            return false;
        }

        return in_array($config['resolver'] ?? null, [
            'shift_aware_setting',
            'setting_with_fallback',
            'shift_admin',
        ], true);
    }
}
