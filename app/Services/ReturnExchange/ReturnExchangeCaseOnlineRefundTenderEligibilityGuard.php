<?php

namespace App\Services\ReturnExchange;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Services\ReturnExchange\Data\ResolvedOnlineHardwareReturnContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fail-closed guard for case-linked online refund submit when wallet/split tender
 * or prior refund destinations cannot be allocated safely by the generic refund engine.
 */
final class ReturnExchangeCaseOnlineRefundTenderEligibilityGuard
{
    private const TENDER_BLOCK_MESSAGE = 'This case requires Finance tender allocation before a refund request can be submitted.';

    /** @var list<ApprovedRefundMethod> */
    private const EXTERNAL_EXECUTION_METHODS = [
        ApprovedRefundMethod::Cashfree,
        ApprovedRefundMethod::BankTransfer,
        ApprovedRefundMethod::Upi,
    ];

    public function assertMaySubmit(
        ResolvedOnlineHardwareReturnContext $context,
        string $eligibleAmount,
    ): void {
        $fulfilment = $context->fulfilment;
        if ($fulfilment === null) {
            throw ValidationException::withMessages([
                'tender_allocation' => self::TENDER_BLOCK_MESSAGE,
            ]);
        }

        $fulfilment->loadMissing('commerceOrder');
        $commerceOrder = $fulfilment->commerceOrder;
        if ($commerceOrder === null) {
            throw ValidationException::withMessages([
                'tender_allocation' => self::TENDER_BLOCK_MESSAGE,
            ]);
        }

        if ($this->hasPersistedWalletTender($commerceOrder)) {
            throw ValidationException::withMessages([
                'tender_allocation' => self::TENDER_BLOCK_MESSAGE,
            ]);
        }

        $order = $context->order;
        $this->assertPriorRefundsAreExternalOnly($order);
        $this->assertEligibleWithinExternalRemainder($order, $eligibleAmount);
    }

    private function hasPersistedWalletTender(\App\Models\CommerceOrder $commerceOrder): bool
    {
        if ($commerceOrder->wallet_tender_amount !== null
            && round((float) $commerceOrder->wallet_tender_amount, 2) >= 0.01) {
            return true;
        }

        $reference = trim((string) ($commerceOrder->wallet_tender_reference ?? ''));

        return $reference !== '';
    }

    private function assertPriorRefundsAreExternalOnly(Order $order): void
    {
        $prior = $order->refundRequests()
            ->whereIn('status', array_map(
                fn (RefundStatus $status): string => $status->value,
                array_filter(
                    RefundStatus::cases(),
                    fn (RefundStatus $status): bool => $status->countsTowardAlreadyRefunded(),
                ),
            ))
            ->get();

        foreach ($prior as $refund) {
            if (! $this->isVerifiedExternalRefund($refund)) {
                throw ValidationException::withMessages([
                    'tender_allocation' => self::TENDER_BLOCK_MESSAGE,
                ]);
            }
        }
    }

    private function isVerifiedExternalRefund(RefundRequest $refund): bool
    {
        $method = $refund->approved_refund_method;
        if ($method === null) {
            return false;
        }

        return in_array($method, self::EXTERNAL_EXECUTION_METHODS, true);
    }

    private function assertEligibleWithinExternalRemainder(Order $order, string $eligibleAmount): void
    {
        $eligible = round((float) $eligibleAmount, 2);
        $externalPaid = round((float) ($order->payment_amount ?? 0), 2);
        if ($eligible >= 0.01 && $externalPaid < 0.01) {
            throw ValidationException::withMessages([
                'tender_allocation' => self::TENDER_BLOCK_MESSAGE,
            ]);
        }

        if ($externalPaid < 0.01) {
            return;
        }

        $alreadyRefunded = round((float) $order->refundRequests()
            ->whereIn('status', array_map(
                fn (RefundStatus $status): string => $status->value,
                array_filter(
                    RefundStatus::cases(),
                    fn (RefundStatus $status): bool => $status->countsTowardAlreadyRefunded(),
                ),
            ))
            ->sum(DB::raw('COALESCE(refund_amount, amount)')), 2);

        $remainder = max(0, round($externalPaid - $alreadyRefunded, 2));

        if ($eligible > $remainder + 0.001) {
            throw ValidationException::withMessages([
                'tender_allocation' => self::TENDER_BLOCK_MESSAGE,
            ]);
        }
    }
}
