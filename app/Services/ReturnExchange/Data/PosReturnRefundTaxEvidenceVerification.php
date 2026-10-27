<?php

namespace App\Services\ReturnExchange\Data;

use App\Enums\PosReturnRefundTaxEvidenceType;
use App\Models\StatutoryInvoice;

final class PosReturnRefundTaxEvidenceVerification
{
    private function __construct(
        public readonly bool $verified,
        public readonly ?StatutoryInvoice $invoice,
        public readonly ?PosReturnRefundTaxEvidenceType $evidenceType,
        public readonly ?string $blockerMessage,
    ) {}

    public static function verified(
        StatutoryInvoice $invoice,
        PosReturnRefundTaxEvidenceType $evidenceType,
    ): self {
        return new self(
            verified: true,
            invoice: $invoice,
            evidenceType: $evidenceType,
            blockerMessage: null,
        );
    }

    public static function blocked(string $message): self
    {
        return new self(
            verified: false,
            invoice: null,
            evidenceType: null,
            blockerMessage: $message,
        );
    }
}
