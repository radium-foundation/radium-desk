<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Enums\BuyerGstVerificationStatus;

final class BuyerGstinVerificationResult
{
    /**
     * @param  array<string, mixed>|null  $verifiedAddressStructured
     */
    public function __construct(
        public readonly BuyerGstVerificationStatus $status,
        public readonly ?string $gstin = null,
        public readonly ?string $legalName = null,
        public readonly ?string $registrationStatus = null,
        public readonly ?array $verifiedAddressStructured = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $reviewReason = null,
        public readonly ?string $providerPayloadHash = null,
    ) {}

    public function isActiveRegistration(): bool
    {
        if ($this->registrationStatus === null) {
            return false;
        }

        $normalized = strtolower(trim($this->registrationStatus));

        return in_array($normalized, ['active', 'provisional'], true);
    }
}
