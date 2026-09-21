<?php

namespace App\Console\Commands;

use App\Data\Retention\RetentionUnknownCustomerPruneSummary;
use App\Services\Retention\RetentionUnknownCustomerPruneService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

#[Signature('database:retention-prune-unknown-customer
    {--dry-run : Preview eligible unknown_customer ignored email candidates without deleting (default when --execute is omitted)}
    {--execute : Delete eligible unknown_customer ignored email rows in ID-ordered batches (requires separate Owner approval)}
    {--batch= : Rows per delete batch (default from config/retention.php; max 10,000)}
    {--limit= : Maximum rows to delete in this run (execute mode only)}
    {--manifest= : Write all candidate IDs to this path (default: timestamped file under configured manifest directory)}
    {--no-manifest : Skip writing a candidate manifest}')]
#[Description('Prune ignored unknown_customer incoming email rows by received_at (dry-run by default; irreversible without DB restore)')]
class RetentionPruneUnknownCustomerCommand extends Command
{
    /** Future scheduler mutex: ->withoutOverlapping() on this name. Not enabled by default. */
    public const SCHEDULE_MUTEX = 'database:retention-prune-unknown-customer';

    public function __construct(
        private readonly RetentionUnknownCustomerPruneService $pruneService,
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
            $manifestPath = $this->resolveManifestPath();
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('DRY-RUN — NO ROWS DELETED');
        } else {
            $this->warn('EXECUTE mode — eligible unknown_customer ignored email rows will be deleted in batches.');
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

            Log::error('database.retention_prune_unknown_customer.failed', [
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

        Log::info('database.retention_prune_unknown_customer.completed', [
            'dry_run' => $summary->dryRun,
            'unknown_customer_days' => $summary->unknownCustomerDays,
            'received_at_cutoff' => $summary->receivedAtCutoff,
            'table_total_count' => $summary->tableTotalCount,
            'unknown_customer_ignored_total' => $summary->unknownCustomerIgnoredTotal,
            'candidate_count' => $summary->candidateCount,
            'estimated_payload_bytes' => $summary->estimatedPayloadBytes,
            'candidates_by_age_bucket' => $summary->candidatesByAgeBucket,
            'oldest_candidate_received_at' => $summary->oldestCandidateReceivedAt,
            'newest_candidate_received_at' => $summary->newestCandidateReceivedAt,
            'oldest_candidate_id' => $summary->oldestCandidateId,
            'newest_candidate_id' => $summary->newestCandidateId,
            'sample_candidate_ids' => $summary->sampleCandidateIds,
            'manifest_path' => $summary->manifestPath,
            'manifest_id_count' => $summary->manifestIdCount,
            'manifest_sha256' => $summary->manifestSha256,
            'baseline_anomaly' => $summary->baselineAnomaly,
            'deleted_count' => $summary->deletedCount,
            'batches_processed' => $summary->batchesProcessed,
            'batch_size' => $summary->batchSize,
        ]);

        return self::SUCCESS;
    }

    private function renderSummary(RetentionUnknownCustomerPruneSummary $summary): void
    {
        $this->newLine();
        $this->info(sprintf('Inspected at %s', $summary->inspectedAt->toIso8601String()));
        $this->line(sprintf(
            'Predicate: status=ignored AND ignore_reason=unknown_customer AND order_id IS NULL AND incident_id IS NULL AND received_at < %s AND processed_at IS NOT NULL AND no pending inbound outbox AND no reply/incident dependencies',
            $summary->receivedAtCutoff,
        ));
        $this->line(sprintf(
            'Retention window: %d days (received_at only — created_at is not used).',
            $summary->unknownCustomerDays,
        ));
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Table total', number_format($summary->tableTotalCount)],
                ['unknown_customer ignored total', number_format($summary->unknownCustomerIgnoredTotal)],
                ['Eligible candidates', number_format($summary->candidateCount)],
                ['Estimated payload', $this->formatBytes($summary->estimatedPayloadBytes)],
                ['Oldest candidate received_at', $summary->oldestCandidateReceivedAt ?? '—'],
                ['Newest candidate received_at', $summary->newestCandidateReceivedAt ?? '—'],
                ['Candidate ID range', $this->formatIdRange($summary->oldestCandidateId, $summary->newestCandidateId)],
                ['Batch size', number_format($summary->batchSize)],
                ['Batches processed', number_format($summary->batchesProcessed)],
                ['Deleted this run', number_format($summary->deletedCount)],
            ],
        );

        if ($summary->candidatesByAgeBucket !== []) {
            $this->newLine();
            $this->info('Candidates by age bucket');

            $rows = [];

            foreach (['0-30d', '31-90d', '91-180d', '181-365d', '>365d'] as $bucket) {
                $rows[] = [$bucket, number_format($summary->candidatesByAgeBucket[$bucket] ?? 0)];
            }

            $this->table(['Age bucket', 'Count'], $rows);
        }

        $this->newLine();
        $this->info('Safety exclusion counts (candidate integrity — must all be 0)');

        $this->table(
            ['Check', 'Count'],
            [
                ['order_id non-NULL', number_format($summary->candidatesWithOrderId)],
                ['incident_id non-NULL', number_format($summary->candidatesWithIncidentId)],
                ['pending/processing inbound outbox', number_format($summary->candidatesWithPendingOutbox)],
                ['outgoing reply reference', number_format($summary->candidatesWithOutgoingReplyFk)],
                ['incident_incoming_email_links', number_format($summary->candidatesWithLinkFk)],
            ],
        );

        $this->newLine();
        $this->info('Population exclusion counts (informational)');

        $this->table(
            ['Check', 'Count'],
            [
                ['processed_at NULL (ignored unknown_customer > cutoff)', number_format($summary->candidatesWithoutProcessedAt)],
                ['non-ignored status with unknown_customer reason (> cutoff)', number_format($summary->candidatesWithWrongStatus)],
                ['ignored non-unknown_customer reason (> cutoff)', number_format($summary->candidatesWithWrongIgnoreReason)],
            ],
        );

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

            if ($summary->manifestSha256 !== null) {
                $this->line(sprintf('Manifest SHA256: %s', $summary->manifestSha256));
            }
        }

        if ($summary->dryRun) {
            $this->newLine();
            $this->line('DRY-RUN — NO ROWS DELETED');
            $this->line('Re-run with --execute only after separate Owner approval.');
        }
    }

    private function resolveManifestPath(): ?string
    {
        if ((bool) $this->option('no-manifest')) {
            return null;
        }

        $manifest = $this->option('manifest');

        if (is_string($manifest) && $manifest !== '') {
            return $manifest;
        }

        $directory = config('retention.unknown_customer.manifest_directory');

        if (! is_string($directory) || $directory === '') {
            return null;
        }

        return rtrim($directory, '/').'/unknown-customer-candidates-'.now()->utc()->format('Ymd\THis\Z').'.txt';
    }

    private function formatIdRange(?int $oldestId, ?int $newestId): string
    {
        if ($oldestId === null || $newestId === null) {
            return '—';
        }

        return sprintf('%d → %d', $oldestId, $newestId);
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
