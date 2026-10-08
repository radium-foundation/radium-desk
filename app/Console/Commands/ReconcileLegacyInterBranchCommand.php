<?php

namespace App\Console\Commands;

use App\Enums\LegacyInterBranchCandidateStatus;
use App\Models\User;
use App\Services\Inventory\LegacyInterBranchReconciliationService;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(
    name: 'inventory:reconcile-legacy-inter-branch',
    description: 'Reconcile historical POS-based Delhi→Mumbai movements into inter-branch transfers',
)]
class ReconcileLegacyInterBranchCommand extends Command
{
    protected $signature = 'inventory:reconcile-legacy-inter-branch
                            {--sale= : Inventory sale ID}
                            {--invoice= : Statutory invoice ID}
                            {--dry-run : Preview reconciliation without mutating data}
                            {--discover : List historical candidates and classification}
                            {--force : Execute without interactive confirmation}';

    public function handle(LegacyInterBranchReconciliationService $reconciliation): int
    {
        if ((bool) $this->option('discover')) {
            return $this->renderDiscovery($reconciliation);
        }

        $saleId = $this->option('sale') !== null ? (int) $this->option('sale') : null;
        $invoiceId = $this->option('invoice') !== null ? (int) $this->option('invoice') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($saleId === null && $invoiceId === null) {
            $this->error('Provide --sale or --invoice, or use --discover.');

            return self::FAILURE;
        }

        if (! $dryRun && ! (bool) $this->option('force')) {
            $this->warn('Legacy inter-branch reconciliation mutates inventory and creates audit records.');
            if (! $this->confirm('Proceed with reconciliation?', false)) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $actor = User::query()->where('is_active', true)->orderBy('id')->first();
        if ($actor === null) {
            $this->error('No active user found to attribute reconciliation.');

            return self::FAILURE;
        }

        try {
            $result = $reconciliation->reconcile(
                actor: $actor,
                saleId: $saleId,
                invoiceId: $invoiceId,
                dryRun: $dryRun,
                reason: 'inventory:reconcile-legacy-inter-branch',
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $assessment = $result->assessment;
        $this->line('Status: '.$assessment->status->label());
        $this->line('Sale: '.$assessment->sale->sale_no.' (#'.$assessment->sale->id.')');
        $this->line('Invoice: '.($assessment->invoice?->invoice_number ?? 'n/a').' (#'.$assessment->invoice?->id.')');
        $this->line('Source: '.($assessment->fromBranch?->code ?? 'n/a'));
        $this->line('Destination: '.($assessment->toBranch?->code ?? 'n/a'));
        $this->line('Serial count: '.$assessment->serialCount);
        $this->line('Finance treatment: '.($assessment->financeTreatment ?? 'n/a'));
        $this->line('Existing IRN: '.($assessment->existingIrn ?? 'none'));
        $this->line('E-way (Desk): '.($assessment->ewayStatus ?? 'not_applicable'));
        $this->line('PO/GR path: superseded — not invoked by reconciliation');

        if ($assessment->blockers !== []) {
            $this->warn('Blockers:');
            foreach ($assessment->blockers as $blocker) {
                $this->line(' - '.$blocker);
            }
        }

        if ($dryRun) {
            $this->info('Dry-run complete. No data was modified.');

            return self::SUCCESS;
        }

        if ($result->transaction !== null) {
            $this->info(sprintf(
                'Inter-branch transaction %s (#%d)%s.',
                $result->transaction->transaction_no,
                $result->transaction->id,
                $result->idempotentReplay ? ' (idempotent replay)' : '',
            ));
        }

        return self::SUCCESS;
    }

    private function renderDiscovery(LegacyInterBranchReconciliationService $reconciliation): int
    {
        $candidates = $reconciliation->discoverCandidates();

        if ($candidates === []) {
            $this->info('No legacy inter-branch candidates found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($candidates as $candidate) {
            $rows[] = [
                $candidate->saleId,
                $candidate->saleNo ?? '',
                $candidate->invoiceId ?? '',
                $candidate->invoiceNumber ?? '',
                $candidate->status->value,
                $candidate->serialCount,
                $candidate->blockers === [] ? '' : implode('; ', $candidate->blockers),
            ];
        }

        $this->table(
            ['Sale ID', 'Sale No', 'Invoice ID', 'Invoice No', 'Status', 'Serials', 'Blockers'],
            $rows,
        );

        $counts = array_count_values(array_map(
            fn ($candidate) => $candidate->status->value,
            $candidates,
        ));
        $this->info(sprintf(
            'Summary: %d candidate(s); reconcilable=%d; blocked=%d; already_reconciled=%d; requires_manual_review=%d',
            count($candidates),
            $counts[LegacyInterBranchCandidateStatus::Reconcilable->value] ?? 0,
            $counts[LegacyInterBranchCandidateStatus::Blocked->value] ?? 0,
            $counts[LegacyInterBranchCandidateStatus::AlreadyReconciled->value] ?? 0,
            $counts[LegacyInterBranchCandidateStatus::RequiresManualReview->value] ?? 0,
        ));

        return self::SUCCESS;
    }
}
