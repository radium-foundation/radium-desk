<?php

namespace App\Console\Commands;

use App\Data\Retention\RetentionHistoricalGmailNoisePruneSummary;
use App\Services\Retention\RetentionHistoricalGmailNoisePruneService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('database:retention-prune-historical-gmail-noise
    {--dry-run : Preview historical Gmail noise candidates without deleting (default when --execute is omitted)}
    {--execute : Delete eligible historical Gmail noise rows in ID-ordered batches}
    {--batch= : Rows per delete batch (default from config/retention.php)}
    {--limit= : Maximum rows to delete in this run (execute mode only)}')]
#[Description('Prune pre-July historical ignored Gmail noise by received_at (dry-run by default)')]
class RetentionPruneHistoricalGmailNoiseCommand extends Command
{
    /** Future scheduler mutex: ->withoutOverlapping() on this name. */
    public const SCHEDULE_MUTEX = 'database:retention-prune-historical-gmail-noise';

    public function __construct(
        private readonly RetentionHistoricalGmailNoisePruneService $pruneService,
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
            $this->info('Dry run — no historical Gmail noise rows will be deleted.');
        } else {
            $this->warn('EXECUTE mode — eligible historical Gmail noise rows will be deleted in batches.');
        }

        $summary = $this->pruneService->prune(
            dryRun: $dryRun,
            batchSize: $batchSize,
            limit: $limit,
        );

        $this->renderSummary($summary);

        Log::info('database.retention_prune_historical_gmail_noise.completed', [
            'dry_run' => $summary->dryRun,
            'received_at_cutoff' => $summary->receivedAtCutoff,
            'table_total_count' => $summary->tableTotalCount,
            'candidate_count' => $summary->candidateCount,
            'estimated_payload_bytes' => $summary->estimatedPayloadBytes,
            'candidates_by_ignore_reason' => $summary->candidatesByIgnoreReason,
            'candidates_by_received_month' => $summary->candidatesByReceivedMonth,
            'sample_candidate_ids' => $summary->sampleCandidateIds,
            'candidates_with_incident_id' => $summary->candidatesWithIncidentId,
            'candidates_with_order_id' => $summary->candidatesWithOrderId,
            'candidates_with_link_fk' => $summary->candidatesWithLinkFk,
            'candidates_with_outgoing_reply_fk' => $summary->candidatesWithOutgoingReplyFk,
            'excluded_unknown_customer_count' => $summary->excludedUnknownCustomerCount,
            'excluded_explicit_message_id_count' => $summary->excludedExplicitMessageIdCount,
            'deleted_count' => $summary->deletedCount,
            'batches_processed' => $summary->batchesProcessed,
            'batch_size' => $summary->batchSize,
        ]);

        return self::SUCCESS;
    }

    private function renderSummary(RetentionHistoricalGmailNoisePruneSummary $summary): void
    {
        $this->newLine();
        $this->info(sprintf('Inspected at %s', $summary->inspectedAt->toIso8601String()));
        $this->line(sprintf(
            'Predicate: received_at < %s AND status=ignored AND unlinked AND approved ignore_reason only',
            $summary->receivedAtCutoff,
        ));
        $this->line('Cutoff uses received_at only — created_at is not used.');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Table total', number_format($summary->tableTotalCount)],
                ['Candidates', number_format($summary->candidateCount)],
                ['Estimated payload', $this->formatBytes($summary->estimatedPayloadBytes)],
                ['Candidates with incident_id', number_format($summary->candidatesWithIncidentId)],
                ['Candidates with order_id', number_format($summary->candidatesWithOrderId)],
                ['Candidates with link FK', number_format($summary->candidatesWithLinkFk)],
                ['Candidates with outgoing reply FK', number_format($summary->candidatesWithOutgoingReplyFk)],
                ['Excluded unknown_customer (policy)', number_format($summary->excludedUnknownCustomerCount)],
                ['Excluded explicit message IDs', number_format($summary->excludedExplicitMessageIdCount)],
                ['Batch size', number_format($summary->batchSize)],
                ['Batches processed', number_format($summary->batchesProcessed)],
                ['Deleted this run', number_format($summary->deletedCount)],
            ],
        );

        if ($summary->candidatesByIgnoreReason !== []) {
            $this->newLine();
            $this->info('Candidates by ignore_reason');

            $rows = [];

            foreach ($summary->candidatesByIgnoreReason as $reason => $count) {
                $rows[] = [$reason, number_format($count)];
            }

            $this->table(['ignore_reason', 'Count'], $rows);
        }

        if ($summary->candidatesByReceivedMonth !== []) {
            $this->newLine();
            $this->info('Candidates by received_at month');

            $rows = [];

            foreach ($summary->candidatesByReceivedMonth as $month => $count) {
                $rows[] = [$month, number_format($count)];
            }

            $this->table(['Month', 'Count'], $rows);
        }

        if ($summary->sampleCandidateIds !== []) {
            $this->newLine();
            $this->info('Sample candidate IDs');
            $this->line(implode(', ', array_map(
                static fn (int $id): string => (string) $id,
                $summary->sampleCandidateIds,
            )));
        }

        if ($summary->dryRun) {
            $this->newLine();
            $this->line('No rows were deleted. Re-run with --execute to delete eligible historical Gmail noise rows.');
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
