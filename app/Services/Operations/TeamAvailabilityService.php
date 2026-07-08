<?php

namespace App\Services\Operations;

use App\Enums\TeamAvailabilityStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

class TeamAvailabilityService
{
    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    public function statusFor(User $user): TeamAvailabilityStatus
    {
        $stored = $user->getRawOriginal('availability_status');

        if (! is_string($stored) || $stored === '') {
            $stored = $user->availability_status;
        }

        if ($stored instanceof TeamAvailabilityStatus) {
            return $stored;
        }

        if (is_string($stored) && $stored !== '') {
            if ($stored === 'on_leave') {
                return TeamAvailabilityStatus::Offline;
            }

            return TeamAvailabilityStatus::tryFrom($stored) ?? TeamAvailabilityStatus::Offline;
        }

        return TeamAvailabilityStatus::Offline;
    }

    public function isOnLeave(User $user, ?Carbon $date = null): bool
    {
        return $this->workCalendarService->hasApprovedLeave($user, $date);
    }

    public function updateStatus(User $user, TeamAvailabilityStatus $status): User
    {
        $user->fill([
            'availability_status' => $status,
            'availability_updated_at' => now(),
        ]);
        $user->save();

        return $user->fresh();
    }

    public function syncFromSessionStart(User $user, ?Carbon $at = null): User
    {
        $at ??= now();

        if ($this->workCalendarService->hasApprovedLeave($user, $at)
            || ! $this->workCalendarService->isEligibleForAssignment($user, $at)) {
            return $this->updateStatus($user, TeamAvailabilityStatus::Offline);
        }

        $current = $this->statusFor($user);

        if ($current === TeamAvailabilityStatus::Offline) {
            return $this->updateStatus($user, TeamAvailabilityStatus::Available);
        }

        return $user->fresh();
    }

    public function syncFromSessionEnd(User $user): User
    {
        return $this->updateStatus($user, TeamAvailabilityStatus::Offline);
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(User $user): array
    {
        $status = $this->statusFor($user);

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'badge_class' => $status->badgeClass(),
            'updated_at' => $user->availability_updated_at?->toIso8601String(),
            'on_leave' => $this->isOnLeave($user),
        ];
    }
}
