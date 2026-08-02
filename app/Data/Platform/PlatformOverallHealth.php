<?php

namespace App\Data\Platform;

use App\Enums\PlatformOverallHealthStatus;
use Illuminate\Support\Carbon;

readonly class PlatformOverallHealth
{
    /**
     * @param  list<PlatformHealthContribution>  $contributions
     */
    public function __construct(
        public PlatformOverallHealthStatus $status,
        public string $statusLabel,
        public ?float $scorePercent,
        public bool $available,
        public bool $stale,
        public ?Carbon $updatedAt,
        public array $contributions = [],
        public string $summary = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'status_label' => $this->statusLabel,
            'score_percent' => $this->scorePercent,
            'available' => $this->available,
            'stale' => $this->stale,
            'updated_at' => $this->updatedAt?->toIso8601String(),
            'summary' => $this->summary,
            'contributions' => array_map(
                static fn (PlatformHealthContribution $c): array => $c->toArray(),
                $this->contributions,
            ),
        ];
    }
}
