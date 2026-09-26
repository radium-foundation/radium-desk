<?php

namespace App\Data\StatutoryInvoice;

final class ServiceStatutoryGstIssuanceDecision
{
    public function __construct(
        public readonly bool $issueAsB2b,
        public readonly ?string $buyerGstinForMint,
        public readonly ?string $b2cDowngradeCode,
    ) {}

    public function c360Note(): ?string
    {
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
}
