<?php

namespace App\Services\Refunds\Data;

use App\Models\StatutoryInvoice;

final class RefundStatutoryAdjustmentEligibilityResult
{
    public function __construct(
        public readonly bool $eligible,
        public readonly ?string $skipReason = null,
        public readonly ?StatutoryInvoice $invoice = null,
    ) {}

    public static function eligible(StatutoryInvoice $invoice): self
    {
        return new self(eligible: true, invoice: $invoice);
    }

    public static function skip(string $reason, ?StatutoryInvoice $invoice = null): self
    {
        return new self(eligible: false, skipReason: $reason, invoice: $invoice);
    }
}
