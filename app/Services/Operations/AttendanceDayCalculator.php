<?php

namespace App\Services\Operations;

use App\Data\Operations\AttendanceDayResult;
use App\Enums\AttendanceDayStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceDayCalculator
{
    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
        private readonly OperationsRoleService $roleService,
    ) {}

    public function compute(
        User $user,
        Carbon $workDate,
        ?Carbon $referenceAt = null,
        bool $allowPreShiftSkip = true,
    ): ?AttendanceDayResult {
        if (! $this->roleService->isAttendanceTracked($user)) {
            return null;
        }

        $user->loadMissing('workSchedule');

        $workDate = $workDate->copy()->startOfDay();
        $referenceAt ??= now();
        $computedAt = $referenceAt->copy();
        $schedule = $this->workCalendarService->scheduleFor($user);
        $sessions = $this->sessionsFor($user, $workDate);
        $isCompanyHoliday = $this->workCalendarService->isCompanyHoliday($workDate);
        $hasSchedule = $schedule !== null;
        $isWeeklyOff = $hasSchedule && ! $this->workCalendarService->isWorkingDay($schedule, $workDate);
        $isWorkingDay = ! $isCompanyHoliday && ! $isWeeklyOff;
        $isOnLeave = $this->workCalendarService->hasApprovedLeave($user, $workDate);
        $calendarStatus = WorkCalendarDayStatus::from(
            (string) ($this->workCalendarService->todayStatusFor($user, $this->referenceInstant($workDate, $referenceAt))['status'] ?? WorkCalendarDayStatus::NoSchedule->value),
        );
        $expectedWorkingMinutes = $schedule !== null
            ? $this->workCalendarService->expectedWorkingMinutes($schedule)
            : null;
        $sessionMetrics = $this->aggregateSessions($user, $sessions);
        $hasSessions = $sessions->isNotEmpty();
        $openSession = $sessions->first(fn (WorkSession $session): bool => $session->isOpen());

        if (! $hasSessions) {
            if ($allowPreShiftSkip
                && $isWorkingDay
                && ! $isOnLeave
                && $workDate->isSameDay($referenceAt)
                && $schedule !== null
                && $referenceAt->lt($this->workCalendarService->expectedWorkStartAt($schedule, $workDate))
            ) {
                return null;
            }

            $status = match (true) {
                $isOnLeave && $isWorkingDay => AttendanceDayStatus::OnLeave,
                $isCompanyHoliday || ! $isWorkingDay => AttendanceDayStatus::ScheduledOff,
                default => AttendanceDayStatus::NotStarted,
            };

            return $this->buildResult(
                user: $user,
                workDate: $workDate,
                status: $status,
                calendarStatus: $calendarStatus,
                isWorkingDay: $isWorkingDay,
                isCompanyHoliday: $isCompanyHoliday,
                isOnLeave: $isOnLeave,
                hasSchedule: $hasSchedule,
                sessionMetrics: $sessionMetrics,
                expectedWorkingMinutes: $expectedWorkingMinutes,
                openSession: null,
                referenceAt: $referenceAt,
                computedAt: $computedAt,
            );
        }

        if (! $isWorkingDay || $isCompanyHoliday) {
            return $this->buildResult(
                user: $user,
                workDate: $workDate,
                status: AttendanceDayStatus::Extra,
                calendarStatus: $calendarStatus,
                isWorkingDay: $isWorkingDay,
                isCompanyHoliday: $isCompanyHoliday,
                isOnLeave: $isOnLeave,
                hasSchedule: $hasSchedule,
                sessionMetrics: $sessionMetrics,
                expectedWorkingMinutes: $expectedWorkingMinutes,
                openSession: $openSession,
                referenceAt: $referenceAt,
                computedAt: $computedAt,
            );
        }

        if ($isOnLeave) {
            return $this->buildResult(
                user: $user,
                workDate: $workDate,
                status: AttendanceDayStatus::OnLeave,
                calendarStatus: $calendarStatus,
                isWorkingDay: $isWorkingDay,
                isCompanyHoliday: $isCompanyHoliday,
                isOnLeave: $isOnLeave,
                hasSchedule: $hasSchedule,
                sessionMetrics: $sessionMetrics,
                expectedWorkingMinutes: $expectedWorkingMinutes,
                openSession: $openSession,
                referenceAt: $referenceAt,
                computedAt: $computedAt,
            );
        }

        $status = $this->resolveWorkingDayStatus(
            user: $user,
            workDate: $workDate,
            sessions: $sessions,
            openSession: $openSession,
            referenceAt: $referenceAt,
            sessionMetrics: $sessionMetrics,
            schedule: $schedule,
        );

        return $this->buildResult(
            user: $user,
            workDate: $workDate,
            status: $status,
            calendarStatus: $calendarStatus,
            isWorkingDay: $isWorkingDay,
            isCompanyHoliday: $isCompanyHoliday,
            isOnLeave: $isOnLeave,
            hasSchedule: $hasSchedule,
            sessionMetrics: $sessionMetrics,
            expectedWorkingMinutes: $expectedWorkingMinutes,
            openSession: $openSession,
            referenceAt: $referenceAt,
            computedAt: $computedAt,
        );
    }

    /**
     * @return Collection<int, WorkSession>
     */
    private function sessionsFor(User $user, Carbon $workDate): Collection
    {
        return WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->orderBy('login_at')
            ->get();
    }

    /**
     * @param  Collection<int, WorkSession>  $sessions
     * @return array{
     *     first_login_at: ?Carbon,
     *     last_logout_at: ?Carbon,
     *     on_time_login: ?bool,
     *     minutes_late: ?int,
     *     session_count: int,
     *     session_duration_seconds: int,
     *     active_duration_seconds: int,
     *     idle_duration_seconds: int,
     *     lunch_duration_seconds: int,
     *     break_duration_seconds: int,
     *     extra_idle_duration_seconds: int,
     *     overtime_seconds: int,
     *     away_timeout_count: int,
     *     manual_logout_count: int,
     * }
     */
    private function aggregateSessions(User $user, Collection $sessions): array
    {
        $firstSession = $sessions->first();
        $lastClosedSession = $sessions
            ->filter(fn (WorkSession $session): bool => $session->logout_at !== null)
            ->sortByDesc('logout_at')
            ->first();

        $firstLoginAt = $firstSession?->login_at;
        $onTimeLogin = $firstSession?->on_time_login;
        $minutesLate = null;

        if ($firstLoginAt !== null && $onTimeLogin === false) {
            $comparison = $this->workCalendarService->compareLoginToSchedule(
                $user,
                $firstLoginAt,
            );
            $minutesLate = $comparison['minutes_late'];
        }

        return [
            'first_login_at' => $firstLoginAt,
            'last_logout_at' => $lastClosedSession?->logout_at,
            'on_time_login' => $onTimeLogin,
            'minutes_late' => $minutesLate,
            'session_count' => $sessions->count(),
            'session_duration_seconds' => (int) $sessions->sum('session_duration_seconds'),
            'active_duration_seconds' => (int) $sessions->sum('active_duration_seconds'),
            'idle_duration_seconds' => (int) $sessions->sum('idle_duration_seconds'),
            'lunch_duration_seconds' => (int) $sessions->sum('lunch_duration_seconds'),
            'break_duration_seconds' => (int) $sessions->sum('break_duration_seconds'),
            'extra_idle_duration_seconds' => (int) $sessions->sum('extra_idle_duration_seconds'),
            'overtime_seconds' => (int) $sessions->sum('overtime_seconds'),
            'away_timeout_count' => $sessions
                ->where('ended_reason', WorkSessionEndReason::AwayTimeout)
                ->count(),
            'manual_logout_count' => $sessions
                ->where('ended_reason', WorkSessionEndReason::ManualLogout)
                ->count(),
        ];
    }

    /**
     * @param  Collection<int, WorkSession>  $sessions
     * @param  array<string, mixed>  $sessionMetrics
     */
    private function resolveWorkingDayStatus(
        User $user,
        Carbon $workDate,
        Collection $sessions,
        ?WorkSession $openSession,
        Carbon $referenceAt,
        array $sessionMetrics,
        ?TeamMemberWorkSchedule $schedule,
    ): AttendanceDayStatus {
        if ($openSession !== null) {
            if ($this->isAwayDuringOpenSession($openSession, $referenceAt)) {
                return AttendanceDayStatus::Away;
            }

            return AttendanceDayStatus::Active;
        }

        if ($sessionMetrics['on_time_login'] === false) {
            return AttendanceDayStatus::Late;
        }

        if ($sessionMetrics['on_time_login'] === true) {
            return AttendanceDayStatus::Completed;
        }

        if ($schedule !== null
            && $workDate->isSameDay($referenceAt)
            && $referenceAt->lt($this->workCalendarService->expectedWorkStartAt($schedule, $workDate))
        ) {
            return AttendanceDayStatus::OnTime;
        }

        return AttendanceDayStatus::Completed;
    }

    private function isAwayDuringOpenSession(WorkSession $session, Carbon $referenceAt): bool
    {
        if (! $session->isOpen() || $session->last_activity_at === null) {
            return false;
        }

        $awayTimeoutMinutes = max(
            1,
            (int) config('presence.away_timeout_minutes', 15),
        );

        return $session->last_activity_at->lte(
            $referenceAt->copy()->subMinutes($awayTimeoutMinutes),
        );
    }

    /**
     * @param  array<string, mixed>  $sessionMetrics
     */
    private function buildResult(
        User $user,
        Carbon $workDate,
        AttendanceDayStatus $status,
        WorkCalendarDayStatus $calendarStatus,
        bool $isWorkingDay,
        bool $isCompanyHoliday,
        bool $isOnLeave,
        bool $hasSchedule,
        array $sessionMetrics,
        ?int $expectedWorkingMinutes,
        ?WorkSession $openSession,
        Carbon $referenceAt,
        Carbon $computedAt,
    ): AttendanceDayResult {
        return new AttendanceDayResult(
            userId: $user->id,
            workDate: $workDate,
            status: $status,
            calendarStatus: $calendarStatus,
            isWorkingDay: $isWorkingDay,
            isCompanyHoliday: $isCompanyHoliday,
            isOnLeave: $isOnLeave,
            hasSchedule: $hasSchedule,
            firstLoginAt: $sessionMetrics['first_login_at'],
            lastLogoutAt: $sessionMetrics['last_logout_at'],
            onTimeLogin: $sessionMetrics['on_time_login'],
            minutesLate: $sessionMetrics['minutes_late'],
            sessionCount: (int) $sessionMetrics['session_count'],
            sessionDurationSeconds: (int) $sessionMetrics['session_duration_seconds'],
            activeDurationSeconds: (int) $sessionMetrics['active_duration_seconds'],
            idleDurationSeconds: (int) $sessionMetrics['idle_duration_seconds'],
            lunchDurationSeconds: (int) $sessionMetrics['lunch_duration_seconds'],
            breakDurationSeconds: (int) $sessionMetrics['break_duration_seconds'],
            extraIdleDurationSeconds: (int) $sessionMetrics['extra_idle_duration_seconds'],
            overtimeSeconds: (int) $sessionMetrics['overtime_seconds'],
            awayTimeoutCount: (int) $sessionMetrics['away_timeout_count'],
            manualLogoutCount: (int) $sessionMetrics['manual_logout_count'],
            expectedWorkingMinutes: $expectedWorkingMinutes,
            finalizedAt: $this->resolveFinalizedAt(
                workDate: $workDate,
                openSession: $openSession,
                referenceAt: $referenceAt,
                computedAt: $computedAt,
                schedule: $this->workCalendarService->scheduleFor($user),
            ),
            computedAt: $computedAt,
            sourceVersion: $this->sourceVersion(),
        );
    }

    private function resolveFinalizedAt(
        Carbon $workDate,
        ?WorkSession $openSession,
        Carbon $referenceAt,
        Carbon $computedAt,
        ?TeamMemberWorkSchedule $schedule,
    ): ?Carbon {
        if ($openSession !== null) {
            return null;
        }

        if (! $workDate->isSameDay($referenceAt)) {
            return $computedAt;
        }

        if ($schedule === null) {
            return $workDate->copy()->endOfDay()->lte($referenceAt) ? $computedAt : null;
        }

        $shiftEnd = $this->workCalendarService->expectedWorkEndAt($schedule, $workDate);

        return $referenceAt->gte($shiftEnd) ? $computedAt : null;
    }

    private function referenceInstant(Carbon $workDate, Carbon $referenceAt): Carbon
    {
        if ($workDate->isSameDay($referenceAt)) {
            return $referenceAt->copy();
        }

        return $workDate->copy()->endOfDay();
    }

    private function sourceVersion(): int
    {
        return max(1, (int) config('workforce_calendar.attendance_calculator_version', 1));
    }
}
