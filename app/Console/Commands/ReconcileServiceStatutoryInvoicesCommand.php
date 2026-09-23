<?php

namespace App\Console\Commands;

use App\Services\StatutoryInvoice\ServiceStatutoryInvoiceReconciliationService;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(name: 'desk:reconcile-service-statutory-invoices', description: 'Reconcile workflow-completed service commerce orders missing statutory invoices')]
class ReconcileServiceStatutoryInvoicesCommand extends Command
{
    protected $signature = 'desk:reconcile-service-statutory-invoices
                            {--limit= : Maximum commerce orders to scan this run}
                            {--dry-run : Report candidates without attempting mint}';

    public function handle(ServiceStatutoryInvoiceReconciliationService $reconciliation): int
    {
        if (! (bool) config('service_statutory_invoice.reconciliation.enabled', true)) {
            $this->warn('Service statutory invoice reconciliation is disabled.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $dryRun = (bool) $this->option('dry-run');

        $result = $reconciliation->reconcile($limit, $dryRun);

        $this->info(sprintf(
            'Scanned %d commerce order(s); attempted %d; skipped %d; already invoiced %d; ineligible %d; no workflow %d%s.',
            $result->scanned,
            $result->attempted,
            $result->skipped,
            $result->alreadyInvoiced,
            $result->ineligible,
            $result->noWorkflow,
            $dryRun ? ' (dry-run)' : '',
        ));

        return self::SUCCESS;
    }
}
