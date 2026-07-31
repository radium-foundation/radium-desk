<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Operations\ScheduleBackfillService;
use App\Support\Workforce\AttendanceMatrixCellMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Recalculate schedule-derived session + attendance fields after a schedule correction.
 *
 *   php artisan workforce:schedule-backfill --user=3 --from=2026-07-01 --to=2026-07-31
 *   php artisan workforce:schedule-backfill --all --dry-run
 *   php artisan workforce:schedule-backfill --all --force
 *   php artisan workforce:schedule-backfill --all --changed-since=2026-07-31 --force
 */
class ScheduleBackfillCommand extends Command
{
    protected $signature = 'workforce:schedule-backfill
                            {--user= : Single user id}
                            {--all : Backfill every employee with an active work schedule}
                            {--changed-since= : Only employees whose schedule was created/updated/superseded since this date (Y-m-d)}
                            {--from= : Inclusive start date (Y-m-d); defaults to start of current month for --all}
                            {--to= : Inclusive end date (Y-m-d); defaults to today}
                            {--dry-run : Show changes without writing}
                            {--force : Skip interactive confirmation}';

    protected $description = 'Backfill schedule-derived work session and attendance fields for one user or all scheduled employees';

    public function handle(
        ScheduleBackfillService $backfillService,
        AttendanceMatrixCellMapper $cellMapper,
    ): int {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $all = (bool) $this->option('all');
        $userOption = $this->option('user');
        $changedSinceOption = $this->option('changed-since');

        if (! $all && ($userOption === null || $userOption === '') && ($changedSinceOption === null || $changedSinceOption === '')) {
            $this->error('Provide --user=ID, --all, and/or --changed-since=YYYY-MM-DD.');

            return self::FAILURE;
        }

        if ($all === false && $userOption !== null && $userOption !== '' && ($this->option('from') === null || $this->option('from') === '')) {
            $this->error('--from is required when using --user.');

            return self::FAILURE;
        }

        try {
            $from = $this->option('from') !== null && $this->option('from') !== ''
                ? Carbon::parse((string) $this->option('from'))->startOfDay()
                : now()->copy()->startOfMonth();
            $to = $this->option('to') !== null && $this->option('to') !== ''
                ? Carbon::parse((string) $this->option('to'))->startOfDay()
                : now()->startOfDay();
            $changedSince = $changedSinceOption !== null && $changedSinceOption !== ''
                ? Carbon::parse((string) $changedSinceOption)->startOfDay()
                : null;
        } catch (Throwable $e) {
            $this->error('Invalid date: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($to->lt($from)) {
            $this->error('`--to` must be on or after `--from`.');

            return self::FAILURE;
        }

        $users = $this->resolveUsers($backfillService, $all, $userOption, $changedSince);

        if ($users->isEmpty()) {
            $this->warn('No employees selected.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no database writes.');
        }

        $this->info('Employees Selected: '.$users->count());
        $this->newLine();
        $this->table(
            ['User ID', 'Employee Name', 'Date Range', 'Current Schedule'],
            $users->map(fn (User $user): array => [
                $user->id,
                $user->name,
                $from->toDateString().' → '.$to->toDateString(),
                $backfillService->currentScheduleLabel($user),
            ])->all(),
        );
        $this->newLine();

        if (! $force) {
            if (! $this->confirm('Proceed with backfill?', false)) {
                $this->warn('Aborted.');

                return self::SUCCESS;
            }
            $this->newLine();
        }

        $employeesProcessed = 0;
        $employeesSkipped = 0;
        $sessionsUpdated = 0;
        $attendanceDaysUpdated = 0;
        $otDeltaSeconds = 0;
        $presentToExtra = 0;
        $presentToWeeklyOff = 0;
        $errors = [];
        $total = $users->count();
        $verboseDays = $total === 1 && ! $all;

        foreach ($users->values() as $index => $user) {
            $n = $index + 1;
            $this->line(sprintf('[%d/%d] %s', $n, $total, $user->name));

            try {
                $report = $backfillService->backfill($user, $from, $to, $dryRun);
            } catch (Throwable $e) {
                $errors[] = sprintf('%s (%d): %s', $user->name, $user->id, $e->getMessage());
                $this->error('  ERROR: '.$e->getMessage());
                $this->newLine();

                continue;
            }

            foreach ($report['errors'] as $dayError) {
                $errors[] = sprintf('%s (%d) %s', $user->name, $user->id, $dayError);
            }

            if ($verboseDays) {
                foreach ($report['days'] as $day) {
                    if (isset($day['error'])) {
                        $this->error(sprintf('  %s  ERROR: %s', $day['date'], $day['error']));

                        continue;
                    }

                    $this->line('  '.$day['date']);
                    $this->line(sprintf('   Schedule: %s', $day['schedule']));
                    $this->line(sprintf('   Sessions Updated: %d', $day['sessions_updated']));
                    $this->line(sprintf(
                        '   Attendance: %s -> %s',
                        $day['attendance_before'],
                        $day['attendance_after'],
                    ));
                    $this->line(sprintf(
                        '   OT: %d -> %d',
                        $day['ot_before'],
                        $day['ot_after'],
                    ));
                }
            }

            $this->line(sprintf(' Sessions Updated: %d', $report['sessions_updated']));
            $this->line(sprintf(' Attendance Days Updated: %d', $report['attendance_days_updated']));
            $this->line(sprintf(
                ' OT Changed: %s',
                $this->formatSignedDuration((int) $report['ot_delta_seconds'], $cellMapper),
            ));
            if ($report['present_to_extra'] > 0 || $report['present_to_weekly_off'] > 0) {
                $this->line(sprintf(
                    ' Transitions: Present→Extra=%d Present→Weekly Off=%d',
                    $report['present_to_extra'],
                    $report['present_to_weekly_off'],
                ));
            }
            $this->newLine();

            $employeesProcessed++;
            if (! $report['had_impact'] && $report['errors'] === []) {
                $employeesSkipped++;
            }

            $sessionsUpdated += (int) $report['sessions_updated'];
            $attendanceDaysUpdated += (int) $report['attendance_days_updated'];
            $otDeltaSeconds += (int) $report['ot_delta_seconds'];
            $presentToExtra += (int) $report['present_to_extra'];
            $presentToWeeklyOff += (int) $report['present_to_weekly_off'];
        }

        $this->info('Summary');
        $this->line('Employees Processed: '.$employeesProcessed);
        $this->line('Employees Skipped: '.$employeesSkipped);
        $this->line('Sessions Updated: '.$sessionsUpdated);
        $this->line('Attendance Days Updated: '.$attendanceDaysUpdated);
        $this->line('Total OT Difference: '.$this->formatSignedDuration($otDeltaSeconds, $cellMapper));
        $this->line('Present → Extra: '.$presentToExtra);
        $this->line('Present → Weekly Off: '.$presentToWeeklyOff);
        $this->line('Errors: '.count($errors));

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error(' - '.$error);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveUsers(
        ScheduleBackfillService $backfillService,
        bool $all,
        mixed $userOption,
        ?Carbon $changedSince,
    ): Collection {
        if ($userOption !== null && $userOption !== '') {
            $user = User::query()->with('workSchedule')->find((int) $userOption);

            if ($user === null) {
                $this->error('User not found.');

                return collect();
            }

            if ($changedSince !== null) {
                $eligibleIds = $backfillService->eligibleUsers($changedSince)->pluck('id');
                if (! $eligibleIds->contains($user->id)) {
                    $this->warn(sprintf(
                        'User %d has no schedule create/update/supersede since %s; nothing selected.',
                        $user->id,
                        $changedSince->toDateString(),
                    ));

                    return collect();
                }
            }

            return collect([$user]);
        }

        // --all and/or --changed-since (without --user)
        if ($all || $changedSince !== null) {
            return $backfillService->eligibleUsers($changedSince);
        }

        return collect();
    }

    private function formatSignedDuration(int $seconds, AttendanceMatrixCellMapper $cellMapper): string
    {
        if ($seconds === 0) {
            return '0';
        }

        $sign = $seconds > 0 ? '+' : '-';

        return $sign.$cellMapper->formatDuration(abs($seconds));
    }
}
