<?php

namespace App\Support\Assignment\Capabilities;

use App\Enums\Assignment\AssignmentCapability;
use App\Models\User;
use App\Models\UserAssignmentCapability;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class UserCapabilityService
{
    public function __construct(
        private readonly UserCapabilityRegistry $registry,
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly SettingService $settingService,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function eligibleUsers(AssignmentCapability $capability, ?Carbon $at = null): Collection
    {
        $at ??= now();

        $explicit = $this->explicitCapabilityUsers($capability);

        if ($explicit->isNotEmpty()) {
            return $explicit;
        }

        if ($this->registry->usesSupportPool($capability)) {
            return collect($this->assignmentService->activeSupportAgents($at));
        }

        if ($this->registry->usesSettingsResolver($capability)) {
            $resolved = $this->resolveFromSettings($capability, $at);

            return $resolved !== null
                ? collect([$resolved])
                : collect();
        }

        return $this->roleBasedUsers($capability);
    }

    public function userHasCapability(User $user, AssignmentCapability $capability, ?Carbon $at = null): bool
    {
        if (! $user->is_active || $user->trashed()) {
            return false;
        }

        if ($this->hasExplicitCapability($user, $capability)) {
            return true;
        }

        return $this->eligibleUsers($capability, $at)
            ->contains(fn (User $candidate): bool => $candidate->id === $user->id);
    }

    public function grant(User $user, AssignmentCapability $capability): UserAssignmentCapability
    {
        return UserAssignmentCapability::query()->firstOrCreate([
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }

    public function revoke(User $user, AssignmentCapability $capability): void
    {
        UserAssignmentCapability::query()
            ->where('user_id', $user->id)
            ->where('capability', $capability)
            ->delete();
    }

    /**
     * @return Collection<int, User>
     */
    private function explicitCapabilityUsers(AssignmentCapability $capability): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('assignmentCapabilities', fn ($query) => $query->where('capability', $capability->value))
            ->orderBy('id')
            ->get();
    }

    private function hasExplicitCapability(User $user, AssignmentCapability $capability): bool
    {
        if ($user->relationLoaded('assignmentCapabilities')) {
            return $user->assignmentCapabilities
                ->contains(fn (UserAssignmentCapability $record): bool => $record->capability === $capability);
        }

        return UserAssignmentCapability::query()
            ->where('user_id', $user->id)
            ->where('capability', $capability->value)
            ->exists();
    }

    /**
     * @return Collection<int, User>
     */
    private function roleBasedUsers(AssignmentCapability $capability): Collection
    {
        $roles = $this->registry->roleSlugsFor($capability);

        if ($roles === []) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->role($roles)
            ->orderBy('id')
            ->get();
    }

    private function resolveFromSettings(AssignmentCapability $capability, Carbon $at): ?User
    {
        $config = $this->registry->capabilityDefinitions()[$capability->value] ?? null;

        if (! is_array($config)) {
            return null;
        }

        return match ($config['resolver'] ?? null) {
            'shift_admin' => $this->assignmentService->resolveAssigneeOrNull($at),
            'shift_aware_setting' => $this->resolveShiftAwareSetting($config, $at),
            'setting_with_fallback' => $this->resolveSettingWithFallback($config, $at, $capability),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveShiftAwareSetting(array $config, Carbon $at): ?User
    {
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

        if (($config['fallback_resolver'] ?? null) === 'shift_admin') {
            return $this->assignmentService->resolveAssigneeOrNull($at);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolveSettingWithFallback(array $config, Carbon $at, AssignmentCapability $capability): ?User
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

        if ($fallbackCapability === null || $fallbackCapability === $capability) {
            return null;
        }

        return $this->resolveFromSettings($fallbackCapability, $at);
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
}
