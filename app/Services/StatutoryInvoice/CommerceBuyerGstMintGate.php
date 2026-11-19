<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\BuyerGstVerificationStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Support\Finance\GstStateCodes;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Gates B2B commerce mint when buyer GSTIN verification policy is active.
 */
final class CommerceBuyerGstMintGate
{
    public function __construct(
        private readonly CommerceOrderBuyerGstVerificationService $verifications,
    ) {}

    /**
     * @return list<string>
     */
    public function blockingReasonsForOrder(CommerceOrder $order): array
    {
        if (! $this->verifications->isEnabled()) {
            return [];
        }

        $gstin = BuyerGstin::normalize($order->buyer_gstin);
        if ($gstin === null) {
            return [];
        }

        $record = $this->verifications->recordForOrder($order);
        if ($record === null) {
            return ['Buyer GSTIN verification is pending.'];
        }

        return match ($record->verification_status) {
            BuyerGstVerificationStatus::Verified => $this->verifiedBlockers($record, $gstin),
            BuyerGstVerificationStatus::Pending => ['Buyer GSTIN verification is pending.'],
            BuyerGstVerificationStatus::Failed,
            BuyerGstVerificationStatus::ReviewRequired => ['Buyer GSTIN requires GST review.'],
        };
    }

    /**
     * @return list<string>
     */
    public function blockingReasonsForMintRequest(StatutoryInvoiceMintRequest $request): array
    {
        if (! $this->verifications->isEnabled()
            || $request->sourceType !== StatutoryInvoiceSourceType::CommerceOrder) {
            return [];
        }

        $order = CommerceOrder::query()
            ->where('channel', $request->channel->value)
            ->where('source_id', $request->sourceId)
            ->first();
        if ($order === null) {
            return [];
        }

        return $this->blockingReasonsForOrder($order);
    }

    /**
     * @return list<string>
     */
    private function verifiedBlockers(
        CommerceOrderBuyerGstVerification $record,
        string $gstin,
    ): array {
        $billTo = StatutoryBillingStructured::fromStored($record->bill_to_address_structured);
        if ($billTo === null) {
            return ['Verified Bill To address is unavailable.'];
        }

        $billingStateCode = GstStateCodes::codeForName((string) ($billTo['state'] ?? ''));
        $gstinStateCode = BuyerGstin::stateCode($gstin);
        if ($billingStateCode !== null
            && $gstinStateCode !== null
            && $billingStateCode !== $gstinStateCode) {
            return ['buyer_pin_gstin_state_mismatch'];
        }

        return [];
    }
}
