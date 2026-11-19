<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\BuyerGstVerificationStatus;
use App\Models\CommerceOrder;
use App\Support\StatutoryInvoice\StatutoryBillingAddressComposer;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Resolves invoice Bill To snapshots for commerce minting without touching Ship To.
 */
final class CommerceOrderBillToResolver
{
    public function __construct(
        private readonly CommerceOrderBuyerGstVerificationService $verifications,
        private readonly StatutoryBillingAddressComposer $billingAddressComposer,
    ) {}

    /**
     * @return array{flat: ?string, structured: ?array<string, mixed>}
     */
    public function resolveForMint(CommerceOrder $order): array
    {
        $structured = StatutoryBillingStructured::fromStored($order->billing_address_structured);
        $flat = $order->billing_address;

        if (! $this->verifications->requiresProviderVerification($order)) {
            return $this->billingAddressComposer->compose($flat, $structured);
        }

        $record = $this->verifications->recordForOrder($order);
        if ($record === null
            || $record->verification_status !== BuyerGstVerificationStatus::Verified
            || $record->bill_to_address_structured === null) {
            return $this->billingAddressComposer->compose($flat, $structured);
        }

        $billTo = StatutoryBillingStructured::fromStored($record->bill_to_address_structured);

        return $this->billingAddressComposer->compose($flat, $billTo ?? $structured);
    }
}
