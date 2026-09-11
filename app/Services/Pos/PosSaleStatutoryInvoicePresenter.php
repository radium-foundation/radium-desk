<?php

namespace App\Services\Pos;

use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Customer360\EInvoiceAgentPresentation;
use App\Services\Notifications\NotificationMailSender;
use App\Services\StatutoryInvoice\EInvoiceEligibility;
use App\Services\StatutoryInvoice\EInvoiceInputReadiness;
use App\Services\StatutoryInvoice\EInvoiceIrnGuard;
use App\Services\StatutoryInvoice\EInvoiceProviderSubmissionBlocker;
use App\Services\StatutoryInvoice\StatutoryMintEligibility;
use App\Support\AppDateFormatter;
use App\Support\Finance\FinanceAccess;

final class PosSaleStatutoryInvoicePresenter
{
    public function __construct(
        private readonly StatutoryMintEligibility $mintEligibility,
        private readonly EInvoiceEligibility $eligibility,
        private readonly EInvoiceInputReadiness $readiness,
        private readonly EInvoiceAgentPresentation $agentPresentation,
        private readonly EInvoiceProviderSubmissionBlocker $providerBlocker,
        private readonly NotificationMailSender $mailSender,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forSale(InventorySale $sale, ?User $user): array
    {
        $sale->loadMissing(['statutoryInvoice.eInvoiceRecord', 'customer']);
        $invoice = $sale->statutoryInvoice;

        if ($invoice === null) {
            $evaluation = $this->mintEligibility->evaluateSale($sale);

            return [
                'minted' => false,
                'invoice_number' => null,
                'status_label' => 'Not issued',
                'eligibility_summary' => $evaluation->staffSummary(),
                'eligibility_errors' => $evaluation->errors,
                'can_issue_from_finance' => $user !== null && FinanceAccess::allowsInvoices($user),
                'issue_url' => $user !== null && FinanceAccess::allowsInvoices($user)
                    ? route('finance.invoices.sales.issue', ['sale' => $sale])
                    : null,
            ];
        }

        $customerEmail = is_string($sale->customer?->email) ? trim($sale->customer->email) : '';
        $viewUrl = route('pos.sales.statutory.pdf', $sale);
        $downloadUrl = route('pos.sales.statutory.download', $sale);

        return [
            'minted' => true,
            'invoice_number' => (string) $invoice->invoice_number,
            'issued_at_label' => AppDateFormatter::date($invoice->issued_at),
            'status_label' => 'Issued',
            'view_url' => $viewUrl,
            'download_url' => $downloadUrl,
            'share_url' => $viewUrl,
            'share_title' => 'Tax invoice '.(string) $invoice->invoice_number,
            'email_url' => route('pos.sales.statutory.email', $sale),
            'can_email' => $customerEmail !== '' && $this->mailSender->isEnabled(),
            'email_unavailable_reason' => $customerEmail === ''
                ? 'Customer email is not on file.'
                : ($this->mailSender->isEnabled() ? null : 'Email delivery is disabled.'),
            'customer_email' => $customerEmail,
            'einvoice' => $this->einvoicePresentation($invoice),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function einvoicePresentation(StatutoryInvoice $invoice): array
    {
        $record = $invoice->eInvoiceRecord;
        if (EInvoiceIrnGuard::recordHasIssuedIrn($record)) {
            return [
                'status_label' => 'IRN Generated',
                'irn' => trim((string) $record?->irn),
                'ack_no' => $this->nullableTrim($record?->ack_no),
                'ack_date' => AppDateFormatter::datetime24($record?->ack_date),
                'why' => null,
                'next_action' => null,
            ];
        }

        $eligibility = $this->eligibility->evaluate($invoice);
        $readiness = $this->readiness->evaluate($invoice);

        if ($eligibility->reason === 'b2c_not_eligible') {
            return [
                'status_label' => 'Not Applicable',
                'irn' => null,
                'ack_no' => null,
                'ack_date' => null,
                'why' => 'B2C / not eligible',
                'next_action' => null,
            ];
        }

        if (! $readiness->ready) {
            return $this->blockedPresentation($readiness->blockedReasonLines());
        }

        $providerReason = $this->providerBlocker->blockedReason($record);
        if ($providerReason !== null) {
            return $this->blockedPresentation([$providerReason]);
        }

        return [
            'status_label' => 'Pending',
            'irn' => null,
            'ack_no' => null,
            'ack_date' => null,
            'why' => null,
            'next_action' => null,
        ];
    }

    /**
     * @param  list<string>  $blocked
     * @return array<string, mixed>
     */
    private function blockedPresentation(array $blocked): array
    {
        $presentation = in_array('buyer_state_mismatch', $blocked, true)
            && ! in_array('place_of_supply_unresolved', $blocked, true)
            && ! in_array('buyer_pin_gstin_state_mismatch', $blocked, true)
            ? $this->agentPresentation->posReview()
            : $this->agentPresentation->blocked($blocked);

        return [
            'status_label' => $presentation['status_label'],
            'irn' => null,
            'ack_no' => null,
            'ack_date' => null,
            'why' => $presentation['why'],
            'next_action' => $presentation['next_action'],
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
