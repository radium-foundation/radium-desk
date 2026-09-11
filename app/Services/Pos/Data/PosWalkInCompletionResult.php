<?php

namespace App\Services\Pos\Data;

use App\Models\InventorySale;
use App\Models\StatutoryInvoice;

final class PosWalkInCompletionResult
{
    /**
     * @param  list<string>  $invoiceErrors
     */
    public function __construct(
        public readonly InventorySale $sale,
        public readonly ?StatutoryInvoice $statutoryInvoice,
        public readonly array $invoiceErrors = [],
    ) {}

    public function invoiceIssued(): bool
    {
        return $this->statutoryInvoice !== null;
    }
}
