<?php

namespace App\Services\RadiumBoxRead\Data;

readonly class RadiumBoxReadCommercialOrder
{
    public function __construct(
        public int $id,
        public ?string $ordercode,
        public ?string $ordertype,
        public ?string $rdserviceOrderId,
        public ?string $invoicecode,
        public ?string $userid,
        public ?string $gstNo,
        public ?string $paymentStatus,
        public ?string $status,
        public ?string $orderdate,
        public ?string $branch,
        public ?string $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ordercode' => $this->ordercode,
            'ordertype' => $this->ordertype,
            'rdservice_order_id' => $this->rdserviceOrderId,
            'invoicecode' => $this->invoicecode,
            'userid' => $this->userid,
            'gst_no' => $this->gstNo,
            'payment_status' => $this->paymentStatus,
            'status' => $this->status,
            'orderdate' => $this->orderdate,
            'branch' => $this->branch,
            'created_at' => $this->createdAt,
        ];
    }
}
