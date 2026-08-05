<?php

namespace App\Services\Operations;

use App\Data\Operations\WorkingHoursToday;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use Illuminate\Support\Carbon;

/**
 * Canonical Working Hours Today reader.
 *
 * Single source of truth: workforce_attendance_days.active_duration_seconds
 * (via AttendanceRegisterService). All supervisor/agent hour UIs must use this.
 */
class WorkingHoursTodayService
{
    /** @var array<string, array<int, WorkingHoursToday>> */
    private array $forUsersCache = [];

    public function __construct(
        private readonly AttendanceRegisterService $attendanceRegister,
        private readonly PresenceEngineService $presenceEngine,
        private readonly AttendanceMatrixCellMapper $attendanceMatrixCellMapper,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, WorkingHoursToday>
     */
    public function forUsers(array $userIds, ?Carbon $at = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $at ??= now();
        $workDate = $at->copy()->startOfDay();
        $sortedIds = $userIds;
        sort($sortedIds);
        $cacheKey = $workDate->toDateString().'|'.implode(',', $sortedIds);

        if (isset($this->forUsersCache[$cacheKey])) {
            return $this->forUsersCache[$cacheKey];
        }

        $users = User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        $openUserIds = WorkSession::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('logout_at')
            ->where(function ($query): void {
                $query->where('is_attributable', true)->orWhereNull('is_attributable');
            })
            ->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();
        $openUserIdSet = array_fill_keys($openUserIds, true);

        $existingDays = WorkforceAttendanceDay::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', $workDate->toDateString())
            ->get()
            ->keyBy('user_id');

        $hours = [];

        foreach ($userIds as $userId) {
            $user = $users->get($userId);

            if ($user === null) {
                $hours[$userId] = WorkingHoursToday::empty();

                continue;
            }

            $hours[$userId] = $this->buildForUser(
                user: $user,
                at: $at,
                workDate: $workDate,
                existing: $existingDays->get($userId),
                hasOpenSession: isset($openUserIdSet[$userId]),
            );
        }

        return $this->forUsersCache[$cacheKey] = $hours;
    }

    public function forUser(User $user, ?Carbon $at = null, ?Carbon $workDate = null): WorkingHoursToday
    {
        $at ??= now();
        $workDate ??= $at->copy()->startOfDay();

        $existing = $this->attendanceRegister->findDay($user, $workDate);
        $hasOpenSession = $this->attendanceRegister->hasOpenAttributableWorkSession($user, $workDate);

        return $this->buildForUser(
            user: $user,
            at: $at,
            workDate: $workDate,
            existing: $existing,
            hasOpenSession: $hasOpenSession,
        );
    }

    private function buildForUser(
        User $user,
        Carbon $at,
        Carbon $workDate,
        ?WorkforceAttendanceDay $existing,
        bool $hasOpenSession,
    ): WorkingHoursToday {
        $day = $this->resolveAttendanceDay(
            user: $user,
            at: $at,
            workDate: $workDate,
            existing: $existing,
            hasOpenSession: $hasOpenSession,
        );

        if ($day === null) {
            return WorkingHoursToday::empty();
        }

        $seconds = (int) $day->active_duration_seconds;

        return new WorkingHoursToday(
            activeDurationSeconds: $seconds,
            label: $this->presenceEngine->formatDuration($seconds),
            sessionCount: (int) $day->session_count,
            onTimeLogin: $day->on_time_login,
            minutesLate: $this->attendanceMatrixCellMapper->lateMinutesForDisplay($day, $workDate),
        );
    }

    /**
     * Prefer the attendance register row. Refresh when missing, or when today has an
     * open WorkSession that still needs live ticks reflected in the rollup.
     * Avoid rewriting finalized or already-computed closed-day rows without open sessions.
     */
    private function resolveAttendanceDay(
        User $user,
        Carbon $at,
        Carbon $workDate,
        ?WorkforceAttendanceDay $existing,
        bool $hasOpenSession,
    ): ?WorkforceAttendanceDay {
        if ($existing !== null && $existing->finalized_at !== null && ! $hasOpenSession) {
            return $existing;
        }

        if ($existing !== null && ! $hasOpenSession) {
            return $existing;
        }

        return $this->attendanceRegister->resolveDay(
            user: $user,
            workDate: $workDate,
            referenceAt: $at,
        );
    }
}
