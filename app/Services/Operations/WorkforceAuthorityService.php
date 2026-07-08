<?php

namespace App\Services\Operations;

use App\Enums\PresenceStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

class WorkforceAuthorityService
{
    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
        private readonly TeamAvailabilityService $availabilityService,
        private readonly PresenceEngineService $presenceEngine,
        private readonly OperationsRoleService $roleService,
    ) {}

    public function calendarAllows(User $user, ?Carbon $at = null): bool
    {
        return $this->workCalendarService->isEligibleForAssignment($user, $at);
    }

    public function isOnApprovedLeave(User $user, ?Carbon $at = null): bool
    {
        return $this->workCalendarService->hasApprovedLeave($user, $at);
    }

    public function isPresent(User $user, ?Carbon $at = null): bool
    {
        if ($this->presenceEngine->openSessionFor($user) === null) {
            return false;
        }

        return $this->presenceEngine->presenceStatus($user, $at) !== PresenceStatus::Away;
    }

    public function effectiveAvailability(User $user, ?Carbon $at = null): TeamAvailabilityStatus
    {
        if ($this->isOnApprovedLeave($user, $at)) {
            return TeamAvailabilityStatus::Offline;
        }

        if (! $this->calendarAllows($user, $at)) {
            return TeamAvailabilityStatus::Offline;
        }

        if (! $this->isPresent($user, $at)) {
            return TeamAvailabilityStatus::Offline;
        }

        return $this->availabilityService->statusFor($user);
    }

    public function isOnDuty(User $user, ?Carbon $at = null): bool
    {
        return in_array($this->effectiveAvailability($user, $at), [
            TeamAvailabilityStatus::Available,
            TeamAvailabilityStatus::Busy,
        ], true);
    }

    public function isEligibleForNormalAssignment(User $user, ?Carbon $at = null): bool
    {
        if (! $user->is_active || $user->trashed()) {
            return false;
        }

        if (! $this->roleService->isNormalAssignmentPool($user)) {
            return false;
        }

        return $this->isOnDuty($user, $at);
    }

    /**
     * @return list<string>
     */
    public function blockReasons(User $user, ?Carbon $at = null): array
    {
        $reasons = [];

        if (! $this->calendarAllows($user, $at)) {
            $reasons[] = 'calendar_blocked';
        }

        if ($this->isOnApprovedLeave($user, $at)) {
            $reasons[] = 'approved_leave';
        }

        if (! $this->isPresent($user, $at)) {
            $reasons[] = 'not_present';
        }

        if ($this->availabilityService->statusFor($user) === TeamAvailabilityStatus::Offline) {
            $reasons[] = 'availability_offline';
        }

        if (! $user->is_active || $user->trashed()) {
            $reasons[] = 'inactive_user';
        }

        if (! $this->roleService->isNormalAssignmentPool($user)) {
            $reasons[] = 'not_assignment_pool';
        }

        return $reasons;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(User $user, ?Carbon $at = null): array
    {
        $storedAvailability = $this->availabilityService->statusFor($user);
        $effectiveAvailability = $this->effectiveAvailability($user, $at);

        return [
            'calendar_allows' => $this->calendarAllows($user, $at),
            'on_approved_leave' => $this->isOnApprovedLeave($user, $at),
            'is_present' => $this->isPresent($user, $at),
            'stored_availability' => $storedAvailability->value,
            'stored_availability_label' => $storedAvailability->label(),
            'effective_availability' => $effectiveAvailability->value,
            'effective_availability_label' => $effectiveAvailability->label(),
            'on_duty' => $this->isOnDuty($user, $at),
            'eligible_for_normal_assignment' => $this->isEligibleForNormalAssignment($user, $at),
            'block_reasons' => $this->blockReasons($user, $at),
            'work_calendar' => $this->workCalendarService->todayStatusFor($user, $at),
            'presence' => $this->presenceEngine->snapshotFor($user, $at),
            'availability' => $this->availabilityService->snapshotFor($user),
        ];
    }
}
