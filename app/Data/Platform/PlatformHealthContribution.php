<?php

namespace App\Data\Platform;

use App\Enums\PlatformOverallHealthStatus;
use Illuminate\Support\Carbon;

readonly class PlatformHealthContribution
{
    public function __construct(
        public string $source,
        public string $label,
        public PlatformOverallHealthStatus $status,
        public bool $available,
        public ?Carbon $updatedAt = null,
        public bool $stale = false,
        public int $weight = 1,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'label' => $this->label,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'available' => $this->available,
            'updated_at' => $this->updatedAt?->toIso8601String(),
            'stale' => $this->stale,
            'weight' => $this->weight,
        ];
    }
}
