<?php

namespace App\Console\Commands;

use App\Services\RadiumBox\RadiumBoxPaymentConfirmationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('radiumbox:reconcile-handoff
    {--limit= : Maximum orders to recover in this run}
    {--order-id= : Recover one business order id such as RBP94}
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

        $orderId = $this->option('order-id');
        $orderId = is_string($orderId) ? trim($orderId) : '';
        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($orderId !== '') {
            if ($dryRun) {
                $this->info("Dry run: would attempt Box recovery for {$orderId}.");

                return self::SUCCESS;
            }

            $result = $this->confirmation->confirmForBusinessOrderId($orderId);
            $this->info(sprintf(
                'Targeted handoff recovery for %s: ok=%s status=%s',
                $orderId,
                $result->ok ? 'yes' : 'no',
                $result->status,
            ));
            if ($result->errorMessage) {
                $this->line($result->errorMessage);
            }

            return $result->ok ? self::SUCCESS : self::FAILURE;
        }

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
