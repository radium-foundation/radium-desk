<?php

namespace App\Data\AI;

use App\Enums\AI\CustomerJourneyConclusionType;

readonly class CustomerJourneyConclusionDTO
{
    public function __construct(
        public CustomerJourneyConclusionType $type,
        public string $headline,
        public string $detail,
        public string $recommendation,
    ) {}
}
