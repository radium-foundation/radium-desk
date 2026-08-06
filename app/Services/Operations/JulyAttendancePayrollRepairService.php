<?php

namespace App\Services\Operations;

use App\Enums\AttendanceDayStatus;
use App\Enums\AttendanceMatrixCellKind;
use App\Enums\LeaveDuration;
use App\Enums\WorkCalendarDayStatus;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One-shot July 2026 payroll attendance normalization.
 *
 * Does not change AttendanceDayCalculator, schedule rows, or August+ behaviour.
 * Writes WorkforceAttendanceDay rows only — never invents WorkSessions.
 */
class JulyAttendancePayrollRepairService
{
    public const WINDOW_FROM = '2026-07-01';

    public const WINDOW_TO = '2026-07-31';

    public function __construct(
        private readonly OperationsRoleService $roleService,
        private readonly WorkCalendarService $workCalendarService,
        private readonly AttendanceMatrixCellMapper $cellMapper,
    ) {}

    /**
     * @return array{
     *     changed: int,
     *     unchanged: int,
     *     users: int,
     *     lines: list<string>,
     *     counts: array{
     *         present: int,
     *         weekly_off: int,
     *         leave: int,
     *         half_day: int,
     *         extra: int,
     *         holiday: int
     *     }
     * }
     */
    public function repair(bool $dryRun = false): array
    {
        $from = Carbon::parse(self::WINDOW_FROM)->startOfDay();
        $to = Carbon::parse(self::WINDOW_TO)->startOfDay();
        $today = now()->startOfDay();

        $users = $this->trackedUsers();
        $changed = 0;
        $unchanged = 0;
        $lines = [];
        $counts = [
            'present' => 0,
            'weekly_off' => 0,
            'leave' => 0,
            'half_day' => 0,
            'extra' => 0,
            'holiday' => 0,
        ];

        foreach ($users as $user) {
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $outcome = $this->repairDay($user, $cursor->copy(), $today, $dryRun);
                $counts[$this->countKeyForKind($outcome['after'])] = ($counts[$this->countKeyForKind($outcome['after'])] ?? 0) + 1;

                if ($outcome['changed']) {
                    $changed++;
                    $lines[] = sprintf(
                        '[%s] %s %s %s → %s',
                        $dryRun ? 'dry-run' : 'repaired',
                        $user->name,
                        $cursor->toDateString(),
                        $outcome['before'],
                        $outcome['after'],
                    );
                } else {
                    $unchanged++;
                }

                $cursor->addDay();
            }
        }

        return [
            'changed' => $changed,
            'unchanged' => $unchanged,
            'users' => $users->count(),
            'lines' => $lines,
            'counts' => $counts,
        ];
    }

    private function countKeyForKind(string $kind): string
    {
        return match ($kind) {
            AttendanceMatrixCellKind::Present->value,
            AttendanceMatrixCellKind::Late->value => 'present',
            AttendanceMatrixCellKind::WeeklyOff->value => 'weekly_off',
            AttendanceMatrixCellKind::Leave->value => 'leave',
            AttendanceMatrixCellKind::HalfDay->value => 'half_day',
            AttendanceMatrixCellKind::Extra->value => 'extra',
            AttendanceMatrixCellKind::Holiday->value => 'holiday',
            default => 'present',
        };
    }

    /**
     * @return Collection<int, User>
     */
    public function trackedUsers(): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $this->roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->roleService->isAttendanceTracked($user))
            ->values();
    }

    /**
     * @return array{changed: bool, before: string, after: string}
     */
    public function repairDay(User $user, Carbon $workDate, ?Carbon $today = null, bool $dryRun = false): array
    {
        $workDate = $workDate->copy()->startOfDay();
        $today ??= now()->startOfDay();

        $existing = WorkforceAttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->first();

        $beforeKind = $this->cellMapper->kindFor($existing, $workDate, $today);
        $target = $this->classify($user, $workDate, $existing);

        $afterKind = $this->kindForTarget($target);
        $alreadyMatches = $existing !== null && $this->dayMatchesTarget($existing, $target);

        if ($alreadyMatches) {
            return [
                'changed' => false,
                'before' => $beforeKind->value,
                'after' => $afterKind->value,
            ];
        }

        if (! $dryRun) {
            $this->persistTarget($user, $workDate, $existing, $target);
        }

        return [
            'changed' => true,
            'before' => $beforeKind->value,
            'after' => $afterKind->value,
        ];
    }

    /**
     * @return array{
     *     status: AttendanceDayStatus,
     *     calendar_status: WorkCalendarDayStatus,
     *     is_working_day: bool,
     *     is_company_holiday: bool,
     *     is_on_leave: bool,
     *     on_time_login: ?bool,
     *     minutes_late: ?int
     * }
     */
    public function classify(User $user, Carbon $workDate, ?WorkforceAttendanceDay $existing = null): array
    {
        $isHoliday = $this->workCalendarService->isCompanyHoliday($workDate);
        $leaveDuration = $this->workCalendarService->approvedLeaveDuration($user, $workDate);
        $isHalfDay = $leaveDuration === LeaveDuration::HalfDay;
        $isFullLeave = $leaveDuration === LeaveDuration::FullDay;
        $isWeeklyOff = $this->isWeeklyOffForJulyRepair($user, $workDate);
        $hasWork = $this->hasWorkEvidence($user, $workDate, $existing);
        $preserveExtra = $existing?->status === AttendanceDayStatus::Extra;

        // 1. Company Holiday (no work) — work on holiday is Extra (priority 4).
        if ($isHoliday && ! $hasWork && ! $preserveExtra) {
            return $this->targetHoliday();
        }

        // 2. Approved Leave
        if ($isFullLeave) {
            return $this->targetLeave();
        }

        // 3. Approved Half Day
        if ($isHalfDay) {
            return $this->targetHalfDay();
        }

        // 4. Extra — worked (or already Extra) on Weekly Off / Holiday; never downgrade Extra → WO.
        if (($isHoliday || $isWeeklyOff) && ($hasWork || $preserveExtra)) {
            return $this->targetExtra($isHoliday);
        }

        // 5. Weekly Off (no work)
        if ($isWeeklyOff) {
            return $this->targetWeeklyOff();
        }

        // 6. Present — July migration working-day default (no fake sessions).
        return $this->targetPresent();
    }

    /**
     * July-repair-only weekly off: schedule when present, else company default Sunday.
     * Does not change WorkCalendarService / AttendanceDayCalculator.
     */
    public function isWeeklyOffForJulyRepair(User $user, Carbon $workDate): bool
    {
        $schedule = $this->workCalendarService->scheduleFor($user, $workDate);

        if ($schedule !== null) {
            return ! $this->workCalendarService->isWorkingDay($schedule, $workDate);
        }

        $defaults = $this->workCalendarService->normalizeWeeklyOffDays(
            config('workforce_calendar.default_weekly_off_days', [Carbon::SUNDAY]),
        );

        return in_array((int) $workDate->dayOfWeek, $defaults, true);
    }

    private function hasWorkEvidence(User $user, Carbon $workDate, ?WorkforceAttendanceDay $existing): bool
    {
        if ($existing !== null && (int) $existing->session_count > 0) {
            return true;
        }

        if ($existing?->status === AttendanceDayStatus::Extra) {
            return true;
        }

        return WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->exists();
    }

    /**
     * @param  array{
     *     status: AttendanceDayStatus,
     *     calendar_status: WorkCalendarDayStatus,
     *     is_working_day: bool,
     *     is_company_holiday: bool,
     *     is_on_leave: bool,
     *     on_time_login: ?bool,
     *     minutes_late: ?int
     * }  $target
     */
    private function dayMatchesTarget(WorkforceAttendanceDay $day, array $target): bool
    {
        return $day->status === $target['status']
            && (bool) $day->is_working_day === $target['is_working_day']
            && (bool) $day->is_company_holiday === $target['is_company_holiday']
            && (bool) $day->is_on_leave === $target['is_on_leave']
            && $day->on_time_login === $target['on_time_login'];
    }

    /**
     * @param  array{
     *     status: AttendanceDayStatus,
     *     calendar_status: WorkCalendarDayStatus,
     *     is_working_day: bool,
     *     is_company_holiday: bool,
     *     is_on_leave: bool,
     *     on_time_login: ?bool,
     *     minutes_late: ?int
     * }  $target
     */
    private function persistTarget(User $user, Carbon $workDate, ?WorkforceAttendanceDay $existing, array $target): void
    {
        $attributes = [
            ...$target,
            'status' => $target['status']->value,
            'calendar_status' => $target['calendar_status']->value,
            'has_schedule' => $this->workCalendarService->scheduleFor($user, $workDate) !== null,
            'finalized_at' => now(),
            'computed_at' => now(),
        ];

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return;
        }

        WorkforceAttendanceDay::query()->create([
            'user_id' => $user->id,
            'work_date' => $workDate->toDateString(),
            'session_count' => 0,
            'session_duration_seconds' => 0,
            'active_duration_seconds' => 0,
            'idle_duration_seconds' => 0,
            'lunch_duration_seconds' => 0,
            'break_duration_seconds' => 0,
            'extra_idle_duration_seconds' => 0,
            'overtime_seconds' => 0,
            'away_timeout_count' => 0,
            'manual_logout_count' => 0,
            'source_version' => (int) config('workforce_calendar.attendance_calculator_version', 1),
            ...$attributes,
        ]);
    }

    /**
     * @param  array{
     *     status: AttendanceDayStatus,
     *     calendar_status: WorkCalendarDayStatus,
     *     is_working_day: bool,
     *     is_company_holiday: bool,
     *     is_on_leave: bool,
     *     on_time_login: ?bool,
     *     minutes_late: ?int
     * }  $target
     */
    private function kindForTarget(array $target): AttendanceMatrixCellKind
    {
        return match ($target['status']) {
            AttendanceDayStatus::OnLeave => AttendanceMatrixCellKind::Leave,
            AttendanceDayStatus::HalfDay => AttendanceMatrixCellKind::HalfDay,
            AttendanceDayStatus::ShortAttendance => AttendanceMatrixCellKind::ShortAttendance,
            AttendanceDayStatus::Extra => AttendanceMatrixCellKind::Extra,
            AttendanceDayStatus::ScheduledOff => $target['is_company_holiday']
                ? AttendanceMatrixCellKind::Holiday
                : AttendanceMatrixCellKind::WeeklyOff,
            default => AttendanceMatrixCellKind::Present,
        };
    }

    /**
     * @return array{
     *     status: AttendanceDayStatus,
     *     calendar_status: WorkCalendarDayStatus,
     *     is_working_day: bool,
     *     is_company_holiday: bool,
     *     is_on_leave: bool,
     *     on_time_login: ?bool,
     *     minutes_late: ?int
     * }
     */
    private function targetHoliday(): array
    {
        return [
            'status' => AttendanceDayStatus::ScheduledOff,
            'calendar_status' => WorkCalendarDayStatus::Holiday,
            'is_working_day' => false,
            'is_company_holiday' => true,
            'is_on_leave' => false,
            'on_time_login' => null,
            'minutes_late' => null,
        ];
    }

    /**
     * @return array{
     *     status: AttendanceDayStatus,
     *     calendar_status: WorkCalendarDayStatus,
     *     is_working_day: bool,
     *     is_company_holiday: bool,
     *     is_on_leave: bool,
     *     on_time_login: ?bool,
     *     minutes_late: ?int
     * }
     */
    private function targetLeave(): array
    {
        return [
            'status' => AttendanceDayStatus::OnLeave,
            'calendar_status' => WorkCalendarDayStatus::LeaveApproved,
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => true,
            'on_time_login' => null,
            'minutes_late' => null,
        ];
    }

    /**
     * @return array{
     *     status: AttendanceDayStatus,
     *     calendar_status: WorkCalendarDayStatus,
     *     is_working_day: bool,
     *     is_company_holiday: bool,
     *     is_on_leave: bool,
     *     on_time_login: ?bool,
     *     minutes_late: ?int
     * }
     */
    private function targetHalfDay(): array
    {
        return [
            'status' => AttendanceDayStatus::HalfDay,
            'calendar_status' => WorkCalendarDayStatus::LeaveApproved,
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => true,
            'on_time_login' => null,
            'minutes_late' => null,
        ];
    }

    /**
     * @return array{
     *     status: AttendanceDayStatus,
     *     calendar_status: WorkCalendarDayStatus,
     *     is_working_day: bool,
     *     is_company_holiday: bool,
     *     is_on_leave: bool,
     *     on_time_login: ?bool,
     *     minutes_late: ?int
     * }
     */
    private function targetExtra(bool $isHoliday): array
    {
        return [
            'status' => AttendanceDayStatus::Extra,
            'calendar_status' => $isHoliday
                ? WorkCalendarDayStatus::Holiday
                : WorkCalendarDayStatus::WeeklyOff,
            'is_working_day' => false,
            'is_company_holiday' => $isHoliday,
            'is_on_leave' => false,
            'on_time_login' => null,
            'minutes_late' => null,
        ];
    }

    /**
     * @return array{
     *     status: AttendanceDayStatus,
     *     calendar_status: WorkCalendarDayStatus,
     *     is_working_day: bool,
     *     is_company_holiday: bool,
     *     is_on_leave: bool,
     *     on_time_login: ?bool,
     *     minutes_late: ?int
     * }
     */
    private function targetWeeklyOff(): array
    {
        return [
            'status' => AttendanceDayStatus::ScheduledOff,
            'calendar_status' => WorkCalendarDayStatus::WeeklyOff,
            'is_working_day' => false,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'on_time_login' => null,
            'minutes_late' => null,
        ];
    }

    /**
     * @return array{
     *     status: AttendanceDayStatus,
     *     calendar_status: WorkCalendarDayStatus,
     *     is_working_day: bool,
     *     is_company_holiday: bool,
     *     is_on_leave: bool,
     *     on_time_login: ?bool,
     *     minutes_late: ?int
     * }
     */
    private function targetPresent(): array
    {
        return [
            'status' => AttendanceDayStatus::Completed,
            'calendar_status' => WorkCalendarDayStatus::Working,
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'on_time_login' => true,
            'minutes_late' => null,
        ];
    }
}
