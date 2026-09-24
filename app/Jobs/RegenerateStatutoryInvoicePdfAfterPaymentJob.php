<?php

namespace App\Jobs;

use App\Infrastructure\Queue\QueueRouting;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\StatutoryInvoicePaymentPdfRegenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegenerateStatutoryInvoicePdfAfterPaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $statutoryInvoiceId,
    ) {
        $this->onQueue((string) config(
            'statutory_invoices.payment_pdf_regeneration_queue',
            QueueRouting::default(),
        ));
    }

    public function handle(StatutoryInvoicePaymentPdfRegenerationService $regeneration): void
    {
        $invoice = StatutoryInvoice::query()
            ->with(['items', 'eInvoiceRecord'])
            ->find($this->statutoryInvoiceId);

        if ($invoice === null) {
            return;
        }

        $regeneration->regenerateAfterPayment($invoice);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Statutory invoice PDF regeneration after payment failed.', [
            'statutory_invoice_id' => $this->statutoryInvoiceId,
            'message' => $exception?->getMessage(),
        ]);
    }
}
