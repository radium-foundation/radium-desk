<?php

namespace App\Services\RadiumBox;

use App\Exceptions\RadiumBox\RadiumBoxPaymentConfirmationRetryableException;
use App\Models\Order;
use App\Models\OutboxEvent;
use RuntimeException;

final class RadiumBoxPaymentConfirmationOutboxProcessor
{
    public function __construct(
        private readonly RadiumBoxPaymentConfirmationService $confirmation,
    ) {}

    public function process(OutboxEvent $event): void
    {
        $payload = $event->payload ?? [];
        $orderDbId = (int) ($payload['order_db_id'] ?? $event->aggregate_id);

        if ($orderDbId <= 0) {
            throw new RuntimeException('RadiumBox payment confirm outbox event is missing order_db_id.');
        }

        $order = Order::query()->find($orderDbId);

        if ($order === null) {
            throw new RuntimeException('Desk order not found for payment confirm outbox: '.$orderDbId);
        }

        if (! $this->confirmation->requiresBoxPaymentConfirmation($order)) {
            return;
        }

        $result = $this->confirmation->confirmForOrder($order);

        if ($result->ok) {
            return;
        }

        if ($result->retriable) {
            throw new RadiumBoxPaymentConfirmationRetryableException(
                $result->errorMessage ?? 'RadiumBox payment confirmation is retriable.',
            );
        }
    }
}