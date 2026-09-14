<?php

namespace App\Console\Commands;

use App\Services\RadiumBox\RadiumBoxPaymentConfirmationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radiumbox:reconcile-handoff
    {--limit= : Maximum orders to recover in this run}
    {--dry-run : Report candidates without calling Box}')]
#[Description('Recover paid radiumbox.com hardware orders missing Desk commerce handoff')]
class ReconcileRadiumBoxHandoffCommand extends Command
{
    public function __construct(
        private readonly RadiumBoxPaymentConfirmationService $confirmation,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('radiumbox.handoff_reconciliation.enabled', true)) {
            $this->warn('RadiumBox handoff reconciliation is disabled.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;
        $dryRun = (bool) $this->option('dry-run');

        $result = $this->confirmation->reconcile($limit, $dryRun);

        $this->info(sprintf(
            'Handoff reconciliation complete: scanned=%d recovered=%d skipped=%d dry_run=%s',
            $result->scanned,
            $result->recovered,
            $result->skipped,
            $dryRun ? 'yes' : 'no',
        ));

        if ($result->recoveredOrderIds !== []) {
            $this->line('Recovered order db ids: '.implode(', ', $result->recoveredOrderIds));
        }

        return self::SUCCESS;
    }
}
