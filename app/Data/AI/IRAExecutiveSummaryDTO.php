<?php

namespace App\Data\AI;

use App\Data\SerialInsight;

readonly class IRAExecutiveSummaryDTO
{
    /**
     * @param  list<string>  $executiveSummary
     */
    public function __construct(
        public array $executiveSummary,
        public string $opinion,
        public string $recommendation,
        public ?SerialInsight $serialInsight = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'executive_summary' => $this->executiveSummary,
            'opinion' => $this->opinion,
            'recommendation' => $this->recommendation,
            'serial_insight' => $this->serialInsight?->toArray(),
        ];
    }
}
