<?php

namespace App\Listeners\Finance;

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
        try {
            $this->posSaleJournals->postForSale($event->sale);
        } catch (Throwable $exception) {
            Log::error('[Finance] POS sale journal listener failed.', [
                'sale_id' => $event->sale->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
