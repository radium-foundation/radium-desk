<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Enums\OutboxEventStatus;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;

/**
 * Guards reopening a terminal statutory-irn outbox row for a safe GENERATE retry.
 */
final class EInvoiceOutboxRetryGate
{
    public function __construct(
        private readonly EInvoiceEligibility $eligibility,
        private readonly EInvoiceIrnPayloadMapper $mapper,
    ) {}

    public function mayReopenTerminalOutbox(
        StatutoryInvoice $invoice,
        OutboxEvent $event,
        ?EInvoiceRecord $record,
    ): bool {
        if (! in_array($event->status, [OutboxEventStatus::Completed, OutboxEventStatus::Failed], true)) {
            return false;
        }

        if ($record === null) {
            return false;
        }

        if (EInvoiceIrnGuard::recordHasIssuedIrn($record) || EInvoiceIrnGuard::mustNotResubmit($record)) {
            return false;
        }

        if (EInvoiceIrnGuard::mustRecoverInsteadOfGenerate($record)) {
            return false;
        }

        if ($record->status !== EInvoiceRecordStatus::Queued->value) {
            return false;
        }

        if (EInvoiceIrpSubmissionHold::isHeld((int) $invoice->id)) {
            return false;
        }

        $decision = $this->eligibility->evaluate($invoice);
        if (! $decision->eligible) {
            return false;
        }

        $invoice->loadMissing('items');
        $payload = $this->mapper->map($invoice);

        return $payload->isSubmittable();
    }
}
