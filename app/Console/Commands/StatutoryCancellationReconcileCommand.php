<?php

namespace App\Console\Commands;

use App\Enums\StatutoryInvoiceCancellationOperationStatus;
use App\Models\StatutoryInvoiceCancellation;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use Illuminate\Console\Command;

final class StatutoryCancellationReconcileCommand extends Command
{
    protected $signature = 'desk:statutory-cancellation-reconcile {--invoice= : Statutory invoice id}';

    protected $description = 'Resume in-flight statutory invoice cancellation operations after provider reconciliation.';

    public function handle(StatutoryInvoiceCancellationOrchestrator $orchestrator): int
    {
        $invoiceId = $this->option('invoice');

        $query = StatutoryInvoiceCancellation::query()
            ->whereIn('status', [
                StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending,
                StatutoryInvoiceCancellationOperationStatus::IrnConfirmed,
                StatutoryInvoiceCancellationOperationStatus::LocalPending,
                StatutoryInvoiceCancellationOperationStatus::FailedRetryable,
            ]);

        if ($invoiceId !== null && $invoiceId !== '') {
            $query->where('statutory_invoice_id', (int) $invoiceId);
        }

        $operations = $query->orderBy('id')->get();
        if ($operations->isEmpty()) {
            $this->info('No in-flight cancellation operations found.');

            return self::SUCCESS;
        }

        foreach ($operations as $operation) {
            try {
                $orchestrator->reconcileInFlightCancellation($operation);
                $this->info("Resumed cancellation operation {$operation->id} for invoice {$operation->statutory_invoice_id}.");
            } catch (\Throwable $exception) {
                $this->error("Operation {$operation->id} failed: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
