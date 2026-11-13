<?php

namespace App\Console\Commands;

use App\Services\DashboardOemHandoff\DashboardOemHandoffSessionPruneService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('dashboard-oem-handoff:prune-sessions
    {--dry-run : Preview deletable sessions without removing rows (default when --execute is omitted)}
    {--execute : Delete eligible session rows}
    {--limit= : Maximum rows to delete in this run}')]
#[Description('Prune expired or completed dashboard OEM handoff sessions past retention grace')]
class PruneDashboardOemHandoffSessionsCommand extends Command
{
    public const SCHEDULE_MUTEX = 'dashboard-oem-handoff:prune-sessions';

    public function __construct(
        private readonly DashboardOemHandoffSessionPruneService $pruneService,
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
        $limit = $this->option('limit');
        $limitInt = is_numeric($limit) ? max(1, (int) $limit) : null;

        $eligible = $this->pruneService->countEligible();
        $this->info(sprintf('Eligible session rows: %d (grace %dh)', $eligible, (int) config('dashboard_oem_handoff.prune_grace_hours_after_expiry', 24)));

        if ($dryRun) {
            $this->info('Dry run — no rows deleted.');

            return self::SUCCESS;
        }

        $deletedIds = $this->pruneService->prune(dryRun: false, limit: $limitInt);
        $deletedCount = count($deletedIds);

        $this->warn(sprintf('Deleted %d dashboard OEM handoff session row(s).', $deletedCount));

        Log::info('dashboard_oem_handoff.prune_sessions.completed', [
            'deleted_count' => $deletedCount,
            'limited' => $limitInt,
        ]);

        return self::SUCCESS;
    }
}
