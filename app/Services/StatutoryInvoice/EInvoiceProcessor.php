<?php

namespace App\Services\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;

class EInvoiceProcessor
{
    public function __construct(
        private readonly EInvoiceGateway $gateway,
        private readonly EInvoiceEligibility $eligibility,
    ) {}

    public function process(OutboxEvent $event): void
    {
        $invoiceId = (int) ($event->payload['invoice_id'] ?? 0);
        $invoice = StatutoryInvoice::query()->find($invoiceId);
        if ($invoice === null) {
            return;
        }

        $decision = $this->eligibility->evaluate($invoice);
        if (! $decision->eligible) {
            $this->persistSkip($invoice, $decision->reason);

            return;
        }

        $this->persistSkip($invoice, $this->skipReason());
    }

    private function persistSkip(StatutoryInvoice $invoice, string $reason): void
    {
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => $this->gateway->provider(),
                'irn' => null,
                'status' => EInvoiceRecordStatus::Skipped->value,
                'response_payload' => [
                    'skip_reason' => $reason,
                ],
            ],
        );
    }

    private function skipReason(): string
    {
        if (! (bool) config('statutory_invoices.worker_may_mint')) {
            return 'worker_may_mint_off';
        }

        $provider = (string) config('statutory_invoices.einvoice.provider', 'none');
        if ($provider === '' || $provider === 'none' || $this->gateway->provider() === 'none') {
            return 'provider_disabled';
        }

        return 'provider_http_disabled';
    }
}
