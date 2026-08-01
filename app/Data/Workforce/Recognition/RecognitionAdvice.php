<?php

namespace App\Data\Workforce\Recognition;

use App\Enums\RecognitionRecommendation;

readonly class RecognitionAdvice
{
    /**
     * @param  list<string>  $why
     */
    public function __construct(
        public float $score,
        public RecognitionRecommendation $recommendation,
        public string $rationale,
        public array $why,
        public string $departmentPack,
    ) {}
}
