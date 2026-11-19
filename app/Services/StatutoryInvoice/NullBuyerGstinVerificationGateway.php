<?php

namespace App\Services\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\BuyerGstinVerificationGateway;
use App\Enums\BuyerGstVerificationStatus;
use App\Services\StatutoryInvoice\Data\BuyerGstinVerificationResult;

final class NullBuyerGstinVerificationGateway implements BuyerGstinVerificationGateway
{
    public function provider(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function verify(string $gstin): BuyerGstinVerificationResult
    {
        return new BuyerGstinVerificationResult(
            status: BuyerGstVerificationStatus::ReviewRequired,
            gstin: BuyerGstin::normalize($gstin),
            reviewReason: 'verification_provider_unconfigured',
        );
    }
}
