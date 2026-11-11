<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\StatutoryInvoiceCancelledRecoveryPaymentPath;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceCancelledRecoveryAssessment;

/**
 * Read-only recovery guidance after statutory invoice cancellation.
 *
 * Does not mint invoices, record payments, credit wallets, execute refunds, or call OPM providers.
 */
final class StatutoryInvoiceCancelledRecoveryService
{
    private const SOURCE_UNIQUE_INDEX = 'statutory_invoices_source_unique';

    public function __construct(
        private readonly StatutoryInvoicePaymentReadService $payments,
        private readonly StatutoryInvoiceRefundReviewService $refundReview,
        private readonly StatutoryMintEligibility $mintEligibility,
    ) {}

    public function assess(StatutoryInvoice $invoice): StatutoryInvoiceCancelledRecoveryAssessment
    {
        if ($invoice->status !== StatutoryInvoiceStatus::Cancelled) {
            return $this->notApplicable();
        }

        $paymentSummary = $this->payments->summary($invoice);
        $paymentPath = $this->resolvePaymentPath($invoice, $paymentSummary);
        $refundReview = $this->refundReview->snapshot($invoice);

        [$replacementMintAllowed, $blockers, $sourceSummary, $issueHint] = $this->replacementMintAssessment($invoice);

        $unpaidSteps = [
            'Confirm with Finance/CA that no off-Desk payment was received for this amount.',
            'If the service remains billable, plan a replacement invoice with a new statutory number and IRN when policy allows.',
            'Collect payment only against the replacement invoice using normal Finance payment evidence and allocations.',
        ];

        $paidDecisions = [
            'Attach verified receipt evidence (bank UTR, gateway settlement, or Desk payment allocations).',
            'Choose one Finance/CA-approved treatment — do not combine full refund and full replacement allocation for the same receipt.',
            'Allocate verified receipt to a replacement invoice after it is issued.',
            'Refund through the approved RefundRequest workflow when a linked order exists.',
            'Wallet credit only when an explicit refund/wallet policy applies — never as a side effect of cancellation.',
            'OPM (Original Payment Method) conversion is a separate refund-related step and is not available from cancellation alone.',
        ];

        $separation = [
            'Cancellation, payment recording, refunds, wallet credits, and OPM are separate lifecycle events.',
            'This panel does not execute financial movements.',
        ];

        return new StatutoryInvoiceCancelledRecoveryAssessment(
            applies: true,
            paymentPath: $paymentPath,
            invoiceValue: $paymentSummary->invoiceValue,
            deskAmountReceived: $paymentSummary->amountReceived,
            deskAmountOutstanding: $paymentSummary->amountOutstanding,
            allocationBackedPaymentEvidence: $paymentSummary->allocationBacked,
            replacementMintAllowed: $replacementMintAllowed,
            replacementBlockers: $blockers,
            billableSourceSummary: $sourceSummary,
            issueEntryHint: $issueHint,
            unpaidNextSteps: $unpaidSteps,
            paidFinanceDecisions: $paidDecisions,
            separationReminders: $separation,
            walletToOpmSupportedOnMain: $this->walletToOpmSupportedOnMain(),
            refundReview: $refundReview,
        );
    }

    private function notApplicable(): StatutoryInvoiceCancelledRecoveryAssessment
    {
        return new StatutoryInvoiceCancelledRecoveryAssessment(
            applies: false,
            paymentPath: StatutoryInvoiceCancelledRecoveryPaymentPath::NotCancelled,
            invoiceValue: 0.0,
            deskAmountReceived: 0.0,
            deskAmountOutstanding: 0.0,
            allocationBackedPaymentEvidence: false,
            replacementMintAllowed: false,
            replacementBlockers: [],
            billableSourceSummary: null,
            issueEntryHint: null,
            unpaidNextSteps: [],
            paidFinanceDecisions: [],
            separationReminders: [],
            walletToOpmSupportedOnMain: false,
            refundReview: null,
        );
    }

    /**
     * @return array{0: bool, 1: list<string>, 2: ?string, 3: ?string}
     */
    private function replacementMintAssessment(StatutoryInvoice $invoice): array
    {
        $blockers = [];
        $blockers[] = 'Cancelled invoices cannot be reopened and invoice numbers cannot be reused.';

        $blockers[] = 'Database uniqueness on (channel, source_type, source_id) ('.self::SOURCE_UNIQUE_INDEX.') prevents minting a second tax invoice for the same source identity while this row exists.';

        $sourceType = $this->resolveSourceType($invoice);

        $existing = app(StatutoryInvoiceService::class)->findBySource(
            $invoice->channel,
            $sourceType,
            (string) $invoice->source_id,
        );
        if ($existing !== null && $existing->id === $invoice->id) {
            $blockers[] = 'Issue/mint idempotency returns this cancelled invoice for the current source key — a replacement requires a new billable source or an approved schema/policy change.';
        }

        $sourceSummary = sprintf(
            '%s / %s / %s',
            $invoice->channel->value,
            $sourceType->value,
            $invoice->source_id,
        );

        $issueHint = match ($sourceType) {
            StatutoryInvoiceSourceType::CommerceOrder => 'Finance → pending invoices → commerce order issue (after a new commerce source exists).',
            StatutoryInvoiceSourceType::InventorySale => 'Finance → pending invoices → POS sale issue (after sale eligibility is satisfied).',
            StatutoryInvoiceSourceType::ServiceOrder => 'Finance → pending invoices → service order issue.',
            default => 'Use the Finance statutory issue flow appropriate to this channel.',
        };

        $this->appendEligibilityBlockers($invoice, $blockers);

        return [false, $blockers, $sourceSummary, $issueHint];
    }

    /**
     * @param  list<string>  $blockers
     */
    private function appendEligibilityBlockers(StatutoryInvoice $invoice, array &$blockers): void
    {
        try {
            if ($this->resolveSourceType($invoice) === StatutoryInvoiceSourceType::CommerceOrder) {
                $order = CommerceOrder::query()
                    ->where('source_id', $invoice->source_id)
                    ->where('channel', $invoice->channel->value)
                    ->first();
                if ($order === null) {
                    $blockers[] = 'Linked commerce order row was not found for eligibility re-check.';

                    return;
                }
                $result = $this->mintEligibility->evaluateOrder($order);
                if (! $result->canMint) {
                    $blockers[] = 'Commerce source eligibility: '.implode(' ', $result->errors);
                }
            }
        } catch (\Throwable) {
            $blockers[] = 'Could not re-evaluate commerce mint eligibility.';
        }
    }

    private function resolvePaymentPath(
        StatutoryInvoice $invoice,
        \App\Services\StatutoryInvoice\Data\StatutoryInvoicePaymentSummary $summary,
    ): StatutoryInvoiceCancelledRecoveryPaymentPath {
        if ($summary->amountReceived > 0.001) {
            return StatutoryInvoiceCancelledRecoveryPaymentPath::PaidDeskAllocations;
        }

        if ($summary->allocationBacked) {
            return StatutoryInvoiceCancelledRecoveryPaymentPath::Unpaid;
        }

        if (is_string($invoice->payment_method) && trim($invoice->payment_method) !== '') {
            return StatutoryInvoiceCancelledRecoveryPaymentPath::PaidLegacyInvoiceFields;
        }

        if ($summary->amountOutstanding > 0.001) {
            return StatutoryInvoiceCancelledRecoveryPaymentPath::Unpaid;
        }

        return StatutoryInvoiceCancelledRecoveryPaymentPath::Ambiguous;
    }

    private function walletToOpmSupportedOnMain(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('wallet_refund_opm_conversions');
    }

    private function resolveSourceType(StatutoryInvoice $invoice): StatutoryInvoiceSourceType
    {
        $value = $invoice->source_type;

        return $value instanceof StatutoryInvoiceSourceType
            ? $value
            : StatutoryInvoiceSourceType::from((string) $value);
    }
}
