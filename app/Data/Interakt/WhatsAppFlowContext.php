<?php

namespace App\Data\Interakt;

use Illuminate\Support\Carbon;

readonly class WhatsAppFlowContext
{
    public function __construct(
        public int $incident_id,
        public string $incident_reference,
        public string $order_id,
        public ?string $customer_name,
        public ?string $customer_phone,
        public ?string $brand,
        public ?string $model,
        public ?string $serial_number,
        public string $booking_url,
        public Carbon $expires_at,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            incident_id: (int) $data['incident_id'],
            incident_reference: (string) $data['incident_reference'],
            order_id: (string) $data['order_id'],
            customer_name: isset($data['customer_name']) ? (string) $data['customer_name'] : null,
            customer_phone: isset($data['customer_phone']) ? (string) $data['customer_phone'] : null,
            brand: isset($data['brand']) ? (string) $data['brand'] : null,
            model: isset($data['model']) ? (string) $data['model'] : null,
            serial_number: isset($data['serial_number']) ? (string) $data['serial_number'] : null,
            booking_url: (string) $data['booking_url'],
            expires_at: Carbon::parse($data['expires_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'incident_id' => $this->incident_id,
            'incident_reference' => $this->incident_reference,
            'order_id' => $this->order_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'brand' => $this->brand,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'booking_url' => $this->booking_url,
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
