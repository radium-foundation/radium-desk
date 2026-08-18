<?php

namespace App\Console\Commands;

use App\Services\Retention\RetentionHistoricalGmailNoiseInspectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('database:retention-inspect-historical-gmail-noise {--dry-run : Read-only inspection (always enforced; no writes)}')]
#[Description('Inspect historical Gmail noise email candidates by received_at (read-only; no deletes)')]
class RetentionInspectHistoricalGmailNoiseCommand extends Command
{
    public function __construct(
        private readonly RetentionHistoricalGmailNoiseInspectionService $inspectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->inspectionService->inspect();

        $this->info(sprintf(
            'Historical Gmail noise inspection at %s (read-only; zero database writes).',
            $summary->inspectedAt->toIso8601String(),
        ));
        $this->newLine();
        $this->line(sprintf(
            'Predicate: received_at < %s AND status=ignored AND unlinked AND approved ignore_reason only',
            $summary->receivedAtCutoff,
        ));
        $this->line('Cutoff uses received_at only — created_at is not used.');
        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total candidates', number_format($summary->candidateCount)],
                ['Estimated payload', $this->formatBytes($summary->estimatedPayloadBytes)],
                ['Candidates with incident_id', number_format($summary->candidatesWithIncidentId)],
                ['Candidates with order_id', number_format($summary->candidatesWithOrderId)],
                ['Candidates with link FK', number_format($summary->candidatesWithLinkFk)],
                ['Candidates with outgoing reply FK', number_format($summary->candidatesWithOutgoingReplyFk)],
                ['Excluded unknown_customer (policy)', number_format($summary->excludedUnknownCustomerCount)],
                ['Excluded explicit message IDs', number_format($summary->excludedExplicitMessageIdCount)],
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

        $this->newLine();
        $this->line('No rows were deleted or modified.');

        Log::info('database.retention_inspect_historical_gmail_noise.completed', [
            'inspected_at' => $summary->inspectedAt->toIso8601String(),
            'received_at_cutoff' => $summary->receivedAtCutoff,
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
        ]);

        return self::SUCCESS;
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
