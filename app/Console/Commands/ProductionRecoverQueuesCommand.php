<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('production:recover-queues
    {--dry-run : Preview recovery steps without mutating}
    {--limit=100 : Maximum items per recovery/backfill step}
    {--chunk=50 : Chunk size passed to backfill commands}
    {--clear-schedule-locks : Clear Laravel schedule mutexes (releases stuck withoutOverlapping locks)}
    {--drain-queue : Drain the database queue with queue:work --stop-when-empty}
    {--max-time=55 : Seconds per queue:work pass}
    {--drain-passes=20 : Maximum queue drain loops}
    {--skip-radiumbox-backfill : Skip radiumbox:backfill-sync}
    {--skip-readyqueue-backfill : Skip readyqueue:backfill}
    {--skip-automation-pending : Skip service-cases:process-automation-pending}
    {--skip-repairs : Skip serial-waiting + automation repair}')]
#[Description('Production recovery: unlock scheduler, drain queue, catch up RadiumBox sync and Ready Queue (idempotent)')]
class ProductionRecoverQueuesCommand extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $chunk = max(1, (int) $this->option('chunk'));

        $this->info($dryRun
            ? 'Production queue recovery (dry run).'
            : 'Production queue recovery starting.');

        if ((bool) $this->option('clear-schedule-locks')) {
            $this->stepClearScheduleLocks($dryRun);
        } else {
            $this->warn('Skipping schedule lock clear (pass --clear-schedule-locks to release stuck mutexes).');
        }

        if ((bool) $this->option('drain-queue')) {
            $this->stepDrainQueue($dryRun);
        } else {
            $this->warn('Skipping queue drain (pass --drain-queue to process pending jobs).');
            $this->reportQueueDepth();
        }

        if (! (bool) $this->option('skip-radiumbox-backfill')) {
            $this->stepBackfillRadiumBoxSync($dryRun, $limit, $chunk);
        }

        if ((bool) $this->option('drain-queue') && ! $dryRun) {
            $this->newLine();
            $this->info('Queue drain after RadiumBox sync dispatch...');
            $this->stepDrainQueue(dryRun: false, passes: 5);
        }

        if (! (bool) $this->option('skip-automation-pending')) {
            $this->stepProcessAutomationPending($dryRun);
        }

        if (! (bool) $this->option('skip-readyqueue-backfill')) {
            $this->stepBackfillReadyQueue($dryRun, $limit, $chunk);
        }

        if (! (bool) $this->option('skip-repairs')) {
            $this->stepRepairs($dryRun);
        }

        if ((bool) $this->option('drain-queue') && ! $dryRun) {
            $this->newLine();
            $this->info('Final queue drain pass after Ready Queue backfill...');
            $this->stepDrainQueue(dryRun: false, passes: 5);
        }

        $this->newLine();
        $this->reportQueueDepth();
        $this->info('Production queue recovery finished.');

        Log::info('production:recover-queues completed.', [
            'dry_run' => $dryRun,
            'limit' => $limit,
            'chunk' => $chunk,
            'clear_schedule_locks' => (bool) $this->option('clear-schedule-locks'),
            'drain_queue' => (bool) $this->option('drain-queue'),
            'skip_radiumbox_backfill' => (bool) $this->option('skip-radiumbox-backfill'),
            'skip_readyqueue_backfill' => (bool) $this->option('skip-readyqueue-backfill'),
            'skip_automation_pending' => (bool) $this->option('skip-automation-pending'),
            'skip_repairs' => (bool) $this->option('skip-repairs'),
        ]);

        return self::SUCCESS;
    }

    private function stepClearScheduleLocks(bool $dryRun): void
    {
        $this->newLine();
        $this->info('1) Clear schedule mutexes');

        if ($dryRun) {
            $this->line('Would run: php artisan schedule:clear-cache');

            return;
        }

        $exit = $this->call('schedule:clear-cache');
        $this->line($exit === self::SUCCESS
            ? 'Schedule mutex cache cleared.'
            : 'schedule:clear-cache returned a non-zero exit code.');
    }

    private function stepDrainQueue(bool $dryRun, ?int $passes = null): void
    {
        $this->newLine();
        $this->info('Drain database queue');

        $maxTime = max(5, (int) $this->option('max-time'));
        $passes ??= max(1, (int) $this->option('drain-passes'));

        if ($dryRun) {
            $this->line(sprintf(
                'Would run up to %d×: php artisan queue:work --stop-when-empty --max-time=%d',
                $passes,
                $maxTime,
            ));
            $this->reportQueueDepth();

            return;
        }

        for ($pass = 1; $pass <= $passes; $pass++) {
            $pendingBefore = $this->pendingJobsCount();

            if ($pendingBefore === 0) {
                $this->line('Queue empty — stop draining.');

                break;
            }

            $this->line(sprintf(
                'Drain pass %d/%d — pending jobs: %d',
                $pass,
                $passes,
                $pendingBefore,
            ));

            $this->call('queue:work', [
                '--stop-when-empty' => true,
                '--max-time' => $maxTime,
            ]);

            $pendingAfter = $this->pendingJobsCount();

            if ($pendingAfter === 0 || $pendingAfter >= $pendingBefore) {
                if ($pendingAfter >= $pendingBefore && $pendingAfter > 0) {
                    $this->warn('Queue depth did not decrease; stopping drain loop to avoid a tight spin.');
                }

                break;
            }
        }
    }

    private function stepBackfillRadiumBoxSync(bool $dryRun, int $limit, int $chunk): void
    {
        $this->newLine();
        $this->info('Backfill RadiumBox sync');

        $params = [
            '--limit' => $limit,
            '--chunk' => $chunk,
        ];

        if ($dryRun) {
            $params['--dry-run'] = true;
        }

        $this->call('radiumbox:backfill-sync', $params);
    }

    private function stepProcessAutomationPending(bool $dryRun): void
    {
        $this->newLine();
        $this->info('Process expired automation-pending grace periods');

        if ($dryRun) {
            $this->line('Would run: php artisan service-cases:process-automation-pending');

            return;
        }

        $this->call('service-cases:process-automation-pending');
    }

    private function stepBackfillReadyQueue(bool $dryRun, int $limit, int $chunk): void
    {
        $this->newLine();
        $this->info('Backfill Ready Queue');

        $params = [
            '--limit' => $limit,
            '--chunk' => $chunk,
        ];

        if ($dryRun) {
            $params['--dry-run'] = true;
        }

        $this->call('radiumbox:backfill-ready-queue', $params);
    }

    private function stepRepairs(bool $dryRun): void
    {
        $this->newLine();
        $this->info('Idempotent repairs');

        $params = $dryRun ? ['--dry-run' => true] : [];

        $this->call('incidents:repair-serial-waiting', $params);
        $this->call('automation:repair', $params);
    }

    private function reportQueueDepth(): void
    {
        $pending = $this->pendingJobsCount();
        $failed = $this->failedJobsCount();

        $this->line(sprintf('Queue depth — jobs: %d, failed_jobs: %d', $pending, $failed));
    }

    private function pendingJobsCount(): int
    {
        try {
            return (int) DB::table('jobs')->count();
        } catch (Throwable) {
            return -1;
        }
    }

    private function failedJobsCount(): int
    {
        try {
            return (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            return -1;
        }
    }
}
