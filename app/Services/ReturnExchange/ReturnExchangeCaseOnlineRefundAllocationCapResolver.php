<?php

namespace App\Services\ReturnExchange;

use App\Enums\RefundStatus;
use App\Models\CommerceOrder;
use App\Models\Order;
use App\Models\ReturnExchangeCase;
use App\Models\StatutoryInvoice;
use App\Services\ReturnExchange\Data\ResolvedOnlineHardwareReturnContext;
use App\Services\ReturnExchange\Data\ReturnExchangeCaseOnlineRefundAllocationCaps;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Derives allocation caps from persisted commerce/support payment facts only.
 */
final class ReturnExchangeCaseOnlineRefundAllocationCapResolver
{
    public function __construct(
        private readonly ReturnExchangeCaseReturnedHardwareEligibleRefundService $eligibleAmounts,
    ) {}

    public function resolve(
        ReturnExchangeCase $case,
        ResolvedOnlineHardwareReturnContext $context,
        StatutoryInvoice $taxInvoice,
    ): ReturnExchangeCaseOnlineRefundAllocationCaps {
        $fulfilment = $context->fulfilment;
        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'order' => 'Hardware fulfilment linkage is required for Finance allocation.',
            ]);
        }

        $fulfilment->loadMissing('commerceOrder');
        $commerce = $fulfilment->commerceOrder;
        if ($commerce === null) {
            throw ValidationException::withMessages([
                'order' => 'Commerce order could not be loaded for this return case.',
            ]);
        }

        $this->assertSplitTenderEvidence($commerce);
        $orderPaidTotal = $this->resolveOrderPaidTotal($commerce, $context->order);
        $priorRefunds = $this->sumQualifyingPriorRefunds($context->order);
        $returnedLineCap = $this->eligibleAmounts->resolve($case, $context, $taxInvoice)->amount;

        $orderRemainder = max(0, round($orderPaidTotal - $priorRefunds, 2));
        $lineCap = round((float) $returnedLineCap, 2);
        $maxAllocatable = min($lineCap, $orderRemainder);

        if ($maxAllocatable < 0.01) {
            throw ValidationException::withMessages([
                'allocation' => 'No refundable amount remains for Finance allocation on this order.',
            ]);
        }

        $currency = strtoupper(trim((string) ($commerce->currency ?? 'INR')));
        if ($currency !== 'INR') {
            throw ValidationException::withMessages([
                'allocation' => 'Finance allocation is only supported for INR orders with verified payment evidence.',
            ]);
        }

        return new ReturnExchangeCaseOnlineRefundAllocationCaps(
            returnedLineCap: number_format($lineCap, 2, '.', ''),
            orderPaidTotal: number_format($orderPaidTotal, 2, '.', ''),
            priorRefundsTotal: number_format($priorRefunds, 2, '.', ''),
            maxAllocatableAmount: number_format($maxAllocatable, 2, '.', ''),
            currency: $currency,
        );
    }

    private function assertSplitTenderEvidence(CommerceOrder $commerce): void
    {
        $wallet = $commerce->wallet_tender_amount !== null
            ? round((float) $commerce->wallet_tender_amount, 2)
            : 0.0;
        $walletReference = trim((string) ($commerce->wallet_tender_reference ?? ''));

        if ($wallet < 0.01 && $walletReference === '') {
            throw ValidationException::withMessages([
                'allocation' => 'Finance allocation is required only for split wallet + external tender orders.',
            ]);
        }
    }

    private function resolveOrderPaidTotal(CommerceOrder $commerce, Order $order): float
    {
        $external = round((float) ($order->payment_amount ?? 0), 2);
        if ($external < 0.01) {
            throw ValidationException::withMessages([
                'allocation' => 'External payment amount is missing on the support order.',
            ]);
        }

        if (filled($order->cashfree_payment_id) === false && filled($order->gateway_payment_id) === false) {
            throw ValidationException::withMessages([
                'allocation' => 'External payment reference is missing on the support order.',
            ]);
        }

        $wallet = $commerce->wallet_tender_amount !== null
            ? round((float) $commerce->wallet_tender_amount, 2)
            : 0.0;
        if ($wallet < 0.01) {
            throw ValidationException::withMessages([
                'allocation' => 'Wallet tender amount is missing on the commerce order.',
            ]);
        }

        $totalPaid = round($external + $wallet, 2);
        $orderValue = $commerce->order_value !== null ? round((float) $commerce->order_value, 2) : null;
        if ($orderValue !== null && abs($totalPaid - $orderValue) > 0.02) {
            throw ValidationException::withMessages([
                'allocation' => 'Order payment totals could not be reconciled from persisted commerce and support fields.',
            ]);
        }

        return $totalPaid;
    }

    private function sumQualifyingPriorRefunds(Order $order): float
    {
        return round((float) $order->refundRequests()
            ->whereIn('status', array_map(
                fn (RefundStatus $status): string => $status->value,
                array_filter(
                    RefundStatus::cases(),
                    fn (RefundStatus $status): bool => $status->countsTowardAlreadyRefunded(),
                ),
            ))
            ->sum(DB::raw('COALESCE(refund_amount, amount)')), 2);
    }
}
