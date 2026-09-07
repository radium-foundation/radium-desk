<?php

namespace App\Listeners\HardwareFulfilment;

use App\Events\Finance\OrderPaid;
use App\Services\HardwareFulfilment\HardwareFulfilmentPaymentCorrelationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class CorrelateHardwareCashfreePayment
{
    public function __construct(
        private readonly HardwareFulfilmentPaymentCorrelationService $correlation,
    ) {}

    public function handle(OrderPaid $event): void
    {
        if (! config('hardware_fulfilment.correlate_cashfree')) {
            return;
        }

        try {
            $this->correlation->recordFromDeskOrder($event->order);
        } catch (Throwable $exception) {
            Log::warning('[Hardware fulfilment] Cashfree payment correlation skipped.', [
                'desk_order_id' => $event->order->id,
                'order_id' => $event->order->order_id,
                'exception' => $exception::class,
            ]);
        }
    }
}
