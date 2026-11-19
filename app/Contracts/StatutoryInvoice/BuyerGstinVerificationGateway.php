<?php

namespace App\Contracts\StatutoryInvoice;

use App\Services\StatutoryInvoice\Data\BuyerGstinVerificationResult;

interface BuyerGstinVerificationGateway
{
    public function provider(): string;

    public function isConfigured(): bool;

    public function verify(string $gstin): BuyerGstinVerificationResult;
}
