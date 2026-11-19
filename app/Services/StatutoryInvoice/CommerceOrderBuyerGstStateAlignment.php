<?php

namespace App\Services\StatutoryInvoice;

use App\Models\CommerceOrder;
use App\Support\Finance\GstStateCodes;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Detects when submitted checkout billing state conflicts with GSTIN registration state.
 */
final class CommerceOrderBuyerGstStateAlignment
{
    public static function submittedBillingStateCode(CommerceOrder $order): ?string
    {
        $structured = StatutoryBillingStructured::fromStored($order->billing_address_structured);
        if ($structured !== null) {
            $name = StatutoryBillingStructured::nullable($structured['state'] ?? null);
            if ($name !== null) {
                return GstStateCodes::codeForName($name);
            }
        }

        $billingState = is_string($order->billing_state) ? trim($order->billing_state) : '';

        return $billingState !== '' ? GstStateCodes::codeForName($billingState) : null;
    }

    public static function gstinStateCode(CommerceOrder $order): ?string
    {
        $gstin = BuyerGstin::normalize($order->buyer_gstin);

        return $gstin !== null ? BuyerGstin::stateCode($gstin) : null;
    }

    public static function billingStateConflictsWithGstin(CommerceOrder $order): bool
    {
        $gstinState = self::gstinStateCode($order);
        $billingState = self::submittedBillingStateCode($order);

        if ($gstinState === null || $billingState === null) {
            return false;
        }

        return $gstinState !== $billingState;
    }

    public static function requiresProviderVerification(CommerceOrder $order, bool $verificationEnabled): bool
    {
        if (! $verificationEnabled) {
            return false;
        }

        $gstin = BuyerGstin::normalize($order->buyer_gstin);
        if ($gstin === null || ! BuyerGstin::isValid($gstin)) {
            return false;
        }

        return self::billingStateConflictsWithGstin($order);
    }
}
