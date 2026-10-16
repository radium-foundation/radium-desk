<?php

namespace App\Services\WalletRefundOpmConversion;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\WalletRefundOpmConversion;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class WalletRefundOpmConversionEligibilityService
{
    public function __construct(
        private readonly WalletRefundOpmSiteAdapterResolver $adapterResolver,
        private readonly WalletRefundCreditSourceResolver $creditSources,
    ) {}

    public function assertCanSubmit(RefundRequest $refund, ?int $excludeConversionId = null): void
    {
        $this->assertParentRefund($refund);

        if (Schema::hasTable('wallet_refund_opm_conversions')) {
            $existingQuery = WalletRefundOpmConversion::query()
                ->where('refund_request_id', $refund->id)
                ->whereNotIn('status', [
                    WalletRefundOpmConversionStatus::Rejected->value,
                    WalletRefundOpmConversionStatus::Cancelled->value,
                ]);

            if ($excludeConversionId !== null) {
                $existingQuery->where('id', '!=', $excludeConversionId);
            }

            if ($existingQuery->exists()) {
                throw ValidationException::withMessages([
                    'conversion' => 'An active wallet-to-OPM conversion already exists for this refund.',
                ]);
            }
        }

        $refund->loadMissing('order');
        $order = $refund->order;
        if (! $order instanceof Order) {
            throw ValidationException::withMessages([
                'conversion' => 'Refund order is required.',
            ]);
        }

        $adapter = $this->adapterResolver->forOrderId($order->order_id);
        if (! $adapter->isEnabled()) {
            throw ValidationException::withMessages([
                'conversion' => 'Wallet-to-OPM conversion is not enabled for this site.',
            ]);
        }

        $source = $this->creditSources->resolve($refund);
        if (! $adapter->supportsCreditSource($source)) {
            throw ValidationException::withMessages([
                'conversion' => 'Wallet-to-OPM conversion is not supported for '
                    .$source->label()
                    .' credits on '
                    .$adapter->siteCode()
                    .'.',
            ]);
        }

        if (! $this->hasOriginalPaymentEvidence($order)) {
            throw ValidationException::withMessages([
                'conversion' => 'Original payment evidence is required before wallet-to-OPM conversion.',
            ]);
        }
    }

    public function fullConversionAmount(RefundRequest $refund): string
    {
        $amount = round((float) ($refund->refund_amount ?? $refund->amount ?? 0), 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'conversion' => 'Refund amount must be positive for a full conversion.',
            ]);
        }

        return number_format($amount, 2, '.', '');
    }

    public function siteCodeForRefund(RefundRequest $refund): string
    {
        $refund->loadMissing('order');

        return $this->adapterResolver->forOrderId($refund->order?->order_id)->siteCode();
    }

    private function assertParentRefund(RefundRequest $refund): void
    {
        if ($refund->approved_refund_method !== ApprovedRefundMethod::Wallet) {
            throw ValidationException::withMessages([
                'conversion' => 'Only completed wallet refunds can be converted to the original payment method.',
            ]);
        }

        if (! ($refund->status?->isTerminalSuccess() ?? false)) {
            throw ValidationException::withMessages([
                'conversion' => 'Wallet refund must be completed before conversion.',
            ]);
        }

        if ($refund->status === RefundStatus::Revoked) {
            throw ValidationException::withMessages([
                'conversion' => 'Revoked refunds cannot be converted.',
            ]);
        }
    }

    private function hasOriginalPaymentEvidence(Order $order): bool
    {
        if ((float) ($order->payment_amount ?? 0) <= 0) {
            return false;
        }

        foreach ([
            $order->cashfree_payment_id,
            $order->gateway_payment_id,
            $order->transaction_id,
            $order->bank_reference,
        ] as $reference) {
            if (is_string($reference) && trim($reference) !== '') {
                return true;
            }
        }

        return false;
    }
}
