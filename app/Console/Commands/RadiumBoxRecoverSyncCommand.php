<?php

namespace App\Console\Commands;

use App\Services\RadiumBox\RadiumBoxSyncRecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('radiumbox:recover-sync
    {--limit= : Maximum number of orders to recover}
    {--dry-run : Show eligible orders without dispatching recovery jobs}')]
#[Description('Recover failed or stale pending RadiumBox synchronizations')]
class RadiumBoxRecoverSyncCommand extends Command
{
    public function __construct(
        private readonly RadiumBoxSyncRecoveryService $recoveryService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('radiumbox.recovery.enabled')) {
            $this->warn('RadiumBox recovery is disabled.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->option('limit');
        $limit = is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;

        $result = $this->recoveryService->recover($limit, $dryRun);

        $this->info(sprintf(
            '%s — scanned: %d, recovered: %d, skipped: %d',
            $dryRun ? 'Dry run complete' : 'Recovery complete',
            $result->scanned,
            $result->recovered,
            $result->skipped,
        ));

        Log::info('RadiumBox scheduler recovery run completed.', [
            'dry_run' => $dryRun,
            'scanned' => $result->scanned,
            'recovered' => $result->recovered,
            'skipped' => $result->skipped,
            'recovered_order_ids' => $result->recoveredOrderIds,
        ]);

        return self::SUCCESS;
    }
}
