<?php

namespace App\Listeners\Finance;

use App\Events\Finance\OrderPaid;
use App\Infrastructure\Queue\QueueRouting;
use App\Models\Order;
use App\Services\Finance\OrderPaymentJournalService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostOrderPaidJournal implements ShouldQueue, ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800];

    public function __construct(
        private readonly OrderPaymentJournalService $orderPaymentJournals,
    ) {}

    public function viaQueue(OrderPaid $event): string
    {
        return QueueRouting::critical();
    }

    public function handle(OrderPaid $event): void
    {
        $order = Order::query()->find($event->order->id);

        if ($order === null) {
            return;
        }

        try {
            $this->orderPaymentJournals->postForOrder($order);
        } catch (Throwable $exception) {
            Log::error('[Finance] Order paid journal listener failed.', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
