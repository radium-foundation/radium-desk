<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\BuyerGstVerificationStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Support\Finance\GstStateCodes;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;
use Illuminate\Validation\ValidationException;

/**
 * Gates B2B commerce mint when buyer GSTIN verification policy is active.
 */
final class CommerceBuyerGstMintGate
{
    public function __construct(
        private readonly CommerceOrderBuyerGstVerificationService $verifications,
        private readonly CommerceOrderBuyerGstB2cConversionService $b2cConversions,
    ) {}

    /**
     * @return list<string>
     */
    public function blockingReasonsForOrder(CommerceOrder $order): array
    {
        $this->b2cConversions->ensureAutomaticConversion($order);

        if ($this->b2cConversions->approvedConversion($order) !== null) {
            return [];
        }

        if (! $this->verifications->requiresProviderVerification($order)) {
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

        if ($this->b2cConversions->approvedConversion($order) !== null) {
            $requestedGstin = BuyerGstin::normalize($request->buyerGstin);
            if ($requestedGstin !== null) {
                return ['B2B mint is blocked after authorized B2C conversion.'];
            }
        }

        return $this->blockingReasonsForOrder($order);
    }

    /**
     * Authoritative commerce-order gate for statutory mint (hardware and non-hardware).
     */
    public function assertNotBlocking(CommerceOrder $order): void
    {
        $reasons = $this->blockingReasonsForOrder($order);
        if ($reasons === []) {
            return;
        }

        throw ValidationException::withMessages([
            'eligibility' => array_values(array_unique($reasons)),
        ]);
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
