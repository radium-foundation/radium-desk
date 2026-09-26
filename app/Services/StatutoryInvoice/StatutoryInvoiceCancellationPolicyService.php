<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\IrnCancellationDecision;
use App\Enums\StatutoryCancellationWorkflow;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\StatutoryCancellationPolicyResult;
use Illuminate\Support\Carbon;

final class StatutoryInvoiceCancellationPolicyService
{
    public function __construct(
        private readonly StatutoryInvoiceCancellationEligibility $eligibility,
    ) {}

    public function evaluate(StatutoryInvoice $invoice): StatutoryCancellationPolicyResult
    {
        $invoice->loadMissing('eInvoiceRecord');

        $isB2b = $this->isB2b($invoice);
        $hasSubmittedIrn = $this->hasSubmittedIrn($invoice);
        $irnAgeHours = $hasSubmittedIrn ? $this->irnAgeHours($invoice) : null;
        $windowHours = $this->cancellationWindowHours();

        if (! $isB2b) {
            return new StatutoryCancellationPolicyResult(
                workflow: StatutoryCancellationWorkflow::B2cCancelInvoice,
                irnDecision: IrnCancellationDecision::NotRequired,
                isB2b: false,
                hasSubmittedIrn: $hasSubmittedIrn,
                irnAgeHours: $irnAgeHours,
                summary: 'B2C statutory invoice: local cancellation without IRN workflow.',
            );
        }

        if (! $hasSubmittedIrn) {
            return new StatutoryCancellationPolicyResult(
                workflow: StatutoryCancellationWorkflow::B2bNoIrnCancelInvoice,
                irnDecision: IrnCancellationDecision::NotRequired,
                isB2b: true,
                hasSubmittedIrn: false,
                irnAgeHours: null,
                summary: 'B2B invoice without a submitted IRN: cancel locally without IRN cancellation.',
            );
        }

        if ($this->irnAlreadyCancelledLocally($invoice->eInvoiceRecord)) {
            return new StatutoryCancellationPolicyResult(
                workflow: StatutoryCancellationWorkflow::B2bWithinWindowCancelInvoice,
                irnDecision: IrnCancellationDecision::AlreadyCancelled,
                isB2b: true,
                hasSubmittedIrn: true,
                irnAgeHours: $irnAgeHours,
                summary: 'B2B invoice IRN is already cancelled locally; proceed with invoice cancellation.',
            );
        }

        if ($irnAgeHours !== null && $irnAgeHours < $windowHours) {
            return new StatutoryCancellationPolicyResult(
                workflow: StatutoryCancellationWorkflow::B2bWithinWindowCancelInvoice,
                irnDecision: IrnCancellationDecision::CancellationRequired,
                isB2b: true,
                hasSubmittedIrn: true,
                irnAgeHours: $irnAgeHours,
                summary: sprintf('B2B IRN is within the %dh cancellation window; cancel IRN then cancel invoice.', $windowHours),
            );
        }

        return new StatutoryCancellationPolicyResult(
            workflow: StatutoryCancellationWorkflow::B2bBeyondWindowCreditNote,
            irnDecision: IrnCancellationDecision::CancellationNotPermittedByWindow,
            isB2b: true,
            hasSubmittedIrn: true,
            irnAgeHours: $irnAgeHours,
            summary: sprintf('B2B IRN is older than %dh; original invoice stays active and a GST credit note is required.', $windowHours),
        );
    }

    public function irnDecisionFor(StatutoryInvoice $invoice): IrnCancellationDecision
    {
        return $this->evaluate($invoice)->irnDecision;
    }

    private function isB2b(StatutoryInvoice $invoice): bool
    {
        $buyerGstin = BuyerGstin::normalize($invoice->buyer_gstin);

        return $buyerGstin !== null && BuyerGstin::isValid($buyerGstin);
    }

    private function hasSubmittedIrn(StatutoryInvoice $invoice): bool
    {
        return $this->eligibility->irnCancellationRequired($invoice);
    }

    private function irnAgeHours(StatutoryInvoice $invoice): ?float
    {
        $record = $invoice->eInvoiceRecord;
        if ($record === null || ! $record->hasIssuedIrn()) {
            return null;
        }

        $anchor = $record->ack_date ?? $invoice->issued_at;
        if ($anchor === null) {
            return null;
        }

        $anchor = $anchor instanceof Carbon ? $anchor : Carbon::parse($anchor);

        return max(0, $anchor->diffInMinutes(now()) / 60);
    }

    private function cancellationWindowHours(): int
    {
        $hours = (int) config('statutory_invoices.einvoice.irn_cancellation_window_hours', 24);

        return $hours > 0 ? $hours : 24;
    }

    private function irnAlreadyCancelledLocally(?EInvoiceRecord $record): bool
    {
        if ($record === null) {
            return false;
        }

        $payload = $record->response_payload;

        return is_array($payload) && ($payload['irn_cancelled'] ?? false) === true;
    }
}
