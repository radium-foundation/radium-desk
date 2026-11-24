<?php

namespace App\Services\Refunds;

use App\CentralWallet\Application\CashfreeCentralCustomerBinder;
use App\Data\Refunds\RefundCalculationResult;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Support\Money\WalletMoney;
use App\Support\Refunds\DeskFirstWalletRefundSpokePolicy;
use Illuminate\Support\Str;

/**
 * Phase 1 wallet refund auto-approval eligibility (read-only policy; no financial side effects).
 */
final class RefundWalletAutoApprovalEvaluator
{
    public const REVIEW_NOTES = 'Auto-approved by wallet refund policy rules';

    public function __construct(
        private readonly WalletRefundDestinationResolver $destinations,
        private readonly CashfreeCentralCustomerBinder $customerBinder,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('refunds.wallet_auto_approval.enabled', false);
    }

    /**
     * @return list<string>
     */
    public static function parseSpokeList(mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = explode(',', (string) $raw);
        }

        $owners = array_values(array_unique(array_filter(array_map(
            static fn (string $part): string => strtolower(trim($part)),
            $parts,
        ))));

        return array_values(array_filter(
            $owners,
            static fn (string $owner): bool => $owner !== ''
                && preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $owner) === 1,
        ));
    }

    /**
     * Pilot spokes intersected with desk-first spoke allowlist (does not expand desk-first scope).
     *
     * @return list<string>
     */
    public function effectivePilotSpokeOwners(): array
    {
        $pilot = self::parseSpokeList(config('refunds.wallet_auto_approval.spokes', ''));
        $deskFirst = array_map('strtolower', DeskFirstWalletRefundSpokePolicy::allowedSpokeOwners());

        return array_values(array_intersect($pilot, $deskFirst));
    }

    public function maxAmount(): string
    {
        $configured = config('refunds.wallet_auto_approval.max_amount', 2000);
        $normalized = WalletMoney::normalize($configured);

        return $normalized ?? '2000.00';
    }

    /**
     * @return array{eligible: bool, reasons: list<string>}
     */
    public function evaluate(
        RefundRequest $refund,
        Order $order,
        RefundCalculationResult $calculation,
    ): array {
        $reasons = [];

        if (! $this->isEnabled()) {
            $reasons[] = 'Wallet refund auto-approval is disabled by configuration.';

            return ['eligible' => false, 'reasons' => $reasons];
        }

        if ($refund->customer_preferred_method !== CustomerPreferredRefundMethod::Wallet) {
            $reasons[] = 'Customer preferred method must be Wallet for pilot auto-approval (OPM and other preferences require manual review).';
        }

        if (! (bool) config('central_wallet.wallet_refund.desk_first_enabled', false)) {
            $reasons[] = 'Central Wallet Desk-First wallet refunds are not enabled.';
        }

        $orderId = trim((string) ($order->order_id ?? ''));
        if ($orderId === '') {
            $reasons[] = 'Order business id is missing.';
        }

        $owner = $orderId !== '' ? $this->destinations->owner($orderId) : null;
        if ($owner === null) {
            $reasons[] = 'Order owner could not be resolved from the business order id.';
        }

        if ($owner !== null && $this->destinations->isRdServiceNet($orderId)) {
            $reasons[] = 'rdservice.net orders are excluded from Phase 1 wallet auto-approval.';
        }

        $effectiveSpokes = $this->effectivePilotSpokeOwners();
        if ($owner !== null && ! in_array(strtolower($owner), $effectiveSpokes, true)) {
            $reasons[] = 'Order owner is not in the effective wallet auto-approval pilot allowlist.';
        }

        if ($orderId !== '' && ! $this->destinations->supportsAutomatedWalletCredit($orderId)) {
            $reasons[] = 'WalletRefundDestinationResolver does not support automated wallet credit for this order.';
        }

        if ($orderId !== '' && ! DeskFirstWalletRefundSpokePolicy::isSpokeAllowedForDeskFirst($orderId, $this->destinations)) {
            $reasons[] = 'Order owner is not on the Desk-First wallet refund spoke allowlist.';
        }

        if ($this->orderHasOtherRefunds($order, $refund)) {
            $reasons[] = 'Another refund request exists for this order.';
        }

        if ($this->orderHasRevokedRefundHistory($order, $refund)) {
            $reasons[] = 'This order has revoked refund history.';
        }

        if ($this->orderHasOtherActiveRefund($order, $refund)) {
            $reasons[] = 'Another pending or in-flight refund exists for this order.';
        }

        if (! $this->isFullRemainingRefundableAmount($calculation)) {
            $reasons[] = 'Only the full remaining refundable amount qualifies for Phase 1 auto-approval.';
        }

        if (! $this->isWithinAmountCap($calculation)) {
            $reasons[] = 'Refund amount exceeds the configured wallet auto-approval cap.';
        }

        if ($calculation->partialDifferenceReason !== null && $calculation->partialDifferenceReason !== '') {
            $reasons[] = 'Partial refund reason is present.';
        }

        if ($calculation->deductionProfileKey !== RefundDeductionProfile::FullRefund->value
            && $calculation->totalDeduction > 0.001) {
            $reasons[] = 'Non-zero deductions require manual review.';
        }

        if (! $this->centralWalletTargetResolvable($order)) {
            $reasons[] = 'Central Wallet customer or wallet could not be resolved for this order.';
        }

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    private function orderHasOtherRefunds(Order $order, RefundRequest $refund): bool
    {
        return RefundRequest::query()
            ->withTrashed()
            ->where('order_id', $order->id)
            ->where('id', '!=', $refund->id)
            ->exists();
    }

    private function orderHasRevokedRefundHistory(Order $order, RefundRequest $refund): bool
    {
        return RefundRequest::query()
            ->where('order_id', $order->id)
            ->where('id', '!=', $refund->id)
            ->where('status', RefundStatus::Revoked)
            ->exists();
    }

    private function orderHasOtherActiveRefund(Order $order, RefundRequest $refund): bool
    {
        return RefundRequest::query()
            ->where('order_id', $order->id)
            ->where('id', '!=', $refund->id)
            ->whereIn('status', [
                RefundStatus::Pending,
                RefundStatus::PendingExecution,
            ])
            ->exists();
    }

    private function isFullRemainingRefundableAmount(RefundCalculationResult $calculation): bool
    {
        $refundAmount = WalletMoney::normalize($calculation->refundAmount);
        $maximum = WalletMoney::normalize($calculation->maximumRefundable);

        if ($refundAmount === null || $maximum === null) {
            return false;
        }

        return bccomp($refundAmount, $maximum, WalletMoney::SCALE) === 0;
    }

    private function isWithinAmountCap(RefundCalculationResult $calculation): bool
    {
        $refundAmount = WalletMoney::normalize($calculation->refundAmount);
        $cap = $this->maxAmount();

        if ($refundAmount === null) {
            return false;
        }

        return bccomp($refundAmount, $cap, WalletMoney::SCALE) <= 0;
    }

    private function centralWalletTargetResolvable(Order $order): bool
    {
        $customerId = trim((string) ($order->customer_id ?? ''));
        if ($customerId === '' || ! Str::isUuid($customerId)) {
            return false;
        }

        return $this->customerBinder->resolveCustomerWallet($customerId) !== null;
    }
}
