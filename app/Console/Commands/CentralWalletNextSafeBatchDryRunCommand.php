<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\NextSafeBatchDryRunService;
use Illuminate\Console\Command;

class CentralWalletNextSafeBatchDryRunCommand extends Command
{
    protected $signature = 'central-wallet:next-safe-batch-dry-run
                            {--manifest= : Optional manifest path}';

    protected $description = 'Dry-run next-safe batch migration readiness (P-30-10-24)';

    public function handle(NextSafeBatchDryRunService $dryRunService): int
    {
        $report = $dryRunService->run($this->option('manifest'));

        $this->info('Next-safe batch dry-run');
        $this->line('Batch: '.($report['batch_id'] ?? ''));
        $this->line('Manifest: '.($report['manifest_count'] ?? 0).' / ₹'.($report['manifest_amount'] ?? '0.00'));
        $this->line('Journal: '.($report['journal_count'] ?? 0).' / ₹'.($report['journal_amount'] ?? '0.00'));
        $this->line('Batch ready: '.(($report['batch_ready'] ?? false) ? 'true' : 'false'));
        $this->line('Batch empty: '.(($report['batch_empty'] ?? false) ? 'true' : 'false'));

        if (! empty($report['blockers'])) {
            $this->line('Blockers: '.implode(', ', $report['blockers']));
        }

        return self::SUCCESS;
    }
}
