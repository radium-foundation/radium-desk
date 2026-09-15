<?php

namespace App\Services\Pos;

use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
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
                'status_label' => 'e-Invoice generated successfully',
                'tone' => 'success',
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
                'tone' => 'info',
                'irn' => null,
                'ack_no' => null,
                'ack_date' => null,
                'why' => 'B2C / not eligible for IRN.',
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

        $status = $this->storedStatus($record);
        $skipReason = $this->storedSkipReason($record);

        if (in_array($status, [
            EInvoiceRecordStatus::Failed->value,
            EInvoiceRecordStatus::PermanentFailure->value,
            EInvoiceRecordStatus::Ambiguous->value,
            EInvoiceRecordStatus::IrnNotFound->value,
        ], true)) {
            return [
                'status_label' => 'e-Invoice not generated',
                'tone' => 'danger',
                'irn' => null,
                'ack_no' => null,
                'ack_date' => null,
                'why' => 'The e-invoice provider did not issue an IRN for this invoice.',
                'next_action' => 'Retry from this sale after the blocker is cleared. Do not generate IRN twice.',
            ];
        }

        if ($status === EInvoiceRecordStatus::TemporaryFailure->value) {
            return [
                'status_label' => 'e-Invoice pending',
                'tone' => 'warning',
                'irn' => null,
                'ack_no' => null,
                'ack_date' => null,
                'why' => 'The e-invoice provider was temporarily unavailable. The worker will retry without creating a duplicate GENERATE.',
                'next_action' => 'Refresh this page shortly.',
            ];
        }

        if ($this->isProviderDisabledSkip($status, $skipReason)) {
            return [
                'status_label' => 'e-Invoice not submitted',
                'tone' => 'warning',
                'irn' => null,
                'ack_no' => null,
                'ack_date' => null,
                'why' => 'This B2B invoice was not sent for IRN because the e-invoice provider is disabled. It is not queued.',
                'next_action' => 'Re-enable the e-invoice provider, then recover with Get-IRN first. Do not generate a second IRN.',
            ];
        }

        if ($status === EInvoiceRecordStatus::Skipped->value) {
            return [
                'status_label' => 'e-Invoice not submitted',
                'tone' => 'warning',
                'irn' => null,
                'ack_no' => null,
                'ack_date' => null,
                'why' => 'This B2B invoice was skipped and is not queued for IRN.',
                'next_action' => 'Review the skip reason, then recover with Get-IRN first if the invoice is still eligible.',
            ];
        }

        return [
            'status_label' => 'e-Invoice pending',
            'tone' => 'warning',
            'irn' => null,
            'ack_no' => null,
            'ack_date' => null,
            'why' => 'Eligible B2B invoice is queued for IRN.',
            'next_action' => 'Refresh this page after the queue worker runs. PDF finalizes automatically after IRN.',
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
            'status_label' => 'e-Invoice not generated',
            'tone' => 'danger',
            'irn' => null,
            'ack_no' => null,
            'ack_date' => null,
            'why' => $presentation['why'],
            'next_action' => $presentation['next_action'],
        ];
    }

    private function isProviderDisabledSkip(?string $status, ?string $skipReason): bool
    {
        if ($status !== EInvoiceRecordStatus::Skipped->value) {
            return false;
        }

        return in_array($skipReason, [
            'provider_disabled',
            'worker_may_mint_off',
            'provider_http_disabled',
        ], true);
    }

    private function storedStatus(?EInvoiceRecord $record): ?string
    {
        $status = $record?->status;
        if ($status instanceof EInvoiceRecordStatus) {
            return $status->value;
        }

        return is_string($status) && $status !== '' ? $status : null;
    }

    private function storedSkipReason(?EInvoiceRecord $record): ?string
    {
        $payloadReason = $record?->response_payload['skip_reason'] ?? null;
        if (is_string($payloadReason) && $payloadReason !== '') {
            return $payloadReason;
        }

        $column = $record?->getAttribute('skip_reason');

        return is_string($column) && $column !== '' ? $column : null;
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
