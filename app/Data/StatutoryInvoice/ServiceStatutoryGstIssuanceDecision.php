<?php

namespace App\Data\StatutoryInvoice;

final class ServiceStatutoryGstIssuanceDecision
{
    public function __construct(
        public readonly bool $issueAsB2b,
        public readonly ?string $buyerGstinForMint,
        public readonly ?string $b2cDowngradeCode,
        public readonly bool $requiresGstReview = false,
        public readonly ?string $gstReviewReason = null,
        public readonly ?string $statutoryIssuanceNote = null,
    ) {}

    public function c360Note(): ?string
    {
        if ($this->statutoryIssuanceNote !== null && trim($this->statutoryIssuanceNote) !== '') {
            return $this->statutoryIssuanceNote;
        }

        return match ($this->b2cDowngradeCode) {
            'gstin_incomplete' => 'GSTIN incomplete — B2C invoice issued.',
            'gstin_invalid' => 'GSTIN invalid — B2C invoice issued.',
            'gstin_state_mismatch' => 'GSTIN/state mismatch — B2C invoice issued.',
            default => null,
        };
    }

    public function requiresB2cDowngrade(): bool
    {
        return $this->b2cDowngradeCode !== null;
    }

    public function reviewNote(): ?string
    {
        if (! $this->requiresGstReview) {
            return null;
        }

        return match ($this->gstReviewReason) {
            'gstin_state_mismatch' => 'GSTIN and billing state conflict — GST review required.',
            'gstin_invalid' => 'Buyer GSTIN failed verification — GST review required.',
            'inactive_registration' => 'Buyer GSTIN registration is not active — GST review required.',
            'verification_pending' => 'Buyer GSTIN verification is pending — GST review required.',
            default => 'GST review required before B2B invoice issuance.',
        };
    }
}
