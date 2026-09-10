<?php

namespace App\Services\StatutoryInvoice;

use App\Models\InventorySale;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After-commit POS statutory mint. Does not use auto_issue_on_pos_complete
 * (that flag still aborts checkout). WhiteBooks GENERATE is not called here.
 */
final class PosStatutoryInvoiceIssuer
{
    public function __construct(
        private readonly StatutoryInvoiceService $invoices,
    ) {}

    public function issueAfterSaleCommit(InventorySale $sale, ?User $actor = null): void
    {
        try {
            $this->invoices->issueFromPosSale($sale, $actor);
        } catch (Throwable $exception) {
            Log::warning('pos_statutory_invoice.sale_issue_failed', [
                'sale_id' => $sale->id,
                'sale_no' => $sale->sale_no,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
