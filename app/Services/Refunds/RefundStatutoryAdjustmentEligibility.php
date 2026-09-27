<?php

namespace App\Services\Refunds;

use App\Enums\RefundStatutoryAdjustmentStatus;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\RefundRequest;
use App\Models\RefundStatutoryAdjustment;
use App\Models\StatutoryInvoice;
use App\Services\RefundCalculationService;
use App\Services\Refunds\Data\RefundStatutoryAdjustmentEligibilityResult;

final class RefundStatutoryAdjustmentEligibility
{
    private const AMOUNT_TOLERANCE = 0.01;

    public function __construct(
        private readonly OrderStatutoryInvoiceResolver $invoiceResolver,
        private readonly RefundCalculationService $calculations,
    ) {}

    public function evaluate(RefundRequest $refund): RefundStatutoryAdjustmentEligibilityResult
    {
        if (! $refund->status->isTerminalSuccess()) {
            return RefundStatutoryAdjustmentEligibilityResult::skip('refund_not_terminal_success');
        }

        $order = $refund->order;
        if ($order === null) {
            return RefundStatutoryAdjustmentEligibilityResult::skip('missing_order');
        }

        $resolution = $this->invoiceResolver->resolveForOrder($order);
        if ($resolution->invoice === null) {
            return RefundStatutoryAdjustmentEligibilityResult::skip(
                $resolution->skipReason ?? 'no_linked_statutory_invoice',
            );
        }

        $invoice = $resolution->invoice;

        if ($invoice->document_type !== StatutoryInvoiceDocumentType::TaxInvoice) {
            return RefundStatutoryAdjustmentEligibilityResult::skip('not_tax_invoice', $invoice);
        }

        if ($invoice->status === StatutoryInvoiceStatus::Cancelled) {
            return RefundStatutoryAdjustmentEligibilityResult::skip('invoice_already_cancelled', $invoice);
        }

        if ($invoice->status !== StatutoryInvoiceStatus::Issued) {
            return RefundStatutoryAdjustmentEligibilityResult::skip('invoice_not_issued', $invoice);
        }

        if ($this->hasExistingCreditNote($invoice)) {
            return RefundStatutoryAdjustmentEligibilityResult::skip('existing_credit_note', $invoice);
        }

        if ($this->hasSucceededAdjustmentForInvoice($invoice, $refund)) {
            return RefundStatutoryAdjustmentEligibilityResult::skip('invoice_already_adjusted', $invoice);
        }

        if (! $this->cumulativeRefundIsFull($refund)) {
            return RefundStatutoryAdjustmentEligibilityResult::skip('partial_refund', $invoice);
        }

        return RefundStatutoryAdjustmentEligibilityResult::eligible($invoice);
    }

    private function cumulativeRefundIsFull(RefundRequest $refund): bool
    {
        $order = $refund->order;
        if ($order === null) {
            return false;
        }

        $order->loadMissing('refundRequests');

        $calculation = $this->calculations->calculate($order);
        $totalPaid = $calculation->totalPaidAmount;
        $alreadyRefunded = $calculation->alreadyRefundedAmount;

        if ($totalPaid <= 0) {
            $refundAmount = $refund->displayAmount();

            return $refundAmount > 0 && $alreadyRefunded >= ($refundAmount - self::AMOUNT_TOLERANCE);
        }

        return $alreadyRefunded >= ($totalPaid - self::AMOUNT_TOLERANCE);
    }

    private function hasExistingCreditNote(StatutoryInvoice $invoice): bool
    {
        return StatutoryInvoice::query()
            ->where('original_statutory_invoice_id', $invoice->id)
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->exists();
    }

    private function hasSucceededAdjustmentForInvoice(StatutoryInvoice $invoice, RefundRequest $refund): bool
    {
        return RefundStatutoryAdjustment::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->where('status', RefundStatutoryAdjustmentStatus::Succeeded)
            ->where('refund_request_id', '!=', $refund->id)
            ->exists();
    }
}
