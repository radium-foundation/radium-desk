<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Dashboard\TeamActivitySalesLeadBacklogCleanupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('team-activity:cleanup-sales-lead-backlog
    {--user= : Assignee user ID (required)}
    {--dry-run : Preview candidates without closing cases}
    {--execute : Close matched cases (required for writes; omit --dry-run)}')]
#[Description('Archive Shipra Team Activity Sales Lead email backlog (P12-08-011)')]
class TeamActivitySalesLeadBacklogCleanupCommand extends Command
{
    public function __construct(
        private readonly TeamActivitySalesLeadBacklogCleanupService $cleanupService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $userId = $this->option('user');

        if ($userId === null || $userId === '' || ! is_numeric($userId) || (int) $userId <= 0) {
            $this->error('The --user option is required and must be a positive integer.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if ($dryRun && $execute) {
            $this->error('Use either --dry-run or --execute, not both.');

            return self::FAILURE;
        }

        if (! $dryRun && ! $execute) {
            $this->error('Refusing to run without --dry-run (preview) or --execute (write).');

            return self::FAILURE;
        }

        $assignee = User::query()->find((int) $userId);

        if ($assignee === null) {
            $this->error(sprintf('User %d was not found.', (int) $userId));

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run — no changes will be written.');
        } else {
            if (! $this->confirm(sprintf(
                'Close Sales Lead email backlog for %s (user %d)?',
                $assignee->name,
                $assignee->id,
            ), false)) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
        }

        $summary = $this->cleanupService->cleanup($assignee, dryRun: $dryRun);

        $this->info(sprintf('Assignee: %s (id %d)', $assignee->name, $assignee->id));
        $this->info(sprintf('Candidates found: %d', $summary->candidatesFound));
        $this->info(sprintf('Excluded from Team Activity pending scope: %d', $summary->excludedFromTeamActivityPending));

        if ($summary->candidateIds !== []) {
            $this->info(sprintf('First candidate ID: %d', $summary->candidateIds[0]));
            $this->info(sprintf('Last candidate ID: %d', $summary->candidateIds[array_key_last($summary->candidateIds)]));
        }

        $this->newLine();
        $this->info('Candidate breakdown:');

        foreach ($summary->breakdown as $dimension => $counts) {
            $this->line(sprintf('  %s:', $dimension));

            foreach ($counts as $key => $count) {
                $this->line(sprintf('    %s: %d', $key, $count));
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info(sprintf('Would close: %d', $summary->wouldClose));
        } else {
            $this->info(sprintf('Cases closed: %d', $summary->casesClosed));
        }

        $this->info(sprintf('Skipped: %d', $summary->skipped));

        if ($summary->skipReasons !== []) {
            $this->newLine();
            $this->info('Skip reasons:');

            foreach ($summary->skipReasons as $reason => $count) {
                $this->line(sprintf('  %s: %d', $reason, $count));
            }
        }

        $unexpected = $this->unexpectedCandidates($assignee, $summary->candidateIds);

        if ($unexpected !== []) {
            $this->newLine();
            $this->error(sprintf('Unexpected candidates detected: %d', count($unexpected)));

            foreach (array_slice($unexpected, 0, 10) as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_SLASHES));
            }

            return self::FAILURE;
        }

        Log::info('team_activity.sales_lead_backlog_cleanup.command_completed', [
            'dry_run' => $dryRun,
            'execute' => $execute,
            'user_id' => $assignee->id,
            'candidates_found' => $summary->candidatesFound,
            'would_close' => $summary->wouldClose,
            'cases_closed' => $summary->casesClosed,
            'skipped' => $summary->skipped,
            'excluded_from_team_activity_pending' => $summary->excludedFromTeamActivityPending,
            'first_candidate_id' => $summary->candidateIds[0] ?? null,
            'last_candidate_id' => $summary->candidateIds !== []
                ? $summary->candidateIds[array_key_last($summary->candidateIds)]
                : null,
            'breakdown' => $summary->breakdown,
            'skip_reasons' => $summary->skipReasons,
        ]);

        return $summary->skipped > 0 && ! $dryRun ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<int>  $candidateIds
     * @return list<array<string, mixed>>
     */
    private function unexpectedCandidates(User $assignee, array $candidateIds): array
    {
        $unexpected = [];

        foreach ($candidateIds as $incidentId) {
            $incident = \App\Models\Incident::query()->find($incidentId);

            if ($incident === null) {
                $unexpected[] = ['id' => $incidentId, 'reason' => 'missing incident'];

                continue;
            }

            if ($incident->assigned_to_user_id !== $assignee->id) {
                $unexpected[] = [
                    'id' => $incidentId,
                    'reason' => 'assignee mismatch',
                    'assigned_to_user_id' => $incident->assigned_to_user_id,
                ];

                continue;
            }

            if (! $this->cleanupService->matchesSalesLeadEmailBacklog($incident)) {
                $unexpected[] = [
                    'id' => $incidentId,
                    'reason' => 'filter mismatch',
                    'category' => $incident->category,
                    'source' => $incident->source?->value,
                    'assignment_origin' => $incident->assignment_origin?->value,
                ];
            }
        }

        return $unexpected;
    }
}
