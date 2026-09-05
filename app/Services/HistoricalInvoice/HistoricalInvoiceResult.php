<?php

namespace App\Services\HistoricalInvoice;

final class HistoricalInvoiceResult
{
    /**
     * @param  array<string, mixed>|null  $reprint
     */
    public function __construct(
        public readonly string $eligibility,
        public readonly ?string $invoiceNumber = null,
        public readonly ?array $reprint = null,
        public readonly ?string $message = null,
        public readonly ?string $source = null,
        public readonly ?string $orderId = null,
        public readonly ?int $ordersId = null,
    ) {}

    public function canReprint(): bool
    {
        return $this->eligibility === 'historical_invoice'
            && is_string($this->invoiceNumber)
            && $this->invoiceNumber !== ''
            && is_array($this->reprint);
    }
}
