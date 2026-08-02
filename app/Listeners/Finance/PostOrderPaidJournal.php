<?php

namespace App\Listeners\Finance;

use App\Events\Finance\OrderPaid;
use App\Services\Finance\OrderPaymentJournalService;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostOrderPaidJournal
{
    public function __construct(
        private readonly OrderPaymentJournalService $orderPaymentJournals,
    ) {}

    public function handle(OrderPaid $event): void
    {
        try {
            $this->orderPaymentJournals->postForOrder($event->order);
        } catch (Throwable $exception) {
            Log::error('[Finance] Order paid journal listener failed.', [
                'order_id' => $event->order->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
