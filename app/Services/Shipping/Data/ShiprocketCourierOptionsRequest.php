<?php

namespace App\Services\Shipping\Data;

final class ShiprocketCourierOptionsRequest
{
    /**
     * Query for GET /courier/serviceability/.
     * Historical Admin write path: pickup_postcode, delivery_postcode, weight, cod,
     * and order_id after a provider order exists.
     */
    public function __construct(
        public readonly string $pickupPostcode,
        public readonly string $deliveryPostcode,
        public readonly float $weight,
        public readonly int $cod = 0,
        public readonly ?string $providerOrderId = null,
    ) {}

    /**
     * @return array<string, int|float|string>
     */
    public function toQuery(): array
    {
        $query = [
            'pickup_postcode' => $this->pickupPostcode,
            'delivery_postcode' => $this->deliveryPostcode,
            'weight' => $this->weight,
            'cod' => $this->cod,
        ];

        $orderId = trim((string) $this->providerOrderId);
        if ($orderId !== '') {
            $query['order_id'] = $orderId;
        }

        return $query;
    }
}
