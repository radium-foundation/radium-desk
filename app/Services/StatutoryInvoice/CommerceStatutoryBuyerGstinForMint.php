<?php

namespace App\Services\StatutoryInvoice;

use App\Models\CommerceOrder;

/**
 * Resolves buyer GSTIN on the statutory mint request after B2C conversion policy.
 */
final class CommerceStatutoryBuyerGstinForMint
{
    public function __construct(
        private readonly CommerceOrderBuyerGstB2cConversionService $b2cConversions,
    ) {}

    public function resolve(CommerceOrder $order): ?string
    {
        if ($this->b2cConversions->approvedConversion($order) !== null) {
            return null;
        }

        return BuyerGstin::normalize($order->buyer_gstin);
    }
}