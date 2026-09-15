<?php

namespace App\Services\StatutoryInvoice\Data;

final class EInvoiceIssuancePolicyResult
{
    public function __construct(
        public readonly bool $permitted,
        public readonly string $reason,
    ) {}
}
