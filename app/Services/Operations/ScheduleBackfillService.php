<?php

namespace App\Services\Operations;

use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use App\Services\Workforce\Extra\ExtraQualificationEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Targeted historical backfill after a work-schedule correction.
 *
 * Per work date: resolve scheduleFor(user, date) → rewrite schedule-derived
 * session fields → rebuild attendance day via AttendanceRegisterService.
 *
 * Persistence SoT ends at work_sessions + workforce_attendance_days.
 * MonthlyAttendanceMatrix / Employee360 are read models over those day rows
 * (no monthly aggregate table exists).
 */
class ScheduleBackfillService
{
    public function __construct(
        private readonly WorkCalendarService $workCalendarService,
        private readonly PresenceEngineService $presenceEngine,
        private readonly AttendanceRegisterService $attendanceRegister,
        private readonly AttendanceDayCalculator $attendanceDayCalculator,
        private readonly AttendanceMatrixCellMapper $cellMapper,
        private readonly ExtraQualificationEngine $extraQualificationEngine,
    ) {}

    /**
     * @return array{
     *     user: User,
     *     dry_run: bool,
     *     days: list<array<string, mixed>>,
     *     days_processed: int,
     *     sessions_updated: int,
     *     attendance_days_updated: int,
     *     monthly_summaries_refreshed: int,
     *     monthly_aggregate_table: string|null,
     *     errors: list<string>
     * }
     */
    public function backfill(
        User $user,
        Carbon $from,
        Carbon $to,
        bool $dryRun = false,
    ): array {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        if ($to->lt($from)) {
            throw new \InvalidArgumentException('`--to` must be on or after `--from`.');
        }

        $days = [];
        $errors = [];
        $sessionsUpdated = 0;
        $attendanceDaysUpdated = 0;

        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $dateString = $cursor->toDateString();

            try {
                $dayReport = $this->processDay($user, $cursor->copy(), $dryRun);
                $days[] = $dayReport;
                $sessionsUpdated += (int) $dayReport['sessions_updated'];
                if ($dayReport['attendance_changed']) {
                    $attendanceDaysUpdated++;
                }
            } catch (Throwable $e) {
                $errors[] = "{$dateString}: {$e->getMessage()}";
                $days[] = [
                    'date' => $dateString,
                    'error' => $e->getMessage(),
                ];
            }

            $cursor->addDay();
        }

        return [
            'user' => $user,
            'dry_run' => $dryRun,
            'days' => $days,
            'days_processed' => count(array_filter(
                $days,
                fn (array $day): bool => ! isset($day['error']),
            )),
            'sessions_updated' => $sessionsUpdated,
            'attendance_days_updated' => $attendanceDaysUpdated,
            // No workforce_attendance_monthly_summaries (or equivalent) table exists.
            // Matrix + Member360 sum overtime/status from workforce_attendance_days on read.
            'monthly_summaries_refreshed' => 0,
            'monthly_aggregate_table' => null,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function processDay(User $user, Carbon $workDate, bool $dryRun): array
    {
        $dateString = $workDate->toDateString();
        $today = now()->startOfDay();
        $schedule = $this->workCalendarService->scheduleFor($user, $workDate);

        $existingDay = $this->attendanceRegister->findDay($user, $workDate);
        $beforeKind = $this->cellMapper->kindFor($existingDay, $workDate, $today);
        $beforeOt = (int) ($existingDay?->overtime_seconds ?? 0);

        $sessions = WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $dateString)
            ->orderBy('login_at')
            ->orderBy('id')
            ->get();

        $sessionChanges = [];
        $sessionsUpdated = 0;

        $apply = function () use (
            $user,
            $workDate,
            $schedule,
            $sessions,
            &$sessionChanges,
            &$sessionsUpdated,
            $dryRun,
            $existingDay,
            $beforeKind,
            $beforeOt,
            $today,
            $dateString,
        ): array {
            foreach ($sessions as $session) {
                $derived = $this->presenceEngine->scheduleDerivedAttributesFor($session, $schedule);
                $updates = $this->diffSessionDerivedFields($session, $derived);

                if ($updates === []) {
                    continue;
                }

                $sessionChanges[] = [
                    'id' => $session->id,
                    'before' => $this->snapshotSessionDerived($session),
                    'after' => array_merge($this->snapshotSessionDerived($session), $updates),
                    'updates' => $updates,
                ];
                $sessionsUpdated++;

                if (! $dryRun) {
                    WorkSession::query()->whereKey($session->id)->update($updates);
                    $session->fill($updates);
                }
            }

            $referenceAt = $workDate->isSameDay(now())
                ? now()->min($workDate->copy()->endOfDay())
                : $workDate->copy()->endOfDay();

            if ($dryRun) {
                $result = $this->attendanceDayCalculator->compute(
                    user: $user,
                    workDate: $workDate,
                    referenceAt: $referenceAt,
                    allowPreShiftSkip: false,
                );

                // Status/kind come from calendar + sessions; OT must use would-be
                // session values because compute() re-reads persisted overtime.
                $afterOt = $this->projectedOvertimeSeconds($sessions, $sessionChanges);
                $previewDay = $this->previewAttendanceDay($existingDay, $result);
                if ($previewDay !== null) {
                    $previewDay->overtime_seconds = $afterOt;
                }
                $afterKind = $this->cellMapper->kindFor($previewDay, $workDate, $today);
                $attendanceChanged = $result !== null && (
                    ($existingDay?->status?->value ?? null) !== ($result->status->value ?? null)
                    || (bool) ($existingDay?->is_working_day) !== $result->isWorkingDay
                    || $beforeOt !== $afterOt
                    || $beforeKind !== $afterKind
                );

                return [
                    'after_kind' => $afterKind,
                    'after_ot' => $afterOt,
                    'attendance_changed' => $attendanceChanged,
                    'after_status' => $result?->status?->value,
                ];
            }

            $refreshed = $this->attendanceRegister->refreshDay(
                user: $user,
                workDate: $workDate,
                referenceAt: $referenceAt,
                allowPreShiftSkip: false,
            );

            // Contribution / EX are evaluate-on-read; invoke so EX events fire when enabled.
            $this->extraQualificationEngine->evaluate($user, $workDate);

            $afterKind = $this->cellMapper->kindFor($refreshed, $workDate, $today);
            $afterOt = (int) ($refreshed?->overtime_seconds ?? 0);
            $attendanceChanged = $refreshed !== null && (
                ($existingDay?->status?->value ?? null) !== ($refreshed->status?->value ?? null)
                || (bool) ($existingDay?->is_working_day) !== (bool) $refreshed->is_working_day
                || $beforeOt !== $afterOt
                || $beforeKind !== $afterKind
                || $sessionsUpdated > 0
            );

            return [
                'after_kind' => $afterKind,
                'after_ot' => $afterOt,
                'attendance_changed' => $attendanceChanged,
                'after_status' => $refreshed?->status?->value,
            ];
        };

        $outcome = $dryRun
            ? $apply()
            : DB::transaction(function () use ($apply): array {
                return $apply();
            });

        return [
            'date' => $dateString,
            'schedule' => $this->formatSchedule($schedule),
            'schedule_id' => $schedule?->id,
            'sessions_updated' => $sessionsUpdated,
            'session_changes' => $sessionChanges,
            'attendance_before' => $beforeKind->shortLabel(),
            'attendance_after' => $outcome['after_kind']->shortLabel(),
            'attendance_before_kind' => $beforeKind->value,
            'attendance_after_kind' => $outcome['after_kind']->value,
            'attendance_before_status' => $existingDay?->status?->value,
            'attendance_after_status' => $outcome['after_status'],
            'ot_before' => $beforeOt,
            'ot_after' => $outcome['after_ot'],
            'attendance_changed' => $outcome['attendance_changed'],
        ];
    }

    /**
     * @param  array{
     *     overtime_seconds: int|null,
     *     expected_working_minutes: int|null,
     *     on_time_login: bool|null,
     *     break_allowance_seconds: int
     * }  $derived
     * @return array<string, mixed>
     */
    private function diffSessionDerivedFields(WorkSession $session, array $derived): array
    {
        $updates = [];

        if ($derived['overtime_seconds'] !== null
            && (int) $session->overtime_seconds !== (int) $derived['overtime_seconds']) {
            $updates['overtime_seconds'] = (int) $derived['overtime_seconds'];
        }

        if ($derived['expected_working_minutes'] !== null
            && (int) $session->expected_working_minutes !== (int) $derived['expected_working_minutes']) {
            $updates['expected_working_minutes'] = (int) $derived['expected_working_minutes'];
        }

        if ($derived['on_time_login'] !== null
            && $session->on_time_login !== $derived['on_time_login']) {
            $updates['on_time_login'] = $derived['on_time_login'];
        }

        if ((int) $session->break_allowance_seconds !== (int) $derived['break_allowance_seconds']) {
            $updates['break_allowance_seconds'] = (int) $derived['break_allowance_seconds'];
        }

        return $updates;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotSessionDerived(WorkSession $session): array
    {
        return [
            'overtime_seconds' => (int) $session->overtime_seconds,
            'expected_working_minutes' => $session->expected_working_minutes,
            'on_time_login' => $session->on_time_login,
            'break_allowance_seconds' => (int) $session->break_allowance_seconds,
        ];
    }

    private function previewAttendanceDay(
        ?WorkforceAttendanceDay $existing,
        mixed $result,
    ): ?WorkforceAttendanceDay {
        if ($result === null) {
            return $existing;
        }

        $preview = $existing?->replicate() ?? new WorkforceAttendanceDay;
        $preview->forceFill($result->persistenceAttributes());

        return $preview;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, WorkSession>  $sessions
     * @param  list<array{id: int, updates: array<string, mixed>}>  $sessionChanges
     */
    private function projectedOvertimeSeconds($sessions, array $sessionChanges): int
    {
        $overrides = [];
        foreach ($sessionChanges as $change) {
            if (array_key_exists('overtime_seconds', $change['updates'])) {
                $overrides[(int) $change['id']] = (int) $change['updates']['overtime_seconds'];
            }
        }

        return (int) $sessions
            ->filter(fn (WorkSession $session): bool => $session->is_attributable !== false)
            ->sum(function (WorkSession $session) use ($overrides): int {
                return $overrides[(int) $session->id] ?? (int) $session->overtime_seconds;
            });
    }

    private function formatSchedule(?TeamMemberWorkSchedule $schedule): string
    {
        if ($schedule === null) {
            return 'none';
        }

        return $this->clock($schedule->work_start_time).'-'.$this->clock($schedule->work_end_time);
    }

    private function clock(mixed $time): string
    {
        $value = (string) $time;

        return strlen($value) >= 5 ? substr($value, 0, 5) : $value;
    }
}
