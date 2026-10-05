<?php

namespace App\Reports\CaMonthly;

use App\Models\RefundRequest;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\BuyerGstin;
use Illuminate\Support\Carbon;

final class CaMonthlyReportRefundReviewClassifier
{
    private const AMOUNT_TOLERANCE = 0.01;

    public function reviewStatus(
        RefundRequest $refund,
        ?StatutoryInvoice $invoice,
        float $cumulativeRefundAmount,
        bool $hasCreditNote,
        ?Carbon $refundCompletedAt,
        ?Carbon $invoiceIssuedAt,
        ?Carbon $irnAckDate,
    ): ?string {
        if ($invoice === null) {
            return null;
        }

        if ($refundCompletedAt !== null && $invoiceIssuedAt !== null && $refundCompletedAt->lt($invoiceIssuedAt)) {
            return CaMonthlyReportDefinition::REVIEW_STATUS_REFUND_BEFORE_INVOICE;
        }

        if ($hasCreditNote) {
            return null;
        }

        if (! $this->isFullRefund($refund, $cumulativeRefundAmount)) {
            return null;
        }

        if (! $this->isB2bWithSubmittedIrn($invoice, $irnAckDate)) {
            return null;
        }

        if ($this->irnAgeHoursAtRefund($refundCompletedAt, $irnAckDate, $invoiceIssuedAt) < $this->cancellationWindowHours()) {
            return null;
        }

        return CaMonthlyReportDefinition::REVIEW_STATUS_POTENTIAL_CN;
    }

    public function irnAgeHoursAtRefund(
        ?Carbon $refundCompletedAt,
        ?Carbon $irnAckDate,
        ?Carbon $invoiceIssuedAt,
    ): ?float {
        if ($refundCompletedAt === null) {
            return null;
        }

        $anchor = $irnAckDate ?? $invoiceIssuedAt;
        if ($anchor === null) {
            return null;
        }

        return max(0, $anchor->diffInMinutes($refundCompletedAt) / 60);
    }

    private function isFullRefund(RefundRequest $refund, float $cumulativeRefundAmount): bool
    {
        $refundAmount = (float) ($refund->refund_amount ?? $refund->amount ?? 0);
        $totalPaid = (float) ($refund->total_paid_amount ?? 0);

        if ($totalPaid <= 0) {
            return $refundAmount > 0 && $cumulativeRefundAmount >= ($refundAmount - self::AMOUNT_TOLERANCE);
        }

        return $cumulativeRefundAmount >= ($totalPaid - self::AMOUNT_TOLERANCE);
    }

    private function isB2bWithSubmittedIrn(StatutoryInvoice $invoice, ?Carbon $irnAckDate): bool
    {
        $gstin = BuyerGstin::normalize($invoice->buyer_gstin);
        if ($gstin === null || ! BuyerGstin::isValid($gstin)) {
            return false;
        }

        $invoice->loadMissing('eInvoiceRecord');
        $irn = $invoice->eInvoiceRecord?->irn;

        return is_string($irn) && trim($irn) !== '' && $irnAckDate !== null;
    }

    private function cancellationWindowHours(): int
    {
        $hours = (int) config('statutory_invoices.einvoice.irn_cancellation_window_hours', 24);

        return $hours > 0 ? $hours : 24;
    }
}
