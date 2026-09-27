<?php

namespace App\Services\Refunds\Data;

use App\Models\StatutoryInvoice;

final class OrderStatutoryInvoiceResolution
{
    public function __construct(
        public readonly ?StatutoryInvoice $invoice,
        public readonly ?string $skipReason = null,
    ) {}

    public static function found(StatutoryInvoice $invoice): self
    {
        return new self(invoice: $invoice);
    }

    public static function skipped(string $reason): self
    {
        return new self(invoice: null, skipReason: $reason);
    }
}
