<?php

namespace App\Services\RadiumBoxRead\Data;

readonly class RadiumBoxReadInvoice
{
    public function __construct(
        public int $id,
        public ?string $orderid,
        public ?string $invoiceNumber,
        public ?string $branch,
        public ?string $serviceType,
        public ?string $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'orderid' => $this->orderid,
            'invoice_number' => $this->invoiceNumber,
            'branch' => $this->branch,
            'service_type' => $this->serviceType,
            'created_at' => $this->createdAt,
        ];
    }
}
