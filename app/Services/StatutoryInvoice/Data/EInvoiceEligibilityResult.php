<?php

namespace App\Services\StatutoryInvoice\Data;

final class EInvoiceEligibilityResult
{
    public function __construct(
        public readonly bool $eligible,
        public readonly string $reason,
    ) {}
}
