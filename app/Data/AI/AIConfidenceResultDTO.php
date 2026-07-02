<?php

namespace App\Data\AI;

use App\Enums\AI\AIConfidenceLevel;

readonly class AIConfidenceResultDTO
{
    /**
     * @param  list<string>  $factors
     */
    public function __construct(
        public AIConfidenceLevel $level,
        public int $score,
        public array $factors,
    ) {}

    public function normalizedScore(): float
    {
        return round($this->score / 100, 2);
    }
}
