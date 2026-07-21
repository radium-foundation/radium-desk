<?php

namespace App\Console\Commands;

use App\Data\Assignment\AssignmentOriginRepairFilters;
use App\Data\Assignment\AssignmentOriginRepairRow;
use App\Data\Assignment\AssignmentOriginRepairSummary;
use App\Services\Assignment\AssignmentOriginRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('assignment:repair-origin
    {--order= : Repair only the incident for this order ID}
    {--service-case= : Repair only the incident matching this service case reference}
    {--incident-id= : Repair only this incident database ID}
    {--dry-run : Preview repairs without writing (default)}
    {--execute : Apply repairs to assignment_origin}')]
#[Description('Repair assignment_origin for historical manual assignments that defaulted to auto')]
class RepairAssignmentOriginCommand extends Command
{
    public function __construct(
        private readonly AssignmentOriginRepairService $repairService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = ! (bool) $this->option('execute');

        $filters = $this->resolveFilters();

        if ($dryRun) {
            $this->info('Dry run — no changes will be written. Pass --execute to apply repairs.');
        } else {
            $this->warn('Execute mode — assignment_origin will be updated.');
        }

        if ($filters->hasFilter()) {
            $this->displayActiveFilters($filters);
        }

        $summary = $this->repairService->repair(dryRun: $dryRun, filters: $filters);

        $this->displayChangedRows($summary);

        $this->newLine();
        $this->info('Summary');
        $this->line('scanned: '.$summary->scanned);
        $this->line('changed: '.$summary->changed);
        $this->line('skipped: '.$summary->skipped);
        $this->line('errors: '.$summary->errors);

        if ($summary->errors > 0) {
            $this->newLine();
            $this->error('Errors');

            foreach ($summary->errorDetails as $error) {
                $this->line(sprintf(
                    '- incident %d: %s',
                    $error['incident_id'],
                    $error['reason'],
                ));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveFilters(): AssignmentOriginRepairFilters
    {
        $incidentId = $this->option('incident-id');

        return new AssignmentOriginRepairFilters(
            orderId: $this->filledOption('order'),
            serviceCase: $this->filledOption('service-case'),
            incidentId: $incidentId !== null && $incidentId !== '' ? (int) $incidentId : null,
        );
    }

    private function filledOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function displayActiveFilters(AssignmentOriginRepairFilters $filters): void
    {
        $active = array_filter([
            $filters->orderId !== null ? 'order='.$filters->orderId : null,
            $filters->serviceCase !== null ? 'service-case='.$filters->serviceCase : null,
            $filters->incidentId !== null ? 'incident-id='.$filters->incidentId : null,
        ]);

        $this->line('Filters: '.implode(', ', $active));
    }

    private function displayChangedRows(AssignmentOriginRepairSummary $summary): void
    {
        if ($summary->changedRows === []) {
            $this->newLine();
            $this->info($summary->dryRun
                ? 'No incidents would be changed.'
                : 'No incidents were changed.');

            return;
        }

        $this->newLine();
        $this->info($summary->dryRun
            ? 'Incidents that would be changed:'
            : 'Incidents changed:');

        $this->table(
            [
                'Incident ID',
                'Service Case',
                'Order ID',
                'Current Assignee',
                'Old Origin',
                'New Origin',
                'Matching Audit Event',
            ],
            array_map(
                fn (AssignmentOriginRepairRow $row): array => [
                    $row->incidentId,
                    $row->serviceCase,
                    $row->orderId ?? '—',
                    $row->assigneeName,
                    $row->oldOrigin,
                    $row->newOrigin,
                    $row->matchingAuditEvent.' (#'.$row->matchingAuditLogId.')',
                ],
                $summary->changedRows,
            ),
        );
    }
}
