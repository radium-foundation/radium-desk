<?php

namespace App\Listeners\RadiumBox;

use App\Events\Finance\OrderPaid;
use App\Services\RadiumBox\RadiumBoxPaymentConfirmationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConfirmRadiumBoxPaymentOnOrderPaid
{
    public function __construct(
        private readonly RadiumBoxPaymentConfirmationService $confirmation,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order->fresh();

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
