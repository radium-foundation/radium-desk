<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\RefundStatus;
use App\Enums\StatutoryInvoiceRefundReviewStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\StatutoryInvoice;
use App\Services\RefundCalculationService;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceRefundReviewSnapshot;

/**
 * Read-only bridge between cancelled statutory invoices and the existing refund workflow.
 *
 * Does not create refund requests, execute wallet credits, or call payment providers.
 */
final class StatutoryInvoiceRefundReviewService
{
    public function __construct(
        private readonly StatutoryInvoiceLinkedOrderResolver $linkedOrders,
        private readonly RefundCalculationService $calculations,
    ) {}

    public function snapshot(StatutoryInvoice $invoice): StatutoryInvoiceRefundReviewSnapshot
    {
        if ($invoice->status !== StatutoryInvoiceStatus::Cancelled) {
            return new StatutoryInvoiceRefundReviewSnapshot(
                status: StatutoryInvoiceRefundReviewStatus::NotApplicable,
                message: 'Refund review applies only after statutory invoice cancellation.',
                posBoundary: false,
            );
        }

        $order = $this->linkedOrders->resolve($invoice);
        if (! $order instanceof Order) {
            if ($invoice->inventory_sale_id !== null) {
                return new StatutoryInvoiceRefundReviewSnapshot(
                    status: StatutoryInvoiceRefundReviewStatus::NotApplicable,
                    message: 'Desk POS sales do not use the service refund workflow. Customer cash/UPI refunds remain manual operations after cancellation.',
                    posBoundary: true,
                );
            }

            return new StatutoryInvoiceRefundReviewSnapshot(
                status: StatutoryInvoiceRefundReviewStatus::NotApplicable,
                message: 'No linked service order was found for refund review.',
                posBoundary: false,
            );
        }

        $order->loadMissing('refundRequests');
        $calculation = $this->calculations->calculate($order);
        $activeRefund = $this->resolveActiveRefundRequest($order);

        if ($activeRefund instanceof RefundRequest) {
            return $this->snapshotForActiveRefund($order, $calculation->totalPaidAmount, $calculation->alreadyRefundedAmount, $calculation->maximumRefundable, $activeRefund);
        }

        if ($calculation->totalPaidAmount <= 0 && $calculation->maximumRefundable <= 0) {
            return new StatutoryInvoiceRefundReviewSnapshot(
                status: StatutoryInvoiceRefundReviewStatus::NotApplicable,
                message: 'Linked order has no recorded payment amount for Desk refund review.',
                posBoundary: false,
                linkedOrderId: $order->id,
                linkedOrderPublicId: $order->order_id,
                paymentMethod: $order->payment_method,
                totalPaidAmount: $calculation->totalPaidAmount,
                alreadyRefundedAmount: $calculation->alreadyRefundedAmount,
                maximumRefundable: $calculation->maximumRefundable,
            );
        }

        if ($calculation->maximumRefundable <= 0) {
            return new StatutoryInvoiceRefundReviewSnapshot(
                status: StatutoryInvoiceRefundReviewStatus::Completed,
                message: 'Linked order payment has already been fully refunded through the existing refund workflow.',
                posBoundary: false,
                linkedOrderId: $order->id,
                linkedOrderPublicId: $order->order_id,
                paymentMethod: $order->payment_method,
                totalPaidAmount: $calculation->totalPaidAmount,
                alreadyRefundedAmount: $calculation->alreadyRefundedAmount,
                maximumRefundable: $calculation->maximumRefundable,
            );
        }

        $latestRejected = $order->refundRequests
            ->sortByDesc('id')
            ->first(fn (RefundRequest $refund): bool => $refund->status === RefundStatus::Rejected);

        if ($latestRejected instanceof RefundRequest) {
            return new StatutoryInvoiceRefundReviewSnapshot(
                status: StatutoryInvoiceRefundReviewStatus::Failed,
                message: 'Latest refund request was rejected. Create a new refund request through the existing approval workflow if customer money must be returned.',
                posBoundary: false,
                linkedOrderId: $order->id,
                linkedOrderPublicId: $order->order_id,
                paymentMethod: $order->payment_method,
                totalPaidAmount: $calculation->totalPaidAmount,
                alreadyRefundedAmount: $calculation->alreadyRefundedAmount,
                maximumRefundable: $calculation->maximumRefundable,
                activeRefundRequestId: $latestRejected->id,
                activeRefundReference: $latestRejected->reference_no,
            );
        }

        return new StatutoryInvoiceRefundReviewSnapshot(
            status: StatutoryInvoiceRefundReviewStatus::RefundReviewRequired,
            message: 'Invoice cancellation does not refund customer money. Review the linked order and create an explicit refund request if required.',
            posBoundary: false,
            linkedOrderId: $order->id,
            linkedOrderPublicId: $order->order_id,
            paymentMethod: $order->payment_method,
            totalPaidAmount: $calculation->totalPaidAmount,
            alreadyRefundedAmount: $calculation->alreadyRefundedAmount,
            maximumRefundable: $calculation->maximumRefundable,
        );
    }

    private function resolveActiveRefundRequest(Order $order): ?RefundRequest
    {
        return $order->refundRequests
            ->sortByDesc('id')
            ->first(fn (RefundRequest $refund): bool => in_array($refund->status, [
                RefundStatus::Pending,
                RefundStatus::PendingExecution,
            ], true));
    }

    private function snapshotForActiveRefund(
        Order $order,
        float $totalPaid,
        float $alreadyRefunded,
        float $maximumRefundable,
        RefundRequest $refund,
    ): StatutoryInvoiceRefundReviewSnapshot {
        $status = match ($refund->status) {
            RefundStatus::PendingExecution => StatutoryInvoiceRefundReviewStatus::PendingExecution,
            RefundStatus::Pending => StatutoryInvoiceRefundReviewStatus::RefundRequested,
            default => StatutoryInvoiceRefundReviewStatus::RefundReviewRequired,
        };

        $message = match ($status) {
            StatutoryInvoiceRefundReviewStatus::PendingExecution => 'Refund is approved and awaiting execution through the existing Wallet or OPM/manual workflow.',
            StatutoryInvoiceRefundReviewStatus::RefundRequested => 'Refund request is pending approval in the existing refund workflow.',
            default => 'Review the linked refund request in the existing refund workflow.',
        };

        return new StatutoryInvoiceRefundReviewSnapshot(
            status: $status,
            message: $message,
            posBoundary: false,
            linkedOrderId: $order->id,
            linkedOrderPublicId: $order->order_id,
            paymentMethod: $order->payment_method,
            totalPaidAmount: $totalPaid,
            alreadyRefundedAmount: $alreadyRefunded,
            maximumRefundable: $maximumRefundable,
            activeRefundRequestId: $refund->id,
            activeRefundReference: $refund->reference_no,
        );
    }
}
