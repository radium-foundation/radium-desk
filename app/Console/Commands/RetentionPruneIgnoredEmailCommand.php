<?php

namespace App\Console\Commands;

use App\Data\Retention\RetentionIgnoredEmailPruneSummary;
use App\Services\Retention\RetentionIgnoredEmailPruneService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

#[Signature('database:retention-prune-ignored-email
    {--dry-run : Preview eligible ignored email candidates without deleting (default when --execute is omitted)}
    {--execute : Delete eligible ignored email rows in ID-ordered batches (requires separate Owner approval)}
    {--batch= : Rows per delete batch (default from config/retention.php)}
    {--limit= : Maximum rows to delete in this run (execute mode only)}
    {--manifest= : Write all candidate IDs to this path}')]
#[Description('Prune rolling ignored incoming email rows by received_at (dry-run by default; irreversible without DB restore)')]
class RetentionPruneIgnoredEmailCommand extends Command
{
    /** Future scheduler mutex: ->withoutOverlapping() on this name. Not enabled by default. */
    public const SCHEDULE_MUTEX = 'database:retention-prune-ignored-email';

    public function __construct(
        private readonly RetentionIgnoredEmailPruneService $pruneService,
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
            $manifestPath = $this->option('manifest');
            $manifestPath = is_string($manifestPath) && $manifestPath !== '' ? $manifestPath : null;
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('DRY-RUN — NO ROWS DELETED');
        } else {
            $this->warn('EXECUTE mode — eligible ignored email rows will be deleted in batches.');
            $this->warn('Deletion is irreversible unless an independent database backup is restored.');
        }

        try {
            $summary = $this->pruneService->prune(
                dryRun: $dryRun,
                batchSize: $batchSize,
                limit: $limit,
                manifestPath: $manifestPath,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            Log::error('database.retention_prune_ignored_email.failed', [
                'dry_run' => $dryRun,
                'message' => $exception->getMessage(),
            ]);

            return self::FAILURE;
        }

        $this->renderSummary($summary);

        if ($summary->baselineAnomaly) {
            $this->newLine();
            $this->warn(sprintf(
                'Candidate count %s exceeds baseline %s by more than %d%% — review before any execute run.',
                number_format($summary->candidateCount),
                number_format((int) $summary->baselineCandidateCount),
                (int) $summary->baselineVariancePercent,
            ));
        }

        Log::info('database.retention_prune_ignored_email.completed', [
            'dry_run' => $summary->dryRun,
            'ignored_email_days' => $summary->ignoredEmailDays,
            'received_at_cutoff' => $summary->receivedAtCutoff,
            'table_total_count' => $summary->tableTotalCount,
            'predicate_match_count' => $summary->predicateMatchCount,
            'candidate_count' => $summary->candidateCount,
            'estimated_payload_bytes' => $summary->estimatedPayloadBytes,
            'candidates_by_ignore_reason' => $summary->candidatesByIgnoreReason,
            'candidates_by_age_bucket' => $summary->candidatesByAgeBucket,
            'predicate_match_by_ignore_reason' => $summary->predicateMatchByIgnoreReason,
            'oldest_candidate_received_at' => $summary->oldestCandidateReceivedAt,
            'newest_candidate_received_at' => $summary->newestCandidateReceivedAt,
            'sample_candidate_ids' => $summary->sampleCandidateIds,
            'manifest_path' => $summary->manifestPath,
            'manifest_id_count' => $summary->manifestIdCount,
            'excluded_unknown_customer_count' => $summary->excludedUnknownCustomerCount,
            'excluded_unapproved_ignore_reason_count' => $summary->excludedUnapprovedIgnoreReasonCount,
            'baseline_anomaly' => $summary->baselineAnomaly,
            'deleted_count' => $summary->deletedCount,
            'batches_processed' => $summary->batchesProcessed,
            'batch_size' => $summary->batchSize,
        ]);

        return self::SUCCESS;
    }

    private function renderSummary(RetentionIgnoredEmailPruneSummary $summary): void
    {
        $this->newLine();
        $this->info(sprintf('Inspected at %s', $summary->inspectedAt->toIso8601String()));
        $this->line(sprintf(
            'Predicate: status=ignored AND order_id IS NULL AND incident_id IS NULL AND received_at < %s AND processed_at IS NOT NULL AND no pending inbound outbox',
            $summary->receivedAtCutoff,
        ));
        $this->line(sprintf(
            'Retention window: %d days (received_at only — created_at is not used).',
            $summary->ignoredEmailDays,
        ));
        $this->line('Approved ignore_reason allowlist applies on top of the base predicate.');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Table total', number_format($summary->tableTotalCount)],
                ['Predicate matches (pre-allowlist)', number_format($summary->predicateMatchCount)],
                ['Eligible candidates', number_format($summary->candidateCount)],
                ['Estimated payload', $this->formatBytes($summary->estimatedPayloadBytes)],
                ['Oldest candidate received_at', $summary->oldestCandidateReceivedAt ?? '—'],
                ['Newest candidate received_at', $summary->newestCandidateReceivedAt ?? '—'],
                ['Excluded unknown_customer (allowlist)', number_format($summary->excludedUnknownCustomerCount)],
                ['Excluded unapproved ignore_reason', number_format($summary->excludedUnapprovedIgnoreReasonCount)],
                ['Batch size', number_format($summary->batchSize)],
                ['Batches processed', number_format($summary->batchesProcessed)],
                ['Deleted this run', number_format($summary->deletedCount)],
            ],
        );

        if ($summary->candidatesByAgeBucket !== []) {
            $this->newLine();
            $this->info('Candidates by age bucket');

            $rows = [];

            foreach ($summary->candidatesByAgeBucket as $bucket => $count) {
                $rows[] = [$bucket, number_format($count)];
            }

            $this->table(['Age bucket', 'Count'], $rows);
        }

        if ($summary->candidatesByIgnoreReason !== []) {
            $this->newLine();
            $this->info('Eligible candidates by ignore_reason');

            $rows = [];

            foreach ($summary->candidatesByIgnoreReason as $reason => $count) {
                $rows[] = [$reason, number_format($count)];
            }

            $this->table(['ignore_reason', 'Count'], $rows);
        }

        if ($summary->predicateMatchByIgnoreReason !== []
            && $summary->predicateMatchByIgnoreReason !== $summary->candidatesByIgnoreReason) {
            $this->newLine();
            $this->info('Predicate matches by ignore_reason (pre-allowlist)');

            $rows = [];

            foreach ($summary->predicateMatchByIgnoreReason as $reason => $count) {
                $rows[] = [$reason, number_format($count)];
            }

            $this->table(['ignore_reason', 'Count'], $rows);
        }

        if ($summary->sampleCandidateIds !== []) {
            $this->newLine();
            $this->info('Sample candidate IDs');
            $this->line(implode(', ', array_map(
                static fn (int $id): string => (string) $id,
                $summary->sampleCandidateIds,
            )));
        }

        if ($summary->manifestPath !== null) {
            $this->newLine();
            $this->line(sprintf(
                'Candidate manifest: %s (%s IDs)',
                $summary->manifestPath,
                number_format($summary->manifestIdCount),
            ));
        }

        if ($summary->dryRun) {
            $this->newLine();
            $this->line('DRY-RUN — NO ROWS DELETED');
            $this->line('Re-run with --execute only after separate Owner approval.');
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return sprintf('%.2f GB', $bytes / 1024 / 1024 / 1024);
        }

        if ($bytes >= 1024 * 1024) {
            return sprintf('%.2f MB', $bytes / 1024 / 1024);
        }

        if ($bytes >= 1024) {
            return sprintf('%.2f KB', $bytes / 1024);
        }

        return sprintf('%d B', $bytes);
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
