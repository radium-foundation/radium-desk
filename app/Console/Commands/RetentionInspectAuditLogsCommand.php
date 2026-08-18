<?php

namespace App\Console\Commands;

use App\Data\Retention\RetentionAuditLogInspectionSummary;
use App\Services\Retention\RetentionAuditLogInspectionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('database:retention-inspect-audit-logs {--dry-run : Read-only inspection (always enforced; no writes)}')]
#[Description('Inspect audit_logs retention candidates and safety categories (read-only; no deletes)')]
class RetentionInspectAuditLogsCommand extends Command
{
    public function __construct(
        private readonly RetentionAuditLogInspectionService $inspectionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $summary = $this->inspectionService->inspect();

        $this->info(sprintf(
            'Audit log retention inspection at %s (read-only; zero database writes).',
            $summary->inspectedAt->toIso8601String(),
        ));
        $this->newLine();

        $this->line(sprintf(
            'Business audit retention window: %d days (config retention.business_audit_days).',
            (int) config('retention.business_audit_days', 365),
        ));
        $this->line('Inspection only — no DELETE, UPDATE, INSERT, or TRUNCATE is performed.');
        $this->newLine();

        $this->info('Table statistics');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total rows', number_format($summary->tableTotalRows)],
                ['Data size', $this->formatBytes($summary->tableDataBytes)],
                ['Index size', $this->formatBytes($summary->tableIndexBytes)],
                ['Total size', $this->formatBytes($summary->tableTotalBytes)],
                ['Min created_at', $summary->minCreatedAt ?? 'n/a'],
                ['Max created_at', $summary->maxCreatedAt ?? 'n/a'],
                ['Estimated payload (JSON/text fields)', $this->formatBytes($summary->estimatedPayloadBytes)],
            ],
        );

        if ($summary->rowsOlderThanDays !== []) {
            $this->newLine();
            $this->info('Age cohorts (created_at)');

            $rows = [];

            foreach ($summary->rowsOlderThanDays as $label => $count) {
                $days = config('retention.audit_logs.age_cohort_days.'.$label);
                $rows[] = [
                    $label,
                    is_numeric($days) ? (string) $days : 'n/a',
                    number_format($count),
                ];
            }

            $this->table(['Cohort', 'Days', 'Rows'], $rows);
        }

        if ($summary->countByCategory !== []) {
            $this->newLine();
            $this->info('Event categories');

            $rows = [];

            foreach ($summary->countByCategory as $key => $count) {
                $label = config('retention.audit_logs.categories.'.$key.'.label', $key);
                $rows[] = [(string) $label, number_format($count)];
            }

            $this->table(['Category', 'Rows'], $rows);
        }

        if ($summary->topEventsByVolume !== []) {
            $this->newLine();
            $this->info('Top events by volume');

            $rows = [];

            foreach ($summary->topEventsByVolume as $entry) {
                $rows[] = [$entry['event'], number_format($entry['count'])];
            }

            $this->table(['Event', 'Rows'], $rows);
        }

        if ($summary->mustKeepFamilyCounts !== []) {
            $this->newLine();
            $this->info('MUST KEEP families (safety analysis)');

            $rows = [];

            foreach ($summary->mustKeepFamilyCounts as $key => $count) {
                $label = config('retention.audit_logs.must_keep_families.'.$key.'.label', $key);
                $rows[] = [(string) $label, number_format($count)];
            }

            $rows[] = ['Distinct rows matching any MUST KEEP family', number_format($summary->mustKeepFamilyRowTotal)];

            $this->table(['Family', 'Rows'], $rows);
        }

        if ($summary->candidateCohorts !== []) {
            $this->newLine();
            $this->info('Candidate cohorts (inspection only — not deleted)');

            $rows = [];

            foreach ($summary->candidateCohorts as $key => $cohort) {
                $rows[] = [
                    $cohort['label'],
                    number_format($cohort['count']),
                    (string) $cohort['older_than_days'],
                    $this->formatBytes($cohort['estimated_payload_bytes']),
                    number_format($cohort['overlapping_must_keep_count']),
                ];
            }

            $this->table(
                ['Cohort', 'Rows', 'Older than (days)', 'Payload est.', 'MUST KEEP overlap'],
                $rows,
            );
        }

        if ($summary->logicalSafety !== []) {
            $this->newLine();
            $this->info('Logical safety (codebase readers)');

            $rows = [];

            foreach ($summary->logicalSafety as $entry) {
                $rows[] = [
                    $entry['label'],
                    $entry['classification'],
                    implode(', ', $entry['readers']),
                    $entry['notes'],
                ];
            }

            $this->table(['Family', 'Classification', 'Readers', 'Notes'], $rows);
        }

        $this->renderTruncationIssue($summary);

        $this->newLine();
        $this->line('No rows were deleted or modified.');

        Log::info('database.retention_inspect_audit_logs.completed', [
            'inspected_at' => $summary->inspectedAt->toIso8601String(),
            'table_total_rows' => $summary->tableTotalRows,
            'table_data_bytes' => $summary->tableDataBytes,
            'table_index_bytes' => $summary->tableIndexBytes,
            'table_total_bytes' => $summary->tableTotalBytes,
            'min_created_at' => $summary->minCreatedAt,
            'max_created_at' => $summary->maxCreatedAt,
            'rows_older_than_days' => $summary->rowsOlderThanDays,
            'estimated_payload_bytes' => $summary->estimatedPayloadBytes,
            'count_by_category' => $summary->countByCategory,
            'top_events_by_volume' => $summary->topEventsByVolume,
            'must_keep_family_counts' => $summary->mustKeepFamilyCounts,
            'must_keep_family_row_total' => $summary->mustKeepFamilyRowTotal,
            'candidate_cohorts' => $summary->candidateCohorts,
            'logical_safety' => $summary->logicalSafety,
            'truncation_issue' => $summary->truncationIssue,
        ]);

        return self::SUCCESS;
    }

    private function renderTruncationIssue(RetentionAuditLogInspectionSummary $summary): void
    {
        if ($summary->truncationIssue === []) {
            return;
        }

        $this->newLine();
        $this->info('Event column truncation issue (P18-08-011)');

        $this->table(
            ['Field', 'Value'],
            [
                ['Status', (string) ($summary->truncationIssue['status'] ?? 'unknown')],
                ['Resolved in', (string) ($summary->truncationIssue['resolved_in_version'] ?? 'n/a')],
                ['Old event (52 chars)', (string) ($summary->truncationIssue['old_event'] ?? 'n/a')],
                ['New event', (string) ($summary->truncationIssue['new_event'] ?? 'n/a')],
                ['Column limit', (string) ($summary->truncationIssue['column_limit'] ?? 'n/a')],
                ['Errors before fix', number_format((int) ($summary->truncationIssue['observed_error_count_before_fix'] ?? 0))],
                ['Notes', (string) ($summary->truncationIssue['notes'] ?? '')],
            ],
        );
    }

    private function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'n/a (driver does not expose table sizes)';
        }

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
