<?php

namespace App\Data\Bonvoice;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;

readonly class BonvoiceClickToCallContext
{
    public function __construct(
        public Order $order,
        public ?Incident $incident,
        public string $customerPhone,
        public string $customerDialable,
    ) {}

    public function orderId(): int
    {
        return (int) $this->order->id;
    }

    public function incidentId(): ?int
    {
        return $this->incident?->id;
    }

    /**
     * @return array<string, int|string>
     */
    public function callbackParams(User $agent, string $eventId): array
    {
        return array_filter([
            'event_id' => $eventId,
            'incident_id' => $this->incidentId(),
            'order_id' => $this->orderId(),
            'user_id' => $agent->id,
            'source' => 'radium_desk',
        ], fn (mixed $value): bool => $value !== null);
    }
}
