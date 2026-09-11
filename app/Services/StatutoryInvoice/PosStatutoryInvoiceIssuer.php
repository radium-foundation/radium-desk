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

    public function issueAfterSaleCommit(InventorySale $sale, ?User $actor = null): ?string
    {
        try {
            $this->invoices->issueFromPosSale($sale, $actor);

            return null;
        } catch (Throwable $exception) {
            Log::warning('pos_statutory_invoice.sale_issue_failed', [
                'sale_id' => $sale->id,
                'sale_no' => $sale->sale_no,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return 'Sale completed, but the GST invoice could not be issued automatically. Open this sale to review or retry from Finance Hub.';
        }
    }
}
