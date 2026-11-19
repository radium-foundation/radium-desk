<?php

namespace App\Support\StatutoryInvoice;

use App\Enums\BuyerGstVerificationStatus;
use App\Models\CommerceOrderBuyerGstVerification;

/**
 * Which provider outcomes may be manually converted to B2C after Finance/CA authorization.
 *
 * Provider outages and ambiguous responses must remain in review — never auto-B2C.
 */
final class BuyerGstB2cConversionPolicy
{
    /**
     * @var list<string>
     */
    private const ELIGIBLE_REVIEW_REASONS = [
        'inactive_registration',
        'invalid_gstin_format',
        'invalid_gstin_response',
    ];

    /**
     * @var list<string>
     */
    private const HOLD_REVIEW_REASONS = [
        'provider_unavailable',
        'provider_timeout',
        'provider_rate_limited',
        'provider_malformed_response',
        'provider_error',
        'verification_not_run',
        'verification_provider_unconfigured',
        'incomplete_registration_snapshot',
        'registration_address_incomplete',
        'gstin_state_mismatch',
    ];

    public static function fallbackPolicyEnabled(): bool
    {
        return (bool) config('statutory_invoices.buyer_gstin_verification.b2c_fallback.enabled', false);
    }

    public static function isEligibleForManualConversion(?CommerceOrderBuyerGstVerification $record): bool
    {
        if ($record === null || ! self::fallbackPolicyEnabled()) {
            return false;
        }

        if (! in_array($record->verification_status, [
            BuyerGstVerificationStatus::ReviewRequired,
            BuyerGstVerificationStatus::Failed,
        ], true)) {
            return false;
        }

        $reason = (string) ($record->review_reason ?? '');

        if (in_array($reason, self::HOLD_REVIEW_REASONS, true)) {
            return false;
        }

        return in_array($reason, self::ELIGIBLE_REVIEW_REASONS, true);
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
            : 'unverified';

        return match ($reviewReason) {
            'inactive_registration' => "B2C issued: buyer GSTIN {$maskedGstin} registration status {$statusLabel} (GST verification).",
            'invalid_gstin_format' => "B2C issued: buyer GSTIN {$maskedGstin} could not be verified (format).",
            'invalid_gstin_response' => "B2C issued: buyer GSTIN {$maskedGstin} failed provider validation.",
            default => "B2C issued: buyer GSTIN {$maskedGstin} requires GST verification review ({$reviewReason}).",
        };
    }
}
