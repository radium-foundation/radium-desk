<?php

namespace App\Services\StatutoryInvoice;

use App\Data\StatutoryInvoice\ServiceStatutoryGstIssuanceDecision;
use App\Models\CommerceOrder;
use App\Support\Finance\GstStateCodes;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Classifies paid service commerce orders for B2B vs B2C statutory issuance.
 *
 * Invalid, incomplete, or state-inconsistent submitted GST data issues B2C
 * immediately. Native B2C orders (no GSTIN submitted) are unchanged.
 */
final class ServiceStatutoryGstB2bClassification
{
    public function resolveForServiceOrder(CommerceOrder $order): ServiceStatutoryGstIssuanceDecision
    {
        $submitted = trim((string) ($order->buyer_gstin ?? ''));
        if ($submitted === '') {
            return new ServiceStatutoryGstIssuanceDecision(
                issueAsB2b: false,
                buyerGstinForMint: null,
                b2cDowngradeCode: null,
            );
        }

        $normalized = BuyerGstin::normalize($submitted);
        if ($normalized === null || strlen($normalized) < 15) {
            return $this->b2cDowngrade('gstin_incomplete');
        }

        if (! BuyerGstin::isValid($normalized)) {
            return $this->b2cDowngrade('gstin_invalid');
        }

        $gstinStateCode = BuyerGstin::stateCode($normalized);
        if ($gstinStateCode === null || ! GstStateCodes::isKnownCode($gstinStateCode)) {
            return $this->b2cDowngrade('gstin_invalid');
        }

        $structured = StatutoryBillingStructured::fromStored($order->billing_address_structured);
        $billingStateCode = $structured !== null
            ? GstStateCodes::codeForName((string) ($structured['state'] ?? ''))
            : GstStateCodes::codeForName($order->billing_state);
        $placeOfSupplyCode = GstStateCodes::codeForName($order->place_of_supply_state);

        if ($billingStateCode !== null && $gstinStateCode !== $billingStateCode) {
            return $this->b2cDowngrade('gstin_state_mismatch');
        }

        if ($placeOfSupplyCode !== null && $gstinStateCode !== $placeOfSupplyCode) {
            return $this->b2cDowngrade('gstin_state_mismatch');
        }

        return new ServiceStatutoryGstIssuanceDecision(
            issueAsB2b: true,
            buyerGstinForMint: $normalized,
            b2cDowngradeCode: null,
        );
    }

    public function c360NoteForIssuedInvoice(CommerceOrder $order, ?string $invoiceBuyerGstin): ?string
    {
        if ($invoiceBuyerGstin !== null && trim($invoiceBuyerGstin) !== '') {
            return null;
        }

        $decision = $this->resolveForServiceOrder($order);

        return $decision->c360Note();
    }

    private function b2cDowngrade(string $code): ServiceStatutoryGstIssuanceDecision
    {
        return new ServiceStatutoryGstIssuanceDecision(
            issueAsB2b: false,
            buyerGstinForMint: null,
            b2cDowngradeCode: $code,
        );
    }
}
