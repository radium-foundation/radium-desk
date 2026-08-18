<?php

namespace App\Console\Commands;

use App\Data\Retention\RetentionCachePruneSummary;
use App\Services\Retention\RetentionCachePruneService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('database:retention-prune-cache
    {--dry-run : Preview expired cache candidates without deleting (default when --execute is omitted)}
    {--execute : Delete expired cache rows in small batches}
    {--batch= : Rows per delete batch (default from config/retention.php)}
    {--limit= : Maximum rows to delete in this run (execute mode only)}')]
#[Description('Prune expired Laravel database cache rows (dry-run by default)')]
class RetentionPruneCacheCommand extends Command
{
    /** Future scheduler mutex: ->withoutOverlapping() on this name. */
    public const SCHEDULE_MUTEX = 'database:retention-prune-cache';

    public function __construct(
        private readonly RetentionCachePruneService $pruneService,
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
            $this->info('Dry run — no cache rows will be deleted.');
        } else {
            $this->warn('EXECUTE mode — expired cache rows will be deleted in batches.');
        }

        $summary = $this->pruneService->prune(
            dryRun: $dryRun,
            batchSize: $batchSize,
            limit: $limit,
        );

        $this->renderSummary($summary);

        Log::info('database.retention_prune_cache.completed', [
            'dry_run' => $summary->dryRun,
            'candidate_count' => $summary->candidateCount,
            'active_count' => $summary->activeCount,
            'estimated_candidate_payload_bytes' => $summary->estimatedCandidatePayloadBytes,
            'deleted_count' => $summary->deletedCount,
            'batches_processed' => $summary->batchesProcessed,
            'batch_size' => $summary->batchSize,
        ]);

        return self::SUCCESS;
    }

    private function renderSummary(RetentionCachePruneSummary $summary): void
    {
        $this->newLine();
        $this->info(sprintf('Inspected at %s', $summary->inspectedAt->toIso8601String()));
        $this->line('Predicate: expiration < UNIX_TIMESTAMP(NOW())');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Table total', number_format($summary->tableTotalCount)],
                ['Expired candidates', number_format($summary->candidateCount)],
                ['Active (excluded)', number_format($summary->activeCount)],
                ['Estimated candidate payload', $this->formatBytes($summary->estimatedCandidatePayloadBytes)],
                ['Batch size', number_format($summary->batchSize)],
                ['Batches processed', number_format($summary->batchesProcessed)],
                ['Deleted this run', number_format($summary->deletedCount)],
            ],
        );

        if ($summary->dryRun) {
            $this->newLine();
            $this->line('No rows were deleted. Re-run with --execute to delete expired cache rows.');
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return number_format($bytes / 1024 / 1024, 2).' MB';
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
