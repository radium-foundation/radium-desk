<?php

namespace App\Listeners\Finance;

use App\Events\Finance\RefundCompleted;
use App\Services\Finance\RefundJournalService;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostRefundCompletedJournal
{
    public function __construct(
        private readonly RefundJournalService $refundJournals,
    ) {}

    public function handle(RefundCompleted $event): void
    {
        try {
            $this->refundJournals->postForRefund($event->refund, $event->actor);
        } catch (Throwable $exception) {
            Log::error('[Finance] Refund completed journal listener failed.', [
                'refund_id' => $event->refund->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
