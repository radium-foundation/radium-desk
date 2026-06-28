<?php

namespace App\Infrastructure\DataQuality;

/**
 * Orders sharing the same serial number.
 */
readonly class DuplicateSerialGroup
{
    /**
     * @param  list<int>  $orderIds
     */
    public function __construct(
        public string $serialNumber,
        public array $orderIds,
    ) {}

    public function count(): int
    {
        return count($this->orderIds);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'serial_number' => $this->serialNumber,
            'order_ids' => $this->orderIds,
            'count' => $this->count(),
        ];
    }
}
