<?php

namespace App\Services\RadiumBoxRead\Data;

readonly class RadiumBoxReadHistoryEvent
{
    public function __construct(
        public int $id,
        public int $orderid,
        public ?string $updatedBy,
        public ?string $status,
        public ?string $description,
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
            'updated_by' => $this->updatedBy,
            'status' => $this->status,
            'description' => $this->description,
            'created_at' => $this->createdAt,
        ];
    }
}
