<?php

namespace App\Listeners\Finance;

use App\Enums\InventoryFinanceHandoffStatus;
use App\Events\Inventory\InventorySaleCompleted;
use App\Services\Finance\PosSaleJournalService;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostPosSaleJournal
{
    public function __construct(
        private readonly PosSaleJournalService $posSaleJournals,
    ) {}

    public function handle(InventorySaleCompleted $event): void
    {
        $sale = $event->sale->fresh();
        if ($sale === null) {
            return;
        }

        if (in_array($sale->finance_handoff_status, [
            InventoryFinanceHandoffStatus::Posted,
            InventoryFinanceHandoffStatus::Skipped,
        ], true)) {
            return;
        }

        try {
            $this->posSaleJournals->postForSale($sale, failClosed: false);
        } catch (Throwable $exception) {
            Log::error('[Finance] POS sale journal listener failed.', [
                'sale_id' => $sale->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
