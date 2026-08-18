<?php

namespace App\Console\Commands;

use App\Data\Retention\RetentionHistoricalUnknownCustomerInspectionSummary;
use App\Services\Retention\RetentionHistoricalUnknownCustomerInspectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('database:retention-inspect-historical-unknown-customer {--dry-run : Read-only inspection (always enforced; no writes)}')]
#[Description('Inspect historical ignored unknown_customer email candidates by fixed received_at cutoff (read-only; no deletes)')]
class RetentionInspectHistoricalUnknownCustomerCommand extends Command
{
    public function __construct(
        private readonly RetentionHistoricalUnknownCustomerInspectionService $inspectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->inspectionService->inspect();

        $this->info(sprintf(
            'Historical unknown_customer inspection at %s (read-only; zero database writes).',
            $summary->inspectedAt->toIso8601String(),
        ));
        $this->newLine();
        $this->line(sprintf(
            'Predicate: status=ignored AND ignore_reason=%s AND received_at < %s',
            config('retention.historical_unknown_customer.ignore_reason', 'unknown_customer'),
            $summary->receivedAtCutoff,
        ));
        $this->line('Fixed historical cutoff — created_at is not used.');
        $this->line('Separate policy from historical Gmail noise — no DELETE, UPDATE, INSERT, or TRUNCATE is performed.');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['incoming_email_messages total', number_format($summary->tableTotalCount)],
                ['Candidate count', number_format($summary->candidateCount)],
                ['Estimated payload', $this->formatBytes($summary->estimatedPayloadBytes)],
                ['Oldest candidate received_at', $summary->oldestCandidateReceivedAt ?? 'n/a'],
                ['Newest candidate received_at', $summary->newestCandidateReceivedAt ?? 'n/a'],
                ['unknown_customer ignored (all ages)', number_format($summary->unknownCustomerIgnoredTotal)],
                ['On/after cutoff unknown_customer ignored', number_format($summary->postCutoffUnknownCustomerIgnoredCount)],
            ],
        );

        $this->newLine();
        $this->info('Safety exclusions (not candidates)');

        $this->table(
            ['Exclusion', 'Rows'],
            [
                ['needs_review + unknown_customer', number_format($summary->excludedNeedsReviewUnknownCustomerCount)],
                ['unknown_customer with incident_id', number_format($summary->excludedUnknownCustomerWithIncidentId)],
                ['unknown_customer with order_id', number_format($summary->excludedUnknownCustomerWithOrderId)],
                ['unknown_customer with link FK', number_format($summary->excludedUnknownCustomerWithLinkFk)],
                ['unknown_customer with outgoing reply FK', number_format($summary->excludedUnknownCustomerWithOutgoingReplyFk)],
            ],
        );

        $this->newLine();
        $this->info('Candidate safety invariants (must be zero)');

        $this->table(
            ['Invariant', 'Count'],
            [
                ['Candidates with incident_id', number_format($summary->candidatesWithIncidentId)],
                ['Candidates with order_id', number_format($summary->candidatesWithOrderId)],
                ['Candidates with link FK', number_format($summary->candidatesWithLinkFk)],
                ['Candidates with outgoing reply FK', number_format($summary->candidatesWithOutgoingReplyFk)],
            ],
        );

        if ($summary->candidatesByYear !== []) {
            $this->newLine();
            $this->info('Candidates by received_at year');

            $rows = [];

            foreach ($summary->candidatesByYear as $year => $count) {
                $rows[] = [(string) $year, number_format($count)];
            }

            $this->table(['Year', 'Count'], $rows);
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

        if ($summary->candidatesByIgnoreReason !== []) {
            $this->newLine();
            $this->info('Candidates by ignore_reason');

            $rows = [];

            foreach ($summary->candidatesByIgnoreReason as $reason => $count) {
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

        if ($summary->sampleCandidateMetadata !== []) {
            $this->newLine();
            $this->info('Sample candidate metadata (no raw_payload/headers loaded)');

            $rows = [];

            foreach ($summary->sampleCandidateMetadata as $sample) {
                $rows[] = [
                    (string) ($sample['id'] ?? ''),
                    (string) ($sample['received_at'] ?? ''),
                    mb_substr((string) ($sample['subject'] ?? ''), 0, 40),
                    (string) ($sample['from_email'] ?? ''),
                    (string) ($sample['status'] ?? ''),
                ];
            }

            $this->table(['ID', 'received_at', 'subject', 'from_email', 'status'], $rows);
        }

        if ($summary->gmailSyncStates !== []) {
            $this->newLine();
            $this->info('Gmail sync cursor status (read-only snapshot)');

            $rows = [];

            foreach ($summary->gmailSyncStates as $state) {
                $rows[] = [
                    (string) ($state['mailbox'] ?? ''),
                    (string) ($state['history_id'] ?? ''),
                    (string) ($state['last_synced_at'] ?? 'n/a'),
                    (string) ($state['oauth_status'] ?? 'n/a'),
                ];
            }

            $this->table(['Mailbox', 'history_id', 'last_synced_at', 'oauth_status'], $rows);
        }

        $this->newLine();
        $this->line('No rows were deleted or modified.');

        Log::info('database.retention_inspect_historical_unknown_customer.completed', $this->logPayload($summary));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function logPayload(RetentionHistoricalUnknownCustomerInspectionSummary $summary): array
    {
        return [
            'inspected_at' => $summary->inspectedAt->toIso8601String(),
            'table_total_count' => $summary->tableTotalCount,
            'received_at_cutoff' => $summary->receivedAtCutoff,
            'candidate_count' => $summary->candidateCount,
            'estimated_payload_bytes' => $summary->estimatedPayloadBytes,
            'oldest_candidate_received_at' => $summary->oldestCandidateReceivedAt,
            'newest_candidate_received_at' => $summary->newestCandidateReceivedAt,
            'candidates_by_ignore_reason' => $summary->candidatesByIgnoreReason,
            'candidates_by_received_month' => $summary->candidatesByReceivedMonth,
            'candidates_by_year' => $summary->candidatesByYear,
            'sample_candidate_ids' => $summary->sampleCandidateIds,
            'sample_candidate_metadata' => $summary->sampleCandidateMetadata,
            'unknown_customer_ignored_total' => $summary->unknownCustomerIgnoredTotal,
            'post_cutoff_unknown_customer_ignored_count' => $summary->postCutoffUnknownCustomerIgnoredCount,
            'excluded_needs_review_unknown_customer_count' => $summary->excludedNeedsReviewUnknownCustomerCount,
            'excluded_unknown_customer_with_incident_id' => $summary->excludedUnknownCustomerWithIncidentId,
            'excluded_unknown_customer_with_order_id' => $summary->excludedUnknownCustomerWithOrderId,
            'excluded_unknown_customer_with_link_fk' => $summary->excludedUnknownCustomerWithLinkFk,
            'excluded_unknown_customer_with_outgoing_reply_fk' => $summary->excludedUnknownCustomerWithOutgoingReplyFk,
            'candidates_with_incident_id' => $summary->candidatesWithIncidentId,
            'candidates_with_order_id' => $summary->candidatesWithOrderId,
            'candidates_with_link_fk' => $summary->candidatesWithLinkFk,
            'candidates_with_outgoing_reply_fk' => $summary->candidatesWithOutgoingReplyFk,
            'gmail_sync_states' => $summary->gmailSyncStates,
        ];
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
}
