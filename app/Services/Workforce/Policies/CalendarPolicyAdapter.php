<?php

namespace App\Services\Workforce\Policies;

use App\Contracts\Workforce\CalendarPolicy;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Operations\WorkCalendarService;
use Illuminate\Support\Carbon;

/**
 * Milestone 1 CalendarPolicy — 1:1 pass-through to WorkCalendarService.
 * No behaviour changes.
 */
class CalendarPolicyAdapter implements CalendarPolicy
{
    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    public function defaultWeeklyOffDays(): array
    {
        return $this->workCalendarService->defaultWeeklyOffDays();
    }

    public function scheduleFor(User $user): ?TeamMemberWorkSchedule
    {
        return $this->workCalendarService->scheduleFor($user);
    }

    public function isCompanyHoliday(?Carbon $at = null): bool
    {
        return $this->workCalendarService->isCompanyHoliday($at);
    }

    public function hasApprovedLeave(User $user, ?Carbon $at = null): bool
    {
        return $this->workCalendarService->hasApprovedLeave($user, $at);
    }

    public function isEligibleForAssignment(User $user, ?Carbon $at = null): bool
    {
        return $this->workCalendarService->isEligibleForAssignment($user, $at);
    }

    public function isOnScheduledShift(User $user, ?Carbon $at = null): bool
    {
        return $this->workCalendarService->isOnScheduledShift($user, $at);
    }

    public function isExpectedOnDutyWindow(User $user, ?Carbon $at = null): bool
    {
        return $this->workCalendarService->isExpectedOnDutyWindow($user, $at);
    }

    public function isWorkingDay(TeamMemberWorkSchedule $schedule, Carbon $at): bool
    {
        return $this->workCalendarService->isWorkingDay($schedule, $at);
    }

    public function isWithinWorkingHours(TeamMemberWorkSchedule $schedule, Carbon $at): bool
    {
        return $this->workCalendarService->isWithinWorkingHours($schedule, $at);
    }

    public function isDuringLunch(TeamMemberWorkSchedule $schedule, Carbon $at): bool
    {
        return $this->workCalendarService->isDuringLunch($schedule, $at);
    }

    public function expectedWorkingMinutes(TeamMemberWorkSchedule $schedule): int
    {
        return $this->workCalendarService->expectedWorkingMinutes($schedule);
    }

    public function expectedWorkStartAt(TeamMemberWorkSchedule $schedule, Carbon $date): Carbon
    {
        return $this->workCalendarService->expectedWorkStartAt($schedule, $date);
    }

    public function expectedWorkEndAt(TeamMemberWorkSchedule $schedule, Carbon $date): Carbon
    {
        return $this->workCalendarService->expectedWorkEndAt($schedule, $date);
    }

    public function isLateLogin(User $user, Carbon $loginAt): bool
    {
        return $this->workCalendarService->isLateLogin($user, $loginAt);
    }

    public function compareLoginToSchedule(User $user, Carbon $loginAt): array
    {
        return $this->workCalendarService->compareLoginToSchedule($user, $loginAt);
    }

    public function todayStatusFor(User $user, ?Carbon $at = null): array
    {
        return $this->workCalendarService->todayStatusFor($user, $at);
    }

    public function isOvernightSchedule(TeamMemberWorkSchedule $schedule): bool
    {
        return $this->workCalendarService->isOvernightSchedule($schedule);
    }

    public function resolveShiftWindow(TeamMemberWorkSchedule $schedule, Carbon $at): ?array
    {
        return $this->workCalendarService->resolveShiftWindow($schedule, $at);
    }
}
