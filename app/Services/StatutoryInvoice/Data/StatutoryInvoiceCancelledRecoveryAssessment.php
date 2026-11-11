<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Enums\StatutoryInvoiceCancelledRecoveryPaymentPath;

final class StatutoryInvoiceCancelledRecoveryAssessment
{
    /**
     * @param  list<string>  $replacementBlockers
     * @param  list<string>  $unpaidNextSteps
     * @param  list<string>  $paidFinanceDecisions
     * @param  list<string>  $separationReminders
     */
    public function __construct(
        public readonly bool $applies,
        public readonly StatutoryInvoiceCancelledRecoveryPaymentPath $paymentPath,
        public readonly float $invoiceValue,
        public readonly float $deskAmountReceived,
        public readonly float $deskAmountOutstanding,
        public readonly bool $allocationBackedPaymentEvidence,
        public readonly bool $replacementMintAllowed,
        public readonly array $replacementBlockers,
        public readonly ?string $billableSourceSummary,
        public readonly ?string $issueEntryHint,
        public readonly array $unpaidNextSteps,
        public readonly array $paidFinanceDecisions,
        public readonly array $separationReminders,
        public readonly bool $walletToOpmSupportedOnMain,
        public readonly ?StatutoryInvoiceRefundReviewSnapshot $refundReview,
    ) {}

    public function requiresExternalPaymentVerification(): bool
    {
        return in_array($this->paymentPath, [
            StatutoryInvoiceCancelledRecoveryPaymentPath::Unpaid,
            StatutoryInvoiceCancelledRecoveryPaymentPath::PaidLegacyInvoiceFields,
            StatutoryInvoiceCancelledRecoveryPaymentPath::Ambiguous,
        ], true);
    }
}
