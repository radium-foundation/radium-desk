<?php

namespace App\Services\RadiumBoxRead\Data;

readonly class RadiumBoxReadLine
{
    public function __construct(
        public int $id,
        public ?string $orderid,
        public ?string $productName,
        public ?string $productid,
        public ?string $invoicecode,
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
            'product_name' => $this->productName,
            'productid' => $this->productid,
            'invoicecode' => $this->invoicecode,
            'created_at' => $this->createdAt,
        ];
    }
}
