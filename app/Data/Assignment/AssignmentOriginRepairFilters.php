<?php

namespace App\Data\Assignment;

readonly class AssignmentOriginRepairFilters
{
    public function __construct(
        public ?string $orderId = null,
        public ?string $serviceCase = null,
        public ?int $incidentId = null,
    ) {}

    public function hasFilter(): bool
    {
        return $this->orderId !== null
            || $this->serviceCase !== null
            || $this->incidentId !== null;
    }
}
