<?php

namespace App\Services\StatutoryInvoice\Data;

/**
 * Company-level legal name plus issuer-specific GST registration.
 */
final class StatutorySellerProfile
{
    public function __construct(
        public readonly string $location,
        public readonly string $legalName,
        public readonly string $gstin,
        public readonly string $address,
        public readonly string $state,
    ) {}
}
