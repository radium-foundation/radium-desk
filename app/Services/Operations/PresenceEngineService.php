<?php

namespace App\Services\Operations;

use App\Enums\PresenceActivityType;
use App\Enums\PresenceStatus;
use App\Enums\TeamAvailabilityChangeSource;
use App\Enums\WorkSessionEndReason;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PresenceEngineService
{
    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
        private readonly OperationsRoleService $roleService,
        private readonly TeamAvailabilityService $availabilityService,
    ) {}

    public function startSession(User $user, ?Carbon $at = null): ?WorkSession
    {
        if (! $this->tracksPresence($user)) {
            return null;
        }

        $at ??= now();

        $openSession = $this->openSessionFor($user);

        if ($openSession !== null) {
            return $openSession;
        }

        $schedule = $this->workCalendarService->scheduleFor($user);
        $breakAllowanceSeconds = $this->breakAllowanceSeconds($schedule);

        $session = WorkSession::query()->create([
            'user_id' => $user->id,
            'work_date' => $at->toDateString(),
            'login_at' => $at,
            'last_activity_at' => $at,
            'last_tick_at' => $at,
            'break_allowance_seconds' => $breakAllowanceSeconds,
            'expected_working_minutes' => $schedule !== null
                ? $this->workCalendarService->expectedWorkingMinutes($schedule)
                : null,
            'on_time_login' => $schedule !== null
                ? ! $this->workCalendarService->isLateLogin($user, $at)
                : null,
        ]);

        $this->availabilityService->syncFromSessionStart($user, $at);

        $this->refreshAttendanceRegister($user, $at, $session);

        app(DeferredSmartAssignmentService::class)->processPendingBatch();

        return $session;
    }

    public function closeSession(
        User $user,
        WorkSessionEndReason $reason,
        ?Carbon $at = null,
    ): ?WorkSession {
        $session = $this->openSessionFor($user);

        if ($session === null) {
            return null;
        }

        $at ??= now();

        $this->tickSession($session, $at, hasActivity: false);
        $this->finalizeSession($session, $at, $reason);
        $this->availabilityService->syncFromSessionEnd(
            $user,
            $reason === WorkSessionEndReason::AwayTimeout
                ? TeamAvailabilityChangeSource::Timeout
                : TeamAvailabilityChangeSource::Logout,
        );

        $this->refreshAttendanceRegister($user, $at, $session);

        return $session->fresh();
    }

    public function recordActivity(
        User $user,
        PresenceActivityType $type = PresenceActivityType::System,
        ?Carbon $at = null,
    ): ?WorkSession {
        if (! $this->tracksPresence($user)) {
            return null;
        }

        $at ??= now();
        $session = $this->openSessionFor($user) ?? $this->startSession($user, $at);

        if ($session === null) {
            return null;
        }

        if (! $at->isSameDay($session->work_date)) {
            $this->closeSession(
                $user,
                WorkSessionEndReason::SessionReplaced,
                $session->work_date->copy()->endOfDay(),
            );
            $session = $this->startSession($user, $at);

            if ($session === null) {
                return null;
            }
        }

        $this->tickSession($session, $at, hasActivity: true);
        $this->incrementAppraisalCounters($session, $type);
        $session->refresh();

        return $session;
    }

    public function presenceStatus(User $user, ?Carbon $at = null): PresenceStatus
    {
        $at ??= now();
        $inactivityMinutes = $this->inactivityMinutes($user, $at);

        return $this->statusFromInactivityMinutes($inactivityMinutes);
    }

    public function inactivityMinutes(User $user, ?Carbon $at = null): int
    {
        $at ??= now();
        $lastActivity = $this->lastActivityAt($user);

        if ($lastActivity === null) {
            return 0;
        }

        return max(0, (int) $lastActivity->diffInMinutes($at));
    }

    public function shouldForceLogout(User $user, ?Carbon $at = null): bool
    {
        if (! $this->tracksPresence($user)) {
            return false;
        }

        if ($this->openSessionFor($user) === null) {
            return false;
        }

        return $this->presenceStatus($user, $at) === PresenceStatus::Away;
    }

    public function forceLogoutUser(User $user, ?Request $request = null): void
    {
        $this->closeSession($user, WorkSessionEndReason::AwayTimeout);

        DB::table('sessions')->where('user_id', $user->id)->delete();

        if ($request !== null && Auth::id() === $user->id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }

    public function processTimedOutSessions(?Carbon $at = null): int
    {
        $at ??= now();
        $processed = 0;

        WorkSession::query()
            ->with('user')
            ->whereNull('logout_at')
            ->where('last_activity_at', '<=', $at->copy()->subMinutes($this->awayTimeoutMinutes()))
            ->orderBy('id')
            ->each(function (WorkSession $session) use ($at, &$processed): void {
                $user = $session->user;

                if ($user === null || ! $this->tracksPresence($user)) {
                    return;
                }

                if ($this->presenceStatus($user, $at) !== PresenceStatus::Away) {
                    return;
                }

                $this->forceLogoutUser($user);
                $processed++;
            });

        return $processed;
    }

    public function openSessionFor(User $user): ?WorkSession
    {
        return WorkSession::query()
            ->where('user_id', $user->id)
            ->whereNull('logout_at')
            ->latest('login_at')
            ->first();
    }

    public function todaySessionFor(User $user, ?Carbon $at = null): ?WorkSession
    {
        $at ??= now();

        return WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $at->toDateString())
            ->latest('login_at')
            ->first();
    }

    public function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0m';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', max(1, $minutes));
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotFor(User $user, ?Carbon $at = null): array
    {
        $at ??= now();
        $status = $this->presenceStatus($user, $at);
        $session = $this->todaySessionFor($user, $at);
        $inactivityMinutes = $this->inactivityMinutes($user, $at);
        $workActivity = app(TeamMemberActivityService::class)->primaryWorkActivity($user);

        return [
            'status' => $status->value,
            'label' => $status->label(),
            'indicator' => $status->indicator(),
            'badge_class' => $status->badgeClass(),
            'inactivity_minutes' => $inactivityMinutes,
            'login_at' => $session?->login_at?->format('H:i'),
            'login_at_iso' => $session?->login_at?->toIso8601String(),
            'active_duration' => $this->formatDuration((int) ($session?->active_duration_seconds ?? 0)),
            'idle_duration' => $this->formatDuration((int) ($session?->idle_duration_seconds ?? 0)),
            'lunch_duration' => $this->formatDuration((int) ($session?->lunch_duration_seconds ?? 0)),
            'break_duration' => $this->formatDuration((int) ($session?->break_duration_seconds ?? 0)),
            'extra_idle_duration' => $this->formatDuration((int) ($session?->extra_idle_duration_seconds ?? 0)),
            'overtime_duration' => $this->formatDuration((int) ($session?->overtime_seconds ?? 0)),
            'cases_handled_count' => (int) ($session?->cases_handled_count ?? 0),
            'communication_events_count' => (int) ($session?->communication_events_count ?? 0),
            'resolution_events_count' => (int) ($session?->resolution_events_count ?? 0),
            'last_work_activity_label' => $workActivity['label'] ?? null,
            'last_work_activity_at' => isset($workActivity['at'])
                ? $workActivity['at']->format('H:i')
                : null,
            'session_open' => $session?->isOpen() ?? false,
            'current_incident_id' => $session?->current_incident_id,
            'on_time_login' => $session?->on_time_login,
        ];
    }

    public function tickSession(WorkSession $session, Carbon $at, bool $hasActivity = false): void
    {
        $session->loadMissing('user');
        $user = $session->user;

        if ($user === null) {
            return;
        }

        if ($session->last_tick_at === null) {
            $session->last_tick_at = $at;

            if ($hasActivity) {
                $session->last_activity_at = $at;
            }

            $session->save();

            return;
        }

        if ($at->lte($session->last_tick_at)) {
            if ($hasActivity) {
                $session->last_activity_at = $at;
                $session->save();
            }

            return;
        }

        $this->accumulatePeriod(
            session: $session,
            user: $user,
            from: $session->last_tick_at,
            to: $at,
            referenceActivityAt: $session->last_activity_at,
        );

        $session->last_tick_at = $at;

        if ($hasActivity) {
            $session->last_activity_at = $at;
        }

        $session->save();
    }

    private function accumulatePeriod(
        WorkSession $session,
        User $user,
        Carbon $from,
        Carbon $to,
        ?Carbon $referenceActivityAt,
    ): void {
        $cursor = $from->copy();

        while ($cursor->lt($to)) {
            $chunkEnd = $this->nextChunkBoundary($user, $cursor, $to);
            $chunkSeconds = max(0, (int) $cursor->diffInSeconds($chunkEnd));

            if ($chunkSeconds === 0) {
                $cursor = $chunkEnd;

                continue;
            }

            if ($this->isDuringLunch($user, $cursor)) {
                $session->lunch_duration_seconds += $chunkSeconds;
            } else {
                $inactivityMinutes = $referenceActivityAt !== null
                    ? max(0, (int) $referenceActivityAt->diffInMinutes($cursor))
                    : 0;

                $presence = $this->statusFromInactivityMinutes($inactivityMinutes);

                if ($presence === PresenceStatus::Active) {
                    $session->active_duration_seconds += $chunkSeconds;
                } else {
                    $this->accumulateIdleSeconds($session, $chunkSeconds);
                }
            }

            $cursor = $chunkEnd;
        }
    }

    private function accumulateIdleSeconds(WorkSession $session, int $seconds): void
    {
        $session->idle_duration_seconds += $seconds;

        $remainingBreakAllowance = max(
            0,
            (int) $session->break_allowance_seconds - (int) $session->break_duration_seconds,
        );

        if ($remainingBreakAllowance > 0) {
            $breakChunk = min($seconds, $remainingBreakAllowance);
            $session->break_duration_seconds += $breakChunk;
            $session->extra_idle_duration_seconds += max(0, $seconds - $breakChunk);

            return;
        }

        $session->extra_idle_duration_seconds += $seconds;
    }

    private function nextChunkBoundary(User $user, Carbon $cursor, Carbon $to): Carbon
    {
        $schedule = $this->workCalendarService->scheduleFor($user);

        if ($schedule === null) {
            return $to->copy();
        }

        $boundaries = collect([
            $this->timeBoundaryOnDate($schedule->lunch_start_time, $cursor),
            $this->timeBoundaryOnDate($schedule->lunch_end_time, $cursor),
        ])
            ->filter(fn (?Carbon $boundary): bool => $boundary !== null && $boundary->gt($cursor) && $boundary->lt($to))
            ->sortBy(fn (Carbon $boundary): int => $boundary->getTimestamp())
            ->first();

        return $boundaries ?? $to->copy();
    }

    private function timeBoundaryOnDate(mixed $time, Carbon $date): ?Carbon
    {
        if ($time === null) {
            return null;
        }

        return $date->copy()->startOfDay()->setTimeFromTimeString($this->normalizeTimeString($time));
    }

    private function isDuringLunch(User $user, Carbon $at): bool
    {
        $schedule = $this->workCalendarService->scheduleFor($user);

        if ($schedule === null) {
            return false;
        }

        return $this->workCalendarService->isDuringLunch($schedule, $at);
    }

    private function finalizeSession(
        WorkSession $session,
        Carbon $at,
        WorkSessionEndReason $reason,
    ): void {
        $session->loadMissing('user');
        $user = $session->user;

        $session->logout_at = $at;
        $session->ended_reason = $reason;
        $session->session_duration_seconds = max(0, (int) $session->login_at->diffInSeconds($at));
        $session->overtime_seconds = $this->calculateOvertimeSeconds($user, $session, $at);
        $session->save();
    }

    private function calculateOvertimeSeconds(?User $user, WorkSession $session, Carbon $logoutAt): int
    {
        if ($user === null) {
            return 0;
        }

        $schedule = $this->workCalendarService->scheduleFor($user);

        if ($schedule === null) {
            return 0;
        }

        $workDate = $session->work_date->copy()->startOfDay();
        $expectedEnd = $this->workCalendarService->expectedWorkEndAt($schedule, $workDate);
        $effectiveLogout = $logoutAt->gt($workDate->copy()->endOfDay())
            ? $workDate->copy()->endOfDay()
            : $logoutAt;

        if ($effectiveLogout->lte($expectedEnd)) {
            return 0;
        }

        return max(0, (int) $expectedEnd->diffInSeconds($effectiveLogout));
    }

    private function incrementAppraisalCounters(WorkSession $session, PresenceActivityType $type): void
    {
        match ($type) {
            PresenceActivityType::CaseAction => $session->increment('cases_handled_count'),
            PresenceActivityType::CustomerCommunication => $session->increment('communication_events_count'),
            PresenceActivityType::StatusChange => $session->increment('resolution_events_count'),
            PresenceActivityType::System, PresenceActivityType::Heartbeat => null,
        };
    }

    private function lastActivityAt(User $user): ?Carbon
    {
        $session = $this->openSessionFor($user);

        return $session?->last_activity_at ?? $user->last_active_at;
    }

    private function statusFromInactivityMinutes(int $inactivityMinutes): PresenceStatus
    {
        if ($inactivityMinutes < $this->activeThresholdMinutes()) {
            return PresenceStatus::Active;
        }

        if ($inactivityMinutes < $this->awayTimeoutMinutes()) {
            return PresenceStatus::Idle;
        }

        return PresenceStatus::Away;
    }

    private function breakAllowanceSeconds(?TeamMemberWorkSchedule $schedule): int
    {
        if ($schedule === null) {
            return (int) config('workforce_calendar.default_short_break_count', 2)
                * (int) config('workforce_calendar.default_short_break_minutes', 10)
                * 60;
        }

        return max(0, (int) $schedule->short_break_count) * max(0, (int) $schedule->short_break_minutes) * 60;
    }

    private function tracksPresence(User $user): bool
    {
        return $this->roleService->isAttendanceTracked($user);
    }

    private function activeThresholdMinutes(): int
    {
        return max(1, (int) config('presence.active_threshold_minutes', 5));
    }

    private function awayTimeoutMinutes(): int
    {
        return max(
            $this->activeThresholdMinutes() + 1,
            (int) config('presence.away_timeout_minutes', 15),
        );
    }

    private function normalizeTimeString(mixed $time): string
    {
        $value = (string) $time;

        if (strlen($value) === 5) {
            return $value.':00';
        }

        return $value;
    }

    private function refreshAttendanceRegister(User $user, Carbon $at, ?WorkSession $session = null): void
    {
        $register = app(AttendanceRegisterService::class);
        $startDate = $at->copy()->startOfDay();

        if ($session !== null) {
            $sessionDate = $session->work_date->copy()->startOfDay();

            if ($sessionDate->lt($startDate)) {
                $startDate = $sessionDate;
            }
        }

        $cursor = $startDate->copy();
        $endDate = $at->copy()->startOfDay();

        while ($cursor->lte($endDate)) {
            $register->refreshDay(
                user: $user,
                workDate: $cursor->copy()->startOfDay(),
                referenceAt: $at,
            );

            $cursor->addDay();
        }
    }
}
