<?php

namespace App\Support\StatutoryInvoice;

use App\Enums\BuyerGstVerificationStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstVerification;
use App\Services\StatutoryInvoice\BuyerGstin;
use App\Support\Finance\GstStateCodes;

/**
 * When buyer GSTIN cannot support B2B billing, automatic B2C conversion applies
 * while verification and provider evidence remain in the audit trail.
 *
 * Confirmed ineligibility auto-converts whenever buyer GST verification is enabled (P-20-27).
 * Stage B fallback flag gates exceptional manual conversion for provider-uncertainty outcomes only.
 */
final class BuyerGstB2cConversionPolicy
{
    /**
     * Provider or verification outcomes that confirm B2B cannot be supported (permanent automatic B2C).
     *
     * @var list<string>
     */
    private const CONFIRMED_B2B_INELIGIBLE_REVIEW_REASONS = [
        'inactive_registration',
        'invalid_gstin_format',
        'invalid_gstin_response',
        'incomplete_registration_snapshot',
        'registration_address_incomplete',
        'gstin_state_mismatch',
        'gstin_invalid',
        'gstin_incomplete',
    ];

    /**
     * Operational / technical outcomes — hold or exceptional manual B2C only; never automatic legal ineligibility.
     *
     * @var list<string>
     */
    private const PROVIDER_UNCERTAINTY_REVIEW_REASONS = [
        'provider_timeout',
        'provider_unavailable',
        'provider_rate_limited',
        'provider_malformed_response',
        'provider_error',
        'verification_not_run',
        'verification_provider_unconfigured',
    ];

    public static function fallbackPolicyEnabled(): bool
    {
        return (bool) config('statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled', false);
    }

    public static function verificationPolicyEnabled(): bool
    {
        return (bool) config('statutory_invoices.buyer_gstin_verification.enabled', false);
    }

    public static function automaticConversionActive(): bool
    {
        return self::verificationPolicyEnabled() || self::fallbackPolicyEnabled();
    }

    public static function isEligibleForManualConversion(
        ?CommerceOrderBuyerGstVerification $record,
        ?CommerceOrder $order = null,
    ): bool {
        if (! self::fallbackPolicyEnabled()) {
            return false;
        }

        if (self::isEligibleForAutomaticConversion($record, $order)) {
            return true;
        }

        if ($record === null) {
            return false;
        }

        if (self::qualifiesForB2bBilling($record)) {
            return false;
        }

        return in_array($record->verification_status, [
            BuyerGstVerificationStatus::Pending,
            BuyerGstVerificationStatus::Failed,
            BuyerGstVerificationStatus::ReviewRequired,
        ], true);
    }

    public static function isEligibleForAutomaticConversion(
        ?CommerceOrderBuyerGstVerification $record,
        ?CommerceOrder $order = null,
    ): bool {
        if (! self::automaticConversionActive()) {
            return false;
        }

        if ($record === null) {
            return $order !== null && self::localGstinBlocksB2b($order);
        }

        if (self::qualifiesForB2bBilling($record)) {
            return false;
        }

        if ($record->verification_status === BuyerGstVerificationStatus::Pending) {
            return false;
        }

        return self::isConfirmedB2bIneligible($record);
    }

    public static function isConfirmedB2bIneligible(CommerceOrderBuyerGstVerification $record): bool
    {
        if (self::qualifiesForB2bBilling($record)) {
            return false;
        }

        $reason = trim((string) ($record->review_reason ?? ''));

        return in_array($reason, self::CONFIRMED_B2B_INELIGIBLE_REVIEW_REASONS, true);
    }

    public static function isProviderUncertaintyReviewReason(?string $reviewReason): bool
    {
        $reason = trim((string) ($reviewReason ?? ''));

        return in_array($reason, self::PROVIDER_UNCERTAINTY_REVIEW_REASONS, true);
    }

    public static function qualifiesForB2bBilling(CommerceOrderBuyerGstVerification $record): bool
    {
        if ($record->verification_status !== BuyerGstVerificationStatus::Verified) {
            return false;
        }

        $registration = strtolower(trim((string) ($record->registration_status ?? '')));
        if (! in_array($registration, ['active', 'provisional'], true)) {
            return false;
        }

        if ($record->bill_to_address_structured === null) {
            return false;
        }

        $gstin = BuyerGstin::normalize($record->submitted_gstin);
        if ($gstin === null) {
            return false;
        }

        $billTo = StatutoryBillingStructured::fromStored($record->bill_to_address_structured);
        if ($billTo === null) {
            return false;
        }

        $billingStateCode = GstStateCodes::codeForName((string) ($billTo['state'] ?? ''));
        $gstinStateCode = BuyerGstin::stateCode($gstin);
        if ($billingStateCode !== null
            && $gstinStateCode !== null
            && $billingStateCode !== $gstinStateCode) {
            return false;
        }

        return true;
    }

    public static function localGstinBlocksB2b(CommerceOrder $order): bool
    {
        if (! (bool) config('statutory_invoices.buyer_gstin_verification.enabled', false)) {
            return false;
        }

        $submitted = trim((string) ($order->buyer_gstin ?? ''));
        if ($submitted === '') {
            return false;
        }

        $normalized = BuyerGstin::normalize($submitted);

        return $normalized === null
            || strlen($normalized) < 15
            || ! BuyerGstin::isValid($normalized);
    }

    /**
     * @return array{
     *     submitted_gstin: string,
     *     verification_status: string,
     *     registration_status: ?string,
     *     review_reason: string,
     *     commerce_order_buyer_gst_verification_id: ?int,
     *     provider_payload_hash: ?string,
     * }|null
     */
    public static function automaticConversionSnapshot(
        CommerceOrder $order,
        ?CommerceOrderBuyerGstVerification $record,
    ): ?array {
        if ($record !== null) {
            return [
                'submitted_gstin' => $record->submitted_gstin,
                'verification_status' => $record->verification_status->value,
                'registration_status' => $record->registration_status,
                'review_reason' => (string) ($record->review_reason ?? 'verification_review'),
                'commerce_order_buyer_gst_verification_id' => $record->id,
                'provider_payload_hash' => $record->provider_payload_hash,
            ];
        }

        if (! self::localGstinBlocksB2b($order)) {
            return null;
        }

        $submitted = trim((string) ($order->buyer_gstin ?? ''));
        $normalized = BuyerGstin::normalize($submitted);
        $gstinForAudit = $normalized ?? strtoupper(substr($submitted, 0, 15));
        $reason = $normalized === null || strlen((string) $normalized) < 15
            ? 'gstin_incomplete'
            : 'gstin_invalid';

        return [
            'submitted_gstin' => $gstinForAudit,
            'verification_status' => BuyerGstVerificationStatus::Failed->value,
            'registration_status' => null,
            'review_reason' => $reason,
            'commerce_order_buyer_gst_verification_id' => null,
            'provider_payload_hash' => null,
        ];
    }

    public static function maskedGstinForRemark(string $gstin): string
    {
        $normalized = strtoupper(trim($gstin));
        if (strlen($normalized) < 4) {
            return 'GSTIN';
        }

        return substr($normalized, 0, 2).'******'.substr($normalized, -3);
    }

    public static function defaultInvoiceRemark(
        string $maskedGstin,
        ?string $registrationStatus,
        string $reviewReason,
    ): string {
        $statusLabel = $registrationStatus !== null && $registrationStatus !== ''
            ? $registrationStatus
            : null;

        return match ($reviewReason) {
            'inactive_registration' => "B2C issued: buyer GSTIN {$maskedGstin} registration status {$statusLabel} (GST verification).",
            'invalid_gstin_format' => "B2C issued: buyer GSTIN {$maskedGstin} could not be verified (format).",
            'invalid_gstin_response' => "B2C issued: buyer GSTIN {$maskedGstin} failed provider validation.",
            'incomplete_registration_snapshot' => "B2C issued: buyer GSTIN {$maskedGstin}; GST registration snapshot incomplete (verification).",
            'registration_address_incomplete' => "B2C issued: buyer GSTIN {$maskedGstin}; GST registration address incomplete (verification).",
            'gstin_state_mismatch' => "B2C issued: buyer GSTIN {$maskedGstin}; GSTIN conflicts with verified billing state (verification).",
            'gstin_invalid' => "B2C issued: buyer GSTIN {$maskedGstin} invalid (format check).",
            'gstin_incomplete' => "B2C issued: buyer GSTIN {$maskedGstin} incomplete (format check).",
            'provider_timeout' => "B2C issued: buyer GSTIN {$maskedGstin}; GST provider verification did not complete (timeout).",
            'provider_unavailable' => "B2C issued: buyer GSTIN {$maskedGstin}; GST provider verification unavailable.",
            'provider_rate_limited' => "B2C issued: buyer GSTIN {$maskedGstin}; GST provider rate limited.",
            'provider_malformed_response' => "B2C issued: buyer GSTIN {$maskedGstin}; GST provider returned an unusable response.",
            'provider_error' => "B2C issued: buyer GSTIN {$maskedGstin}; GST provider verification error.",
            'verification_not_run' => "B2C issued: buyer GSTIN {$maskedGstin}; GST verification did not complete.",
            'verification_provider_unconfigured' => "B2C issued: buyer GSTIN {$maskedGstin}; GST verification provider not configured.",
            default => "B2C issued: buyer GSTIN {$maskedGstin}; GST verification outcome {$reviewReason}.",
        };
    }
}
