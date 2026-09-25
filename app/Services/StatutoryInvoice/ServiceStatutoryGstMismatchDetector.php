<?php

namespace App\Services\StatutoryInvoice;

use App\Models\CommerceOrder;
use App\Support\Finance\GstStateCodes;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

final class ServiceStatutoryGstMismatchDetector
{
    public const REASON_BUYER_PIN_GSTIN_STATE_MISMATCH = 'buyer_pin_gstin_state_mismatch';

    public function detectForCommerceOrder(CommerceOrder $order): ?string
    {
        $buyerGstin = BuyerGstin::normalize($order->buyer_gstin);
        if ($buyerGstin === null || ! BuyerGstin::isValid($buyerGstin)) {
            return null;
        }

        $structured = StatutoryBillingStructured::fromStored($order->billing_address_structured);
        $billingStateCode = $structured !== null
            ? GstStateCodes::codeForName((string) ($structured['state'] ?? ''))
            : GstStateCodes::codeForName($order->billing_state);
        $gstinStateCode = BuyerGstin::stateCode($buyerGstin);

        if ($gstinStateCode !== null
            && $billingStateCode !== null
            && $gstinStateCode !== $billingStateCode) {
            return self::REASON_BUYER_PIN_GSTIN_STATE_MISMATCH;
        }

        return null;
    }

    public function messageContainsGstMismatch(string $message): bool
    {
        return str_contains($message, self::REASON_BUYER_PIN_GSTIN_STATE_MISMATCH);
    }
}
