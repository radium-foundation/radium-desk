<?php

namespace App\Data\AI;

use App\Enums\AI\AIRiskLevel;

readonly class AIRiskIndicatorDTO
{
    public function __construct(
        public string $label,
        public AIRiskLevel $level,
    ) {}
}
