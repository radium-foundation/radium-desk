<?php

namespace App\Console\Commands;

use App\Data\Retention\RetentionOutboxPruneSummary;
use App\Services\Retention\RetentionOutboxPruneService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('database:retention-prune-outbox
    {--dry-run : Preview completed outbox candidates without deleting (default when --execute is omitted)}
    {--execute : Delete eligible completed outbox rows in small batches}
    {--batch= : Rows per delete batch (default from config/retention.php)}
    {--limit= : Maximum rows to delete in this run (execute mode only)}')]
#[Description('Prune completed outbox events older than retention policy (dry-run by default)')]
class RetentionPruneOutboxCommand extends Command
{
    /** Future scheduler mutex: ->withoutOverlapping() on this name. */
    public const SCHEDULE_MUTEX = 'database:retention-prune-outbox';

    public function __construct(
        private readonly RetentionOutboxPruneService $pruneService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRunFlag = (bool) $this->option('dry-run');

        if ($execute && $dryRunFlag) {
            $this->error('Pass either --dry-run or --execute, not both.');

            return self::FAILURE;
        }

        $dryRun = ! $execute;

        try {
            $batchSize = $this->positiveIntOption('batch');
            $limit = $this->positiveIntOption('limit');
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run — no outbox rows will be deleted.');
        } else {
            $this->warn('EXECUTE mode — eligible completed outbox rows will be deleted in batches.');
        }

        $summary = $this->pruneService->prune(
            dryRun: $dryRun,
            batchSize: $batchSize,
            limit: $limit,
        );

        $this->renderSummary($summary);

        Log::info('database.retention_prune_outbox.completed', [
            'dry_run' => $summary->dryRun,
            'retention_days' => $summary->retentionDays,
            'cutoff_at' => $summary->cutoffAt,
            'candidate_count' => $summary->candidateCount,
            'excluded_pending' => $summary->excludedPending,
            'excluded_processing' => $summary->excludedProcessing,
            'excluded_failed' => $summary->excludedFailed,
            'excluded_recent_completed' => $summary->excludedRecentCompleted,
            'excluded_null_processed_at' => $summary->excludedNullProcessedAt,
            'deleted_count' => $summary->deletedCount,
            'batches_processed' => $summary->batchesProcessed,
            'batch_size' => $summary->batchSize,
            'candidates_by_event_type' => $summary->candidatesByEventType,
        ]);

        return self::SUCCESS;
    }

    private function renderSummary(RetentionOutboxPruneSummary $summary): void
    {
        $this->newLine();
        $this->info(sprintf('Inspected at %s', $summary->inspectedAt->toIso8601String()));
        $this->line(sprintf(
            'Predicate: status=completed AND processed_at IS NOT NULL AND processed_at < %s (%d-day retention)',
            $summary->cutoffAt,
            $summary->retentionDays,
        ));
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Table total', number_format($summary->tableTotalCount)],
                ['Candidates', number_format($summary->candidateCount)],
                ['Excluded pending', number_format($summary->excludedPending)],
                ['Excluded processing', number_format($summary->excludedProcessing)],
                ['Excluded failed', number_format($summary->excludedFailed)],
                ['Excluded recent completed', number_format($summary->excludedRecentCompleted)],
                ['Excluded completed (NULL processed_at)', number_format($summary->excludedNullProcessedAt)],
                ['Batch size', number_format($summary->batchSize)],
                ['Batches processed', number_format($summary->batchesProcessed)],
                ['Deleted this run', number_format($summary->deletedCount)],
            ],
        );

        if ($summary->candidatesByEventType !== []) {
            $this->newLine();
            $this->info('Candidate breakdown by event_type');

            $rows = [];

            foreach ($summary->candidatesByEventType as $eventType => $count) {
                $rows[] = [$eventType, number_format($count)];
            }

            $this->table(['Event type', 'Candidates'], $rows);
        }

        if ($summary->dryRun) {
            $this->newLine();
            $this->line('No rows were deleted. Re-run with --execute to delete eligible completed outbox rows.');
        }
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new \InvalidArgumentException(sprintf('The --%s option must be a positive integer.', $name));
        }

        return (int) $value;
    }
}
