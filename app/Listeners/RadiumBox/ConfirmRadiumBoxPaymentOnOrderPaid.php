<?php

namespace App\Listeners\RadiumBox;

use App\Events\Finance\OrderPaid;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\RadiumBox\RadiumBoxPaymentConfirmationOutboxWriter;
use App\Services\RadiumBox\RadiumBoxPaymentConfirmationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConfirmRadiumBoxPaymentOnOrderPaid
{
    public function __construct(
        private readonly RadiumBoxPaymentConfirmationService $confirmation,
        private readonly RadiumBoxPaymentConfirmationOutboxWriter $outboxWriter,
        private readonly OutboxProcessorService $outboxProcessor,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $order = $event->order->fresh();

        if ($order === null || ! $this->confirmation->requiresBoxPaymentConfirmation($order)) {
            return;
        }

        if (config('radiumbox.payment_confirm.outbox_enabled', true)) {
            try {
                $outboxEvent = $this->outboxWriter->enqueueIfRequired($order);

                if (
                    $outboxEvent !== null
                    && config('radiumbox.payment_confirm.drain_on_enqueue', true)
                ) {
                    $this->outboxProcessor->processAggregate(
                        RadiumBoxPaymentConfirmationOutboxWriter::AGGREGATE_TYPE,
                        $order->id,
                    );
                }
            } catch (Throwable $exception) {
                Log::warning('[RadiumBox payment confirm] Outbox enqueue/drain failed.', [
                    'order_id' => $order->order_id,
                    'order_db_id' => $order->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }

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
