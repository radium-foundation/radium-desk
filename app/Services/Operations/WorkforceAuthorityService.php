<?php

namespace App\Services\Operations;

use App\Enums\PresenceStatus;
use App\Enums\TeamAvailabilityStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

class WorkforceAuthorityService
{
    /** @var array<string, array<string, mixed>> */
    private array $snapshotCache = [];

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
        $cacheKey = $user->id.'|'.($at?->getTimestamp() ?? 'now');

        return $this->snapshotCache[$cacheKey] ??= $this->buildSnapshot($user, $at);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(User $user, ?Carbon $at = null): array
    {
        // Compute shared calendar/presence/availability inputs once for this snapshot.
        $calendarAllows = $this->calendarAllows($user, $at);
        $onApprovedLeave = $this->isOnApprovedLeave($user, $at);
        $isPresent = $this->isPresent($user, $at);
        $storedAvailability = $this->availabilityService->statusFor($user);
        $availabilitySnapshot = $this->availabilityService->snapshotFor($user);
        $workCalendar = $this->workCalendarService->todayStatusFor($user, $at);
        $presence = $this->presenceEngine->snapshotFor($user, $at);

        if ($onApprovedLeave || ! $calendarAllows || ! $isPresent) {
            $effectiveAvailability = TeamAvailabilityStatus::Offline;
        } else {
            $effectiveAvailability = $storedAvailability;
        }

        $onDuty = in_array($effectiveAvailability, [
            TeamAvailabilityStatus::Available,
            TeamAvailabilityStatus::Busy,
        ], true);

        $blockReasons = [];

        if (! $calendarAllows) {
            $blockReasons[] = 'calendar_blocked';
        }

        if ($onApprovedLeave) {
            $blockReasons[] = 'approved_leave';
        }

        if (! $isPresent) {
            $blockReasons[] = 'not_present';
        }

        if ($storedAvailability === TeamAvailabilityStatus::Offline) {
            $blockReasons[] = 'availability_offline';
        }

        if (! $user->is_active || $user->trashed()) {
            $blockReasons[] = 'inactive_user';
        }

        if (! $this->roleService->isNormalAssignmentPool($user)) {
            $blockReasons[] = 'not_assignment_pool';
        }

        $eligibleForNormalAssignment = $user->is_active
            && ! $user->trashed()
            && $this->roleService->isNormalAssignmentPool($user)
            && $onDuty;

        return [
            'calendar_allows' => $calendarAllows,
            'on_approved_leave' => $onApprovedLeave,
            'is_present' => $isPresent,
            'stored_availability' => $storedAvailability->value,
            'stored_availability_label' => $storedAvailability->label(),
            'effective_availability' => $effectiveAvailability->value,
            'effective_availability_label' => $effectiveAvailability->label(),
            'on_duty' => $onDuty,
            'eligible_for_normal_assignment' => $eligibleForNormalAssignment,
            'block_reasons' => $blockReasons,
            'work_calendar' => $workCalendar,
            'presence' => $presence,
            'availability' => $availabilitySnapshot,
        ];
    }
}
