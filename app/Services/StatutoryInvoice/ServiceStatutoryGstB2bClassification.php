<?php

namespace App\Services\StatutoryInvoice;

use App\Data\StatutoryInvoice\ServiceStatutoryGstIssuanceDecision;
use App\Enums\BuyerGstVerificationStatus;
use App\Models\CommerceOrder;
use App\Support\Finance\GstStateCodes;
use App\Support\StatutoryInvoice\StatutoryBillingStructured;

/**
 * Classifies paid service commerce orders for B2B vs B2C statutory issuance.
 *
 * When buyer GSTIN verification is enabled, state mismatches route to GST review
 * instead of silent B2C downgrade.
 */
final class ServiceStatutoryGstB2bClassification
{
    public function __construct(
        private readonly CommerceOrderBuyerGstVerificationService $buyerGstVerification,
    ) {}

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
            return $this->legacyOrReview($order, $this->b2cDowngrade('gstin_incomplete'));
        }

        if (! BuyerGstin::isValid($normalized)) {
            return $this->legacyOrReview($order, $this->b2cDowngrade('gstin_invalid'));
        }

        $gstinStateCode = BuyerGstin::stateCode($normalized);
        if ($gstinStateCode === null || ! GstStateCodes::isKnownCode($gstinStateCode)) {
            return $this->legacyOrReview($order, $this->b2cDowngrade('gstin_invalid'));
        }

        $structured = StatutoryBillingStructured::fromStored($order->billing_address_structured);
        $billingStateCode = $structured !== null
            ? GstStateCodes::codeForName((string) ($structured['state'] ?? ''))
            : GstStateCodes::codeForName($order->billing_state);
        $placeOfSupplyCode = GstStateCodes::codeForName($order->place_of_supply_state);

        $stateMismatch = ($billingStateCode !== null && $gstinStateCode !== $billingStateCode)
            || ($placeOfSupplyCode !== null && $gstinStateCode !== $placeOfSupplyCode);

        if ($stateMismatch && $this->buyerGstVerification->isEnabled()) {
            $record = $this->buyerGstVerification->recordForOrder($order);
            if ($record !== null
                && $record->verification_status === BuyerGstVerificationStatus::Verified
                && $record->bill_to_address_structured !== null) {
                return new ServiceStatutoryGstIssuanceDecision(
                    issueAsB2b: true,
                    buyerGstinForMint: $normalized,
                    b2cDowngradeCode: null,
                );
            }

            if ($record === null
                || in_array($record->verification_status, [
                    BuyerGstVerificationStatus::Pending,
                    BuyerGstVerificationStatus::ReviewRequired,
                ], true)) {
                return new ServiceStatutoryGstIssuanceDecision(
                    issueAsB2b: false,
                    buyerGstinForMint: null,
                    b2cDowngradeCode: null,
                    requiresGstReview: true,
                    gstReviewReason: $record?->review_reason ?? 'gstin_state_mismatch',
                );
            }

            return new ServiceStatutoryGstIssuanceDecision(
                issueAsB2b: false,
                buyerGstinForMint: null,
                b2cDowngradeCode: null,
                requiresGstReview: true,
                gstReviewReason: $record->review_reason ?? 'gstin_state_mismatch',
            );
        }

        if ($stateMismatch) {
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

        if ($decision->reviewNote() !== null) {
            return $decision->reviewNote();
        }

        return $decision->c360Note();
    }

    private function legacyOrReview(
        CommerceOrder $order,
        ServiceStatutoryGstIssuanceDecision $legacyDowngrade,
    ): ServiceStatutoryGstIssuanceDecision {
        if (! $this->buyerGstVerification->isEnabled()) {
            return $legacyDowngrade;
        }

        return new ServiceStatutoryGstIssuanceDecision(
            issueAsB2b: false,
            buyerGstinForMint: null,
            b2cDowngradeCode: null,
            requiresGstReview: true,
            gstReviewReason: $legacyDowngrade->b2cDowngradeCode ?? 'gstin_invalid',
        );
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
