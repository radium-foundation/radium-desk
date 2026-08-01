<?php

namespace App\Console\Commands;

use App\Enums\AttendanceDayStatus;
use App\Enums\WorkCalendarDayStatus;
use App\Models\User;
use App\Services\Operations\AttendanceRegisterService;
use App\Services\Operations\OperationsRoleService;
use App\Services\Operations\WorkCalendarService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-shot July 2026 go-live reconciliation: pre-go-live working days without
 * sessions were NotStarted → matrix Absent. Convert eligible days to Present.
 * Does not invent WorkSessions. Does not change Leave / WO / Holiday / Extra.
 */
class JulyGoliveAttendanceBackfillCommand extends Command
{
    protected $signature = 'workforce:july-golive-attendance-backfill
                            {--dry-run : Show planned conversions without writing}
                            {--force : Execute without confirmation}';

    protected $description = 'Backfill Jul 1–4 2026 NotStarted working days to Present (HR go-live reconciliation)';

    public function handle(
        OperationsRoleService $roleService,
        WorkCalendarService $workCalendarService,
        AttendanceRegisterService $attendanceRegisterService,
    ): int {
        $config = config('workforce_calendar.july_golive_attendance_backfill', []);
        $from = Carbon::parse((string) ($config['from'] ?? '2026-07-01'))->startOfDay();
        $to = Carbon::parse((string) ($config['to'] ?? '2026-07-04'))->startOfDay();

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if (! $dryRun && ! $force && ! $this->confirm(
            sprintf('Backfill Present for %s → %s on this database?', $from->toDateString(), $to->toDateString()),
            false,
        )) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $users = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $roleService->attendanceTrackedRoleSlugs()))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $roleService->isAttendanceTracked($user));

        $converted = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $cursor = $from->copy();
            while ($cursor->lte($to)) {
                $outcome = $this->backfillDay(
                    user: $user,
                    workDate: $cursor->copy(),
                    workCalendarService: $workCalendarService,
                    attendanceRegisterService: $attendanceRegisterService,
                    dryRun: $dryRun,
                );

                if ($outcome === 'converted') {
                    $converted++;
                    $this->line(sprintf(
                        '[%s] %s %s NotStarted → Present',
                        $dryRun ? 'dry-run' : 'converted',
                        $user->name,
                        $cursor->toDateString(),
                    ));
                } else {
                    $skipped++;
                }

                $cursor->addDay();
            }
        }

        $this->info(sprintf(
            '%s Converted: %d · Skipped: %d',
            $dryRun ? 'DRY RUN —' : 'Done.',
            $converted,
            $skipped,
        ));

        return self::SUCCESS;
    }

    private function backfillDay(
        User $user,
        Carbon $workDate,
        WorkCalendarService $workCalendarService,
        AttendanceRegisterService $attendanceRegisterService,
        bool $dryRun,
    ): string {
        $day = $attendanceRegisterService->findDay($user, $workDate);

        if ($day === null) {
            if ($dryRun) {
                // Preview eligibility without requiring an existing row.
                if ($workCalendarService->approvedLeaveDuration($user, $workDate) !== null) {
                    return 'skipped';
                }

                $schedule = $workCalendarService->scheduleFor($user, $workDate);
                $isHoliday = $workCalendarService->isCompanyHoliday($workDate);
                $isWeeklyOff = $schedule !== null && ! $workCalendarService->isWorkingDay($schedule, $workDate);
                if ($isHoliday || $isWeeklyOff) {
                    return 'skipped';
                }

                return 'converted';
            }

            $day = $attendanceRegisterService->refreshDay(
                user: $user,
                workDate: $workDate,
                referenceAt: $workDate->copy()->endOfDay(),
                allowPreShiftSkip: false,
            );
        }

        if ($day === null) {
            return 'skipped';
        }

        if (in_array($day->status, [
            AttendanceDayStatus::OnLeave,
            AttendanceDayStatus::HalfDay,
            AttendanceDayStatus::ScheduledOff,
            AttendanceDayStatus::Extra,
        ], true)) {
            return 'skipped';
        }

        if ($workCalendarService->approvedLeaveDuration($user, $workDate) !== null) {
            return 'skipped';
        }

        if (! $day->is_working_day || $day->is_company_holiday) {
            return 'skipped';
        }

        if ($day->status !== AttendanceDayStatus::NotStarted) {
            return 'skipped';
        }

        if ((int) $day->session_count > 0) {
            return 'skipped';
        }

        if ($dryRun) {
            return 'converted';
        }

        $day->fill([
            'status' => AttendanceDayStatus::Completed,
            'calendar_status' => WorkCalendarDayStatus::Working,
            'is_working_day' => true,
            'is_company_holiday' => false,
            'is_on_leave' => false,
            'on_time_login' => true,
            'minutes_late' => null,
            'finalized_at' => now(),
            'computed_at' => now(),
        ])->save();

        return 'converted';
    }
}
