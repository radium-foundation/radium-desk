<?php

namespace App\Reports\CaMonthly;

use App\Models\CommerceOrder;
use App\Models\InventorySale;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Support\Inventory\PosSalePaymentState;

/**
 * Resolves verified collected payment amount for CA Monthly payment-channel classification.
 */
final class CaMonthlyReportPaidAmountResolver
{
    /**
     * Inclusive tolerance for CA reporting: differences at or below this amount
     * are treated as fully paid (not Partial Paid).
     */
    public const PARTIAL_PAID_TOLERANCE = 1.00;

    public function resolveVerifiedPaidAmount(
        StatutoryInvoice $invoice,
        ?CommerceOrder $commerceOrder = null,
        ?Order $supportOrder = null,
        float $allocationTotal = 0.0,
        ?InventorySale $inventorySale = null,
    ): float {
        $amounts = [];

        if ($allocationTotal > 0) {
            $amounts[] = round($allocationTotal, 2);
        }

        if ($supportOrder !== null) {
            $supportPaid = round((float) ($supportOrder->payment_amount ?? 0), 2);
            if ($supportPaid > 0) {
                $amounts[] = $supportPaid;
            }
        }

        if ($commerceOrder !== null && $this->commerceOrderReportsPayment($commerceOrder)) {
            $commercePaid = round((float) ($commerceOrder->order_value ?? 0), 2);
            if ($commercePaid > 0) {
                $amounts[] = $commercePaid;
            }
        }

        if ($inventorySale !== null
            && ! PosSalePaymentState::displaysAsPaymentPending($inventorySale)
            && $this->nullableString($inventorySale->payment_method) !== null) {
            $salePaid = round((float) ($inventorySale->total ?? 0), 2);
            if ($salePaid > 0) {
                $amounts[] = $salePaid;
            }
        }

        if ($amounts === []) {
            return 0.0;
        }

        return min($amounts);
    }

    public function paymentDifference(float $verifiedPaidAmount, float $invoiceValue): float
    {
        return round(abs(round($invoiceValue, 2) - round($verifiedPaidAmount, 2)), 2);
    }

    public function isPartiallyPaid(float $verifiedPaidAmount, float $invoiceValue): bool
    {
        $invoiceValue = round($invoiceValue, 2);
        $verifiedPaidAmount = round($verifiedPaidAmount, 2);

        if ($invoiceValue <= 0 || $verifiedPaidAmount <= 0) {
            return false;
        }

        return $this->paymentDifference($verifiedPaidAmount, $invoiceValue) > self::PARTIAL_PAID_TOLERANCE;
    }

    private function commerceOrderReportsPayment(CommerceOrder $commerceOrder): bool
    {
        $status = strtolower((string) ($commerceOrder->payment_status ?? ''));
        if (in_array($status, ['paid', 'partial', 'partially_paid'], true)) {
            return true;
        }

        return $this->nullableString($commerceOrder->payment_method) !== null
            || $this->nullableString($commerceOrder->payment_reference) !== null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
