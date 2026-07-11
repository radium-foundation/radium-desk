<?php

namespace App\Data\AI;

use App\Enums\AI\AIConfidenceLevel;

readonly class CustomerJourneyConfidenceDTO
{
    /**
     * @param  list<string>  $positiveSignals
     * @param  list<string>  $negativeSignals
     */
    public function __construct(
        public int $score,
        public AIConfidenceLevel $level,
        public array $positiveSignals,
        public array $negativeSignals,
    ) {}

    public function summaryLine(): string
    {
        return sprintf(
            'Journey confidence: %s (%d)',
            $this->level->label(),
            $this->score,
        );
    }
}
