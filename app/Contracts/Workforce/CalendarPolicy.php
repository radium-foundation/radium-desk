<?php

namespace App\Contracts\Workforce;

use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Active Workforce policy port in Milestone 1.
 * Behaviour must match WorkCalendarService via CalendarPolicyAdapter.
 */
interface CalendarPolicy extends WorkforcePolicy
{
    /**
     * @return list<int>
     */
    public function defaultWeeklyOffDays(): array;

    public function scheduleFor(User $user, ?Carbon $date = null): ?TeamMemberWorkSchedule;

    public function isCompanyHoliday(?Carbon $at = null): bool;

    public function hasApprovedLeave(User $user, ?Carbon $at = null): bool;

    public function isEligibleForAssignment(User $user, ?Carbon $at = null): bool;

    public function isOnScheduledShift(User $user, ?Carbon $at = null): bool;

    public function isExpectedOnDutyWindow(User $user, ?Carbon $at = null): bool;

    public function isWorkingDay(TeamMemberWorkSchedule $schedule, Carbon $at): bool;

    public function isWithinWorkingHours(TeamMemberWorkSchedule $schedule, Carbon $at): bool;

    public function isDuringLunch(TeamMemberWorkSchedule $schedule, Carbon $at): bool;

    public function expectedWorkingMinutes(TeamMemberWorkSchedule $schedule): int;

    public function expectedWorkStartAt(TeamMemberWorkSchedule $schedule, Carbon $date): Carbon;

    public function expectedWorkEndAt(TeamMemberWorkSchedule $schedule, Carbon $date): Carbon;

    public function isLateLogin(User $user, Carbon $loginAt): bool;

    /**
     * @return array{
     *     expected_start: string|null,
     *     expected_end: string|null,
     *     actual_login: string,
     *     minutes_late: int|null,
     *     is_late: bool,
     *     expected_working_minutes: int|null
     * }
     */
    public function compareLoginToSchedule(User $user, Carbon $loginAt): array;

    /**
     * @return array<string, mixed>
     */
    public function todayStatusFor(User $user, ?Carbon $at = null): array;

    public function isOvernightSchedule(TeamMemberWorkSchedule $schedule): bool;

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function resolveShiftWindow(TeamMemberWorkSchedule $schedule, Carbon $at): ?array;
}
