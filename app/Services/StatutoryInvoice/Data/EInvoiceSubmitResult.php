<?php

namespace App\Services\StatutoryInvoice\Data;

final class EInvoiceSubmitResult
{
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $irn = null,
        public readonly ?string $ackNo = null,
        public readonly mixed $payload = null,
    ) {}
}
