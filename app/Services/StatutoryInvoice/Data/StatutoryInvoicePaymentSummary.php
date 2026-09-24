<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Enums\StatutoryInvoicePaymentStatus;

final class StatutoryInvoicePaymentSummary
{
    /**
     * @param  list<array<string, mixed>>  $payments
     */
    public function __construct(
        public readonly bool $allocationBacked,
        public readonly StatutoryInvoicePaymentStatus $status,
        public readonly float $invoiceValue,
        public readonly float $amountReceived,
        public readonly float $amountOutstanding,
        public readonly ?string $posTenderMethod = null,
        public readonly ?string $posTenderReference = null,
        public readonly ?string $latestPaymentMethod = null,
        public readonly ?string $latestPaymentDate = null,
        public readonly ?string $latestBankName = null,
        public readonly ?string $latestBankBranch = null,
        public readonly ?string $latestReference = null,
        public readonly ?int $inventorySaleId = null,
        public readonly ?string $inventorySaleReference = null,
        public readonly array $payments = [],
    ) {}

    public function wasPaid(): bool
    {
        return $this->amountReceived > 0;
    }

    public function fullyPaid(): bool
    {
        return $this->status === StatutoryInvoicePaymentStatus::Paid;
    }
}
