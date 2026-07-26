<?php

namespace App\Data;

use Illuminate\Support\Carbon;

readonly class TeamActivityEntry
{
    public function __construct(
        public Carbon $at,
        public string $time,
        public string $label,
        public ?string $reference = null,
        public ?int $incidentId = null,
        public ?string $serviceCaseReference = null,
        public ?string $orderReference = null,
        public ?string $description = null,
    ) {}

    /**
     * @return array{
     *     at: string,
     *     time: string,
     *     label: string,
     *     reference: string|null,
     *     incident_id: int|null,
     *     service_case_reference: string|null,
     *     order_reference: string|null,
     *     description: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'at' => $this->at->toIso8601String(),
            'time' => $this->time,
            'label' => $this->label,
            'reference' => $this->reference,
            'incident_id' => $this->incidentId,
            'service_case_reference' => $this->serviceCaseReference,
            'order_reference' => $this->orderReference,
            'description' => $this->description,
        ];
    }
}
