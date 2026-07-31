<?php

namespace App\Services\Operations;

use App\Enums\PresenceActivityType;
use App\Enums\PresenceStatus;
use App\Enums\TeamAvailabilityChangeSource;
use App\Enums\WorkSessionEndReason;
use App\Enums\WorkSessionOrigin;
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

    public function startSession(
        User $user,
        ?Carbon $at = null,
        WorkSessionOrigin $origin = WorkSessionOrigin::Login,
    ): ?WorkSession {
        if (! $this->tracksPresence($user)) {
            return null;
        }

        $at ??= now();

        $openSession = $this->openSessionFor($user);

        if ($openSession !== null) {
            return $openSession;
        }

        $schedule = $this->workCalendarService->scheduleFor($user, $at);
        $breakAllowanceSeconds = $this->breakAllowanceSeconds($schedule);

        $session = WorkSession::query()->create([
            'user_id' => $user->id,
            'work_date' => $at->toDateString(),
            'login_at' => $at,
            'last_activity_at' => $at,
            'last_tick_at' => $at,
            'origin' => $origin,
            'is_attributable' => $origin->isAttributableByDefault(),
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

    /**
     * Record presence-related activity against a work session.
     *
     * Session creation is gated by $createIfMissing:
     * - true  → authenticated browser paths (login already uses startSession;
     *           heartbeat / middleware pass true)
     * - false → business / assignment / automation / queue / webhook callers
     *           may attach productivity to an existing open session only
     *
     * When $createIfMissing is false, presence is not extended
     * (last_activity_at is left unchanged).
     */
    public function recordActivity(
        User $user,
        PresenceActivityType $type = PresenceActivityType::System,
        ?Carbon $at = null,
        bool $createIfMissing = false,
    ): ?WorkSession {
        if (! $this->tracksPresence($user)) {
            return null;
        }

        $at ??= now();
        $extendsPresence = $createIfMissing;
        $session = $this->openSessionFor($user);

        if ($session === null) {
            if (! $createIfMissing) {
                return null;
            }

            $session = $this->startSession($user, $at, WorkSessionOrigin::Browser);
        }

        if ($session === null) {
            return null;
        }

        if (! $at->isSameDay($session->work_date)) {
            $this->closeSession(
                $user,
                WorkSessionEndReason::SessionReplaced,
                $session->work_date->copy()->endOfDay(),
            );

            if (! $createIfMissing) {
                return null;
            }

            $session = $this->startSession($user, $at, WorkSessionOrigin::Browser);

            if ($session === null) {
                return null;
            }
        }

        $this->tickSession($session, $at, hasActivity: $extendsPresence);
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
        $cutoff = $at->copy()->subMinutes($this->awayTimeoutMinutes());

        WorkSession::query()
            ->with('user')
            ->whereNull('logout_at')
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_activity_at', '<=', $cutoff)
                    ->orWhere(function ($orphaned) use ($cutoff): void {
                        // Corrupted rows with null last_activity_at must not stick open forever.
                        $orphaned->whereNull('last_activity_at')
                            ->where('login_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->each(function (WorkSession $session) use ($at, &$processed): void {
                // Close the specific stale row. Do not route through openSessionFor()/
                // presenceStatus($user): duplicate open sessions can make the latest row
                // look active while an older stale row keeps health Critical forever.
                $this->forceCloseTimedOutSession($session, $at);
                $processed++;
            });

        return $processed;
    }

    /**
     * Close one open work session for away-timeout cleanup.
     *
     * Unlike forceLogoutUser(), this always finalizes the given session id. Auth
     * invalidation and availability sync run only when the user has no open session left.
     */
    public function forceCloseTimedOutSession(WorkSession $session, ?Carbon $at = null): void
    {
        if ($session->logout_at !== null) {
            return;
        }

        $at ??= now();
        $session->loadMissing('user');
        $user = $session->user;

        $this->tickSession($session, $at, hasActivity: false);
        $this->finalizeSession($session, $at, WorkSessionEndReason::AwayTimeout);

        if ($user === null) {
            return;
        }

        if ($this->openSessionFor($user) !== null) {
            $this->refreshAttendanceRegister($user, $at, $session);

            return;
        }

        $this->availabilityService->syncFromSessionEnd(
            $user,
            TeamAvailabilityChangeSource::Timeout,
        );
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $this->refreshAttendanceRegister($user, $at, $session);
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
            if ($at->lt($session->last_tick_at)) {
                // A prior tick advanced past a real reference time (e.g. premature endOfDay).
                // Rewind the tick cursor and clamp active time so later real ticks can accumulate.
                if ($hasActivity) {
                    $session->last_activity_at = $at;
                }

                $session->last_tick_at = $at;
                $this->enforceActiveDurationInvariant($session, $at);
                $session->save();

                return;
            }

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

        $this->enforceActiveDurationInvariant($session, $at);
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
        $schedule = $this->workCalendarService->scheduleFor($user, $cursor);

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
        $schedule = $this->workCalendarService->scheduleFor($user, $at);

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
        $this->enforceActiveDurationInvariant($session, $at);
        $session->overtime_seconds = $this->calculateOvertimeSeconds($user, $session, $at);
        $session->save();
    }

    /**
     * Active desk time can never exceed the wall-clock window through logout (or $through).
     * Guards against inflated last_tick_at from premature endOfDay ticks.
     */
    private function enforceActiveDurationInvariant(WorkSession $session, ?Carbon $through = null): void
    {
        if ($session->login_at === null) {
            return;
        }

        $end = $session->logout_at ?? $through ?? $session->last_tick_at;

        if ($end === null) {
            return;
        }

        $wallClockSeconds = max(0, (int) $session->login_at->diffInSeconds($end));

        $session->active_duration_seconds = min(
            max(0, (int) $session->active_duration_seconds),
            $wallClockSeconds,
        );
    }

    /**
     * Recalculate overtime for a closed session using current OT rules.
     * Used by historical repair (attendance:repair-corrupted-sessions).
     *
     * OT = wall-clock seconds of this session that fall after expected shift end,
     * capped at the login work_date end-of-day. Does not count idle gaps before
     * login, and cannot exceed the session's own post-shift wall duration.
     */
    public function recalculateOvertimeSeconds(WorkSession $session): int
    {
        if ($session->login_at === null || $session->logout_at === null || $session->work_date === null) {
            return 0;
        }

        $session->loadMissing('user');

        return $this->calculateOvertimeSeconds($session->user, $session, $session->logout_at);
    }

    /**
     * Schedule-derived session fields for a point-in-time schedule version.
     * Does not mutate the session. Open sessions skip overtime (needs logout).
     *
     * @return array{
     *     overtime_seconds: int|null,
     *     expected_working_minutes: int|null,
     *     on_time_login: bool|null,
     *     break_allowance_seconds: int
     * }
     */
    public function scheduleDerivedAttributesFor(
        WorkSession $session,
        ?TeamMemberWorkSchedule $schedule,
    ): array {
        $session->loadMissing('user');
        $user = $session->user;

        $overtimeSeconds = null;
        if ($session->logout_at !== null && $session->login_at !== null && $user !== null) {
            $overtimeSeconds = $this->calculateOvertimeSeconds($user, $session, $session->logout_at);
        }

        $expectedWorkingMinutes = $schedule !== null
            ? $this->workCalendarService->expectedWorkingMinutes($schedule)
            : null;

        $onTimeLogin = null;
        if ($schedule !== null && $user !== null && $session->login_at !== null) {
            $onTimeLogin = ! $this->workCalendarService->isLateLogin($user, $session->login_at);
        }

        return [
            'overtime_seconds' => $overtimeSeconds,
            'expected_working_minutes' => $expectedWorkingMinutes,
            'on_time_login' => $onTimeLogin,
            'break_allowance_seconds' => $this->breakAllowanceSeconds($schedule),
        ];
    }

    private function calculateOvertimeSeconds(?User $user, WorkSession $session, Carbon $logoutAt): int
    {
        if ($user === null || $session->login_at === null || $session->work_date === null) {
            return 0;
        }

        $workDate = $session->work_date->copy()->startOfDay();
        $schedule = $this->workCalendarService->scheduleFor($user, $workDate);

        if ($schedule === null) {
            return 0;
        }

        // Anchor on shift start for this work_date so overnight schedules
        // (10:00→00:00, 22:00→06:00) resolve the end of THIS day's shift —
        // not yesterday's post-midnight window that startOfDay can fall into.
        $shiftStart = $this->workCalendarService->expectedWorkStartAt($schedule, $workDate);
        $expectedEnd = $this->workCalendarService->expectedWorkEndAt($schedule, $shiftStart);
        $effectiveLogout = $logoutAt->gt($workDate->copy()->endOfDay())
            ? $workDate->copy()->endOfDay()
            : $logoutAt;

        // Only the portion of THIS session after shift end counts as OT.
        // Using expectedEnd→logout (ignoring login) double-counted gaps across
        // multiple after-hours sessions and, with a wrong overnight end, produced
        // ~24h OT on short away_timeout sessions.
        $overtimeStart = $session->login_at->greaterThan($expectedEnd)
            ? $session->login_at->copy()
            : $expectedEnd->copy();

        if ($effectiveLogout->lte($overtimeStart)) {
            return 0;
        }

        return max(0, (int) $overtimeStart->diffInSeconds($effectiveLogout));
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

        return $session?->last_activity_at
            ?? $session?->login_at
            ?? $user->last_active_at;
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
