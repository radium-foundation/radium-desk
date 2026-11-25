<?php

namespace App\Listeners\RadiumBox;

use App\Events\Finance\OrderPaid;
use App\Infrastructure\Queue\QueueRouting;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxPaymentConfirmationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConfirmRadiumBoxPaymentOnOrderPaid implements ShouldQueue, ShouldQueueAfterCommit
{
    use InteractsWithQueue;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1800];

    public function __construct(
        private readonly RadiumBoxPaymentConfirmationService $confirmation,
    ) {}

    public function viaQueue(OrderPaid $event): string
    {
        return QueueRouting::critical();
    }

    public function handle(OrderPaid $event): void
    {
        $order = Order::query()->find($event->order->id);

        if ($order === null || ! $this->confirmation->requiresBoxPaymentConfirmation($order)) {
            return;
        }

        try {
            $this->confirmation->confirmForOrder($order);
        } catch (Throwable $exception) {
            Log::warning('[RadiumBox payment confirm] OrderPaid listener failed.', [
                'order_id' => $order->order_id,
                'order_db_id' => $order->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
