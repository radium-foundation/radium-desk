<?php

namespace App\Support\Assignment;

use App\Enums\Assignment\AssignmentCapability;
use App\Models\User;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use App\Support\Assignment\Capabilities\UserCapabilityService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AssignmentCapabilityResolver
{
    public function __construct(
        private readonly SettingService $settingService,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly UserCapabilityService $userCapabilityService,
    ) {}

    public function resolve(AssignmentCapability $capability, ?Carbon $at = null): ?User
    {
        $config = config("assignment_capabilities.capabilities.{$capability->value}");

        if (! is_array($config)) {
            return null;
        }

        return match ($config['resolver'] ?? null) {
            'shift_admin' => $this->assignmentService->resolveAssigneeOrNull($at),
            'shift_aware_setting' => $this->resolveShiftAwareSetting($config, $at),
            'setting_with_fallback' => $this->resolveSettingWithFallback($config, $at),
            'support_pool' => null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveShiftAwareSetting(array $config, ?Carbon $at): ?User
    {
        $at ??= now();
        $localized = $at->copy()->timezone($this->settingService->get('assignment.timezone', config('app.timezone')));
        $time = $localized->format('H:i');
        $start = $this->settingService->get('assignment.day_shift_start', '09:00');
        $end = $this->settingService->get('assignment.day_shift_end', '18:30');
        $withinDayShift = $time >= $start && $time <= $end;

        $settingKey = $withinDayShift
            ? ($config['day_setting_key'] ?? null)
            : ($config['night_setting_key'] ?? null);

        if (is_string($settingKey)) {
            $user = $this->resolveActiveUserBySetting($settingKey);

            if ($user !== null) {
                return $user;
            }
        }

        $fallbackResolver = $config['fallback_resolver'] ?? null;

        if ($fallbackResolver === 'shift_admin') {
            return $this->assignmentService->resolveAssigneeOrNull($at);
        }

        return null;
    }

    private function resolveActiveUserBySetting(string $settingKey): ?User
    {
        $userId = $this->settingService->getInt($settingKey);

        if ($userId <= 0) {
            return null;
        }

        return User::query()
            ->whereKey($userId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveSettingWithFallback(array $config, ?Carbon $at): ?User
    {
        $settingKey = $config['setting_key'] ?? null;

        if (is_string($settingKey)) {
            $user = $this->resolveActiveUserBySetting($settingKey);

            if ($user !== null) {
                return $user;
            }
        }

        if (($config['fallback_resolver'] ?? null) === 'shift_admin') {
            return $this->assignmentService->resolveAssigneeOrNull($at);
        }

        $fallback = $config['fallback_capability'] ?? null;

        if (! is_string($fallback)) {
            return null;
        }

        $fallbackCapability = AssignmentCapability::tryFrom($fallback);

        if ($fallbackCapability === null || $fallbackCapability->value === ($config['capability'] ?? '')) {
            return null;
        }

        return $this->resolve($fallbackCapability, $at);
    }

    public function isWithinSupportHours(?Carbon $at = null): bool
    {
        $at ??= now();
        $localized = $at->copy()->timezone($this->settingService->get('assignment.timezone', config('app.timezone')));
        $time = $localized->format('H:i');
        $start = $this->settingService->get('assignment.day_shift_start', '09:00');
        $end = $this->settingService->get('assignment.day_shift_end', '18:30');

        return $time >= $start && $time <= $end;
    }

    /**
     * @return list<User>
     */
    public function supportAgentPool(?Carbon $at = null): array
    {
        return $this->assignmentService->activeSupportAgents($at);
    }

    /**
     * @return Collection<int, User>
     */
    public function eligibleUsers(AssignmentCapability $capability, ?Carbon $at = null): Collection
    {
        return $this->userCapabilityService->eligibleUsers($capability, $at);
    }

    public function isAdminCapable(User $user): bool
    {
        return $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);
    }
}
