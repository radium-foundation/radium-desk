<?php

namespace App\Services\ReturnExchange;

use App\Models\ReturnExchangeCase;
use App\Services\ReturnExchange\Data\PosReturnRefundTaxEvidenceVerification;

/** @deprecated Use {@see ReturnExchangeCaseRecordedTaxOutcomeVerifier} */
final class PosReturnRefundTaxEvidenceVerifier
{
    public function __construct(
        private readonly ReturnExchangeCaseRecordedTaxOutcomeVerifier $verifier,
    ) {}

    public function verify(ReturnExchangeCase $case): PosReturnRefundTaxEvidenceVerification
    {
        return $this->verifier->verify($case);
    }
}
