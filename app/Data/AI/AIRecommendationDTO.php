<?php

namespace App\Data\AI;

readonly class AIRecommendationDTO
{
    public function __construct(
        public string $title,
        public ?string $description = null,
        public float $confidence = 0.0,
        public ?string $rationale = null,
    ) {}
}
