<?php

namespace App\Data\SerialValidation;

use App\Enums\SerialInsightConfidence;

readonly class SerialPatternAssessment
{
    public function __construct(
        public ?string $canonicalProduct,
        public bool $matchesVerifiedValid,
        public bool $matchesVerifiedWrong,
        public ?string $wrongPatternReason,
        public ?SerialInsightConfidence $wrongPatternConfidence,
        public ?string $crossModelHint,
        public bool $hasOvsIConfusion,
        public string $failureGuidance,
        public string $validFormatDescription,
    ) {}

    public function hasHighConfidenceWrongSignal(): bool
    {
        return $this->wrongPatternConfidence === SerialInsightConfidence::High
            || $this->matchesVerifiedWrong
            || $this->crossModelHint !== null;
    }
}
