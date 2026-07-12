<?php

namespace App\Services\Operations;

use App\Data\Operations\AppointmentReminderConfiguration;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

class AppointmentReminderConfigurationResolver
{
    public function globalEnabled(): bool
    {
        return (bool) $this->settings()['enabled'];
    }

    public function forUser(User $user): AppointmentReminderConfiguration
    {
        if (! $this->globalEnabled()) {
            return new AppointmentReminderConfiguration(enabled: false, thresholdsMinutes: []);
        }

        return new AppointmentReminderConfiguration(
            enabled: true,
            thresholdsMinutes: $this->thresholdsForRole($this->resolveRoleKey($user)),
        );
    }

    /**
     * @return list<int>
     */
    public function thresholdsForRole(string $roleKey): array
    {
        $roleThresholds = $this->settings()['role_thresholds_minutes'] ?? [];
        $thresholds = $roleThresholds[$roleKey] ?? $roleThresholds['default'] ?? [30, 10, 0];

        return $this->normalizeThresholds($thresholds);
    }

    private function resolveRoleKey(User $user): string
    {
        if ($user->hasRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST)) {
            return 'escalation_specialist';
        }

        if ($user->hasRole(RolePermissionSeeder::ROLE_ADMIN)) {
            return 'admin';
        }

        if ($user->hasRole(RolePermissionSeeder::ROLE_AGENT)) {
            return 'agent';
        }

        if ($user->hasRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST)) {
            return 'support_specialist';
        }

        return 'default';
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return config('team_telegram.appointment_reminders', []);
    }

    /**
     * @return list<int>
     */
    private function normalizeThresholds(mixed $thresholds): array
    {
        if ($thresholds === []) {
            return [];
        }

        if (! is_array($thresholds)) {
            return [30, 10, 0];
        }

        $normalized = [];

        foreach ($thresholds as $threshold) {
            if (! is_numeric($threshold)) {
                continue;
            }

            $normalized[] = max(0, (int) $threshold);
        }

        $normalized = array_values(array_unique($normalized));
        rsort($normalized);

        return $normalized !== [] ? $normalized : [30, 10, 0];
    }
}
