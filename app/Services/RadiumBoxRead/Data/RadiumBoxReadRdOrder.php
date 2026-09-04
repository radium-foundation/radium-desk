<?php

namespace App\Services\RadiumBoxRead\Data;

readonly class RadiumBoxReadRdOrder
{
    public function __construct(
        public int $id,
        public ?string $rdorderid,
        public ?int $userid,
        public ?string $gstNo,
        public ?string $productName,
        public ?string $serialNo,
        public ?string $status,
        public ?string $website,
        public ?string $amcServiceName,
        public ?string $rdServiceName,
        public ?string $createdAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'rdorderid' => $this->rdorderid,
            'userid' => $this->userid,
            'gst_no' => $this->gstNo,
            'product_name' => $this->productName,
            'serial_no' => $this->serialNo,
            'status' => $this->status,
            'website' => $this->website,
            'amc_service_name' => $this->amcServiceName,
            'rd_service_name' => $this->rdServiceName,
            'created_at' => $this->createdAt,
        ];
    }
}
