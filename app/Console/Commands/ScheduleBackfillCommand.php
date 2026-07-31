<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Operations\ScheduleBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Recalculate schedule-derived session + attendance fields for one user/date range
 * after a work-schedule correction (effective-dated or current).
 *
 *   php artisan workforce:schedule-backfill --user=3 --from=2026-07-01 --to=2026-07-31
 *   php artisan workforce:schedule-backfill --user=7 --from=2026-07-26 --dry-run
 */
class ScheduleBackfillCommand extends Command
{
    protected $signature = 'workforce:schedule-backfill
                            {--user= : Required user id}
                            {--from= : Required inclusive start date (Y-m-d)}
                            {--to= : Inclusive end date (Y-m-d); defaults to today}
                            {--dry-run : Show changes without writing}';

    protected $description = 'Backfill schedule-derived work session and attendance fields for one user over a date range';

    public function handle(ScheduleBackfillService $backfillService): int
    {
        $userId = $this->option('user');
        $fromOption = $this->option('from');

        if ($userId === null || $userId === '' || $fromOption === null || $fromOption === '') {
            $this->error('Both --user and --from are required.');

            return self::FAILURE;
        }

        $user = User::query()->find((int) $userId);

        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        try {
            $from = Carbon::parse((string) $fromOption)->startOfDay();
            $to = $this->option('to') !== null && $this->option('to') !== ''
                ? Carbon::parse((string) $this->option('to'))->startOfDay()
                : now()->startOfDay();
        } catch (\Throwable $e) {
            $this->error('Invalid date: '.$e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no database writes.');
        }

        $this->info(sprintf('Processing User: %s (%d)', $user->name, $user->id));
        $this->newLine();

        try {
            $report = $backfillService->backfill($user, $from, $to, $dryRun);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($report['days'] as $day) {
            if (isset($day['error'])) {
                $this->error(sprintf('%s  ERROR: %s', $day['date'], $day['error']));
                $this->newLine();

                continue;
            }

            $this->line($day['date']);
            $this->line(sprintf(' Schedule: %s', $day['schedule']));
            $this->line(sprintf(' Sessions Updated: %d', $day['sessions_updated']));
            $this->line(sprintf(
                ' Attendance: %s -> %s',
                $day['attendance_before'],
                $day['attendance_after'],
            ));
            $this->line(sprintf(
                ' OT: %d -> %d',
                $day['ot_before'],
                $day['ot_after'],
            ));
            $this->newLine();
        }

        $this->info('Summary');
        $this->line('Days Processed: '.$report['days_processed']);
        $this->line('Sessions Updated: '.$report['sessions_updated']);
        $this->line('Attendance Days Updated: '.$report['attendance_days_updated']);
        $this->line(
            'Monthly Summary Refreshed: 0 (no monthly aggregate table; matrix/360 read workforce_attendance_days)',
        );
        $this->line('Errors: '.count($report['errors']));

        if ($report['errors'] !== []) {
            foreach ($report['errors'] as $error) {
                $this->error(' - '.$error);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
